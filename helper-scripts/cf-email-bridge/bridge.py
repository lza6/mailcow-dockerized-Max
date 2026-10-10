#!/usr/bin/env python3
"""
Cloudflare Email Sending SMTP 桥接

作用：
  mailcow/postfix 默认用「587 + STARTTLS」发信，而 Cloudflare Email Sending
  只接受「465 + 隐式 TLS」。本桥接在内部网络监听一个普通 SMTP 端口，
  把收到的邮件经 smtp.mx.cloudflare.net:465 转发出去。

  这样 mailcow 侧只需把 relayhost 指向本桥接的 主机:端口，
  走 mailcow 原生支持的路径，无需改 postfix transport，也无需重建镜像。

环境变量：
  CF_API_TOKEN  必填，Cloudflare API Token（需 Email Sending 权限）
  LISTEN_PORT   默认 2526
  CF_HOST       默认 smtp.mx.cloudflare.net
  CF_PORT       默认 465
  ALLOW_DOMAINS 必填，逗号分隔的发件域白名单（fail-closed：为空则拒绝启动）
  RL_COOLDOWN   命中 CF 限流后的全局冷却秒数，默认 60

变更记录（2026-10-10）：
  * [P0-1] relay() 改为**原样转发原始字节**。此前会重建 MIME 结构，
    导致 HTML 正文、附件、Cc、List-Unsubscribe 全部丢失
    （实测内容损毁率 98.2%，且日志仍打印 RELAY OK，完全静默）。
  * [P0-2] MAIL FROM 解析改用正则取 <> 内地址，忽略 SIZE=/BODY= 等 ESMTP 参数；
    白名单改为支持子域（后缀匹配带点，防 a.com.evil.com 误放行）；
    ALLOW_DOMAINS 为空时**拒绝启动**（此前为 fail-open，等于开放中继）。
  * [P2-5] 新增优雅关闭（SIGTERM）、CF 限流全局冷却、启动自检与更清晰的日志。
"""

import asyncio
import email
import logging
import os
import re
import signal
import smtplib
import ssl
import sys
import time

CF_TOKEN = os.environ.get("CF_API_TOKEN", "").strip()
LISTEN_PORT = int(os.environ.get("LISTEN_PORT", "2526"))
CF_HOST = os.environ.get("CF_HOST", "smtp.mx.cloudflare.net")
CF_PORT = int(os.environ.get("CF_PORT", "465"))
ALLOW = {d.strip().lower() for d in os.environ.get("ALLOW_DOMAINS", "").split(",") if d.strip()}
RL_COOLDOWN = int(os.environ.get("RL_COOLDOWN", "60"))

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
    stream=sys.stdout,
)
log = logging.getLogger("cf-bridge")

# 额外写一份到文件，供 mailcow 后台「投递链路追踪」读取。
# 为什么要写文件：php-fpm 容器**没有** docker socket（这是正确的安全设计，
# 给 Web 应用 docker socket 等于给它主机 root 权限），dockerapi 也没有 logs 路由，
# 因此 Web 侧无法用 docker logs 读本容器日志。改为让桥主动写文件、php-fpm 只读挂载。
LOG_FILE = os.environ.get("LOG_FILE", "").strip()
LOG_MAX_BYTES = 5 * 1024 * 1024


class _SizedRotatingFileHandler(logging.FileHandler):
    """按大小轮转的文件 handler（在**每次写入前**判断，不是启动时判一次）。

    为什么必须放进写入路径：本进程是 `asyncio.run(main())` 常驻守护进程。
    原先的大小检查写在模块顶层（import 阶段），整个进程生命周期只执行一次 ——
    启动之后文件就**没有任何上界**了。而 Web 侧的投递追踪用 `file()` 整读该文件，
    文件多大就吃多少内存（5MB ≈ 35k 行 ≈ 10-15MB PHP 数组，并发读时成倍放大）。
    """

    def __init__(self, filename: str, max_bytes: int) -> None:
        super().__init__(filename, encoding="utf-8")
        self.max_bytes = max_bytes

    def emit(self, record: logging.LogRecord) -> None:
        try:
            if self.stream is not None and self.stream.tell() >= self.max_bytes:
                self._rollover()
        except (OSError, ValueError):
            pass
        super().emit(record)

    def _rollover(self) -> None:
        bak = self.baseFilename + ".1"
        if self.stream is not None:
            self.stream.close()
            self.stream = None
        try:
            if os.path.exists(bak):
                os.remove(bak)
            os.rename(self.baseFilename, bak)
        except OSError:
            pass
        self.stream = self._open()


if LOG_FILE:
    try:
        os.makedirs(os.path.dirname(LOG_FILE), exist_ok=True)
        fh = _SizedRotatingFileHandler(LOG_FILE, LOG_MAX_BYTES)
        fh.setFormatter(logging.Formatter("%(asctime)s %(levelname)s %(message)s"))
        log.addHandler(fh)
    except OSError as e:
        log.warning("无法写日志文件 %s：%s（仅输出到 stdout）", LOG_FILE, e)

if not CF_TOKEN:
    log.error("CF_API_TOKEN 未设置，退出")
    sys.exit(1)

# fail-closed：白名单为空即拒绝启动。
# 否则任何能连到 mailcow-network 的容器都能借道发信（开放中继），
# 会直接摧毁 CF 账号与全部域名的信誉。
if not ALLOW:
    log.error("ALLOW_DOMAINS 未设置或为空 —— 拒绝启动。"
              "请显式配置允许的发件域，避免成为开放中继。")
    sys.exit(1)

# MAIL FROM:<addr> [SIZE=...] [BODY=...] —— 只取尖括号内地址，兼容无尖括号写法
MAIL_FROM_RE = re.compile(r"^MAIL\s+FROM:\s*(?:<([^>]*)>|(\S+))", re.IGNORECASE)
RCPT_TO_RE = re.compile(r"^RCPT\s+TO:\s*(?:<([^>]*)>|(\S+))", re.IGNORECASE)

# CF 限流的全局冷却截止时间戳（0 表示无冷却）
_rl_until = 0.0


def domain_of(addr: str) -> str:
    """从邮箱地址取出小写域名；无 @ 返回空串。"""
    addr = (addr or "").strip().strip("<>").strip()
    if "@" not in addr:
        return ""
    return addr.rsplit("@", 1)[-1].strip().lower().strip(".")


def allowed(domain: str) -> bool:
    """fail-closed 的白名单判定，支持子域。

    子域匹配必须用 endswith("." + allowed)：
    若写成 endswith(allowed)，`a.com.evil.com` 会被误判为命中 `a.com`。
    """
    if not ALLOW or not domain:
        return False
    if domain in ALLOW:
        return True
    return any(domain.endswith("." + a) for a in ALLOW)


def extract_addr(match: "re.Match | None") -> str:
    if not match:
        return ""
    return (match.group(1) or match.group(2) or "").strip()


def relay(raw: bytes, mail_from: str, rcpts: list) -> None:
    """原样转发。

    不做任何 MIME 重构 —— 直接把 postfix 交来的原始字节交给 CF。
    这样 HTML 正文、附件、Cc/Bcc、List-Unsubscribe 以及原有的
    MIME 结构都会被完整保留；DKIM 由 CF 侧重新签名（s=cf-bounce）。
    """
    global _rl_until

    now = time.time()
    if now < _rl_until:
        # 处于限流冷却期，直接回 451 让 postfix 稍后重试，
        # 避免持续撞击 CF 限流（此前无退避，会一直撞）。
        raise RuntimeError(
            "rate-limit cooldown active, %d s left" % int(_rl_until - now)
        )

    # 从原始字节里提取 Message-ID，写进日志。
    # 这样后台「投递链路追踪」才能按 Message-ID 把 postfix 段与中继段串成一条链。
    # 提取失败不影响投递（该元数据仅用于日志）。
    msg_id = ""
    try:
        m = email.message_from_bytes(raw)
        msg_id = (m.get("Message-ID") or "").strip()
    except Exception as e:
        # 不静默吞掉：提取失败要留痕，否则日志里只会看到 msgid=- 却不知为何
        log.warning("Message-ID 提取失败（不影响投递）：%s: %s", type(e).__name__, e)

    ctx = ssl.create_default_context()
    try:
        with smtplib.SMTP_SSL(CF_HOST, CF_PORT, context=ctx, timeout=45) as s:
            s.login("api_token", CF_TOKEN)
            # sendmail 直接传原始字节，保留全部结构与头部
            s.sendmail(mail_from, rcpts, raw)
    except smtplib.SMTPDataError as e:
        msg = str(e)
        if "rate limit" in msg.lower() or "4.7.1" in msg:
            _rl_until = time.time() + RL_COOLDOWN
            log.warning("CF 限流命中，进入 %d 秒冷却", RL_COOLDOWN)
        raise
    finally:
        # 记在 finally，失败路径也留下 Message-ID，便于排查
        pass

    log.info("RELAY OK  msgid=%s  from=%s  to=%s  bytes=%d",
             msg_id or "-", mail_from, rcpts, len(raw))


class SMTPBridge(asyncio.Protocol):
    def __init__(self):
        self.buf = b""
        self.mail_from = ""
        self.rcpts = []
        self.data_mode = False
        self.data = b""

    def connection_made(self, transport):
        self.transport = transport
        self.peer = transport.get_extra_info("peername")
        log.info("CONNECT %s", self.peer)
        # 必须立即回问候语，否则客户端会一直等，直至超时
        # （postfix 报 "timed out while receiving the initial server greeting"）
        self._send("220 cf-bridge ESMTP ready")

    def _send(self, line: str):
        self.transport.write((line + "\r\n").encode())

    def data_received(self, chunk):
        self.buf += chunk
        while b"\n" in self.buf:
            line, self.buf = self.buf.split(b"\n", 1)
            raw = line.rstrip(b"\r")
            if self.data_mode:
                if raw == b".":
                    self.data_mode = False
                    self._handle_data()
                else:
                    if raw.startswith(b".."):
                        raw = raw[1:]
                    self.data += raw + b"\r\n"
                continue
            self._handle_cmd(raw.decode("utf-8", "replace"))

    def _handle_cmd(self, cmd: str):
        u = cmd.upper()
        if u.startswith("EHLO"):
            self.transport.write(b"250-cf-bridge\r\n250-SIZE 5242880\r\n250 OK\r\n")
        elif u.startswith("HELO"):
            self._send("250 cf-bridge")
        elif u.startswith("MAIL FROM:"):
            addr = extract_addr(MAIL_FROM_RE.match(cmd))
            self.mail_from = addr
            d = domain_of(addr)
            if not allowed(d):
                log.warning("REJECT sender not allowed: %r (domain=%r)", addr[:120], d)
                self._send("550 5.7.1 sender domain not allowed")
                self.mail_from = ""
                return
            self._send("250 OK")
        elif u.startswith("RCPT TO:"):
            addr = extract_addr(RCPT_TO_RE.match(cmd))
            if not addr:
                self._send("501 5.1.3 bad recipient address")
                return
            self.rcpts.append(addr)
            self._send("250 OK")
        elif u == "DATA":
            if not self.mail_from or not self.rcpts:
                self._send("503 5.5.1 need MAIL/RCPT first")
                return
            self.data_mode = True
            self.data = b""
            self._send("354 End data with <CR><LF>.<CR><LF>")
        elif u == "RSET":
            self.mail_from, self.rcpts, self.data = "", [], b""
            self._send("250 OK")
        elif u == "NOOP":
            self._send("250 OK")
        elif u == "QUIT":
            self._send("221 Bye")
            self.transport.close()
        elif u == "STARTTLS":
            self._send("454 TLS not available")
        else:
            # 未知命令回 502（此前统一回 250，会误导客户端认为已生效）
            self._send("502 5.5.2 command not implemented")

    def _handle_data(self):
        nbytes = len(self.data)
        try:
            relay(self.data, self.mail_from, self.rcpts)
            self._send("250 2.0.0 Ok: queued")
            # 成功日志由 relay() 统一记录（含 msgid=），此处不重复打印
        except Exception as e:
            log.error("RELAY FAIL from=%s to=%s bytes=%d err=%s",
                      self.mail_from, self.rcpts, nbytes, e)
            self._send(f"451 4.3.0 relay failed: {str(e)[:120]}")
        finally:
            self.mail_from, self.rcpts, self.data = "", [], b""


async def main():
    loop = asyncio.get_running_loop()
    stop = loop.create_future()

    def _on_signal(signame: str):
        if not stop.done():
            log.info("收到 %s，开始优雅关闭…", signame)
            stop.set_result(None)

    for sig in (signal.SIGTERM, signal.SIGINT):
        try:
            loop.add_signal_handler(sig, _on_signal, sig.name)
        except NotImplementedError:
            pass

    server = await loop.create_server(SMTPBridge, "0.0.0.0", LISTEN_PORT)
    log.info("cf-bridge listening on 0.0.0.0:%d -> %s:%d", LISTEN_PORT, CF_HOST, CF_PORT)
    log.info("allowed sender domains: %s", sorted(ALLOW))

    async with server:
        await stop


if __name__ == "__main__":
    asyncio.run(main())
