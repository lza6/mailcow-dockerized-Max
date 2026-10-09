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
  ALLOW_DOMAINS 可选，逗号分隔的发件域白名单；留空表示不限制
"""

import asyncio
import email
import logging
import os
import smtplib
import ssl
import sys
from email.message import EmailMessage

CF_TOKEN = os.environ.get("CF_API_TOKEN", "").strip()
LISTEN_PORT = int(os.environ.get("LISTEN_PORT", "2526"))
CF_HOST = os.environ.get("CF_HOST", "smtp.mx.cloudflare.net")
CF_PORT = int(os.environ.get("CF_PORT", "465"))
ALLOW = {d.strip().lower() for d in os.environ.get("ALLOW_DOMAINS", "").split(",") if d.strip()}

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
    stream=sys.stdout,
)
log = logging.getLogger("cf-bridge")

if not CF_TOKEN:
    log.error("CF_API_TOKEN 未设置，退出")
    sys.exit(1)


def domain_of(addr: str) -> str:
    return addr.rsplit("@", 1)[-1].strip().lower().strip("<>") if "@" in addr else ""


def relay(raw: bytes, mail_from: str, rcpts: list) -> None:
    """把原始邮件转发给 Cloudflare"""
    msg = email.message_from_bytes(raw)

    out = EmailMessage()
    for h in ("From", "To", "Cc", "Bcc", "Reply-To", "Subject", "Date",
              "Message-ID", "MIME-Version", "List-Unsubscribe",
              "List-Unsubscribe-Post", "Content-Type", "Content-Transfer-Encoding"):
        vals = msg.get_all(h)
        if not vals:
            continue
        for v in vals:
            if h in out:
                out[h] = f"{out[h]}, {v}"
            else:
                out[h] = v

    body = None
    if msg.is_multipart():
        for part in msg.walk():
            if part.get_content_maintype() == "multipart":
                continue
            payload = part.get_payload(decode=True)
            if payload is None:
                continue
            ctype = part.get_content_type()
            if ctype == "text/plain" and body is None:
                body = payload.decode(part.get_content_charset() or "utf-8", "replace")
            elif ctype == "text/html":
                pass
    else:
        payload = msg.get_payload(decode=True)
        if payload:
            body = payload.decode(msg.get_content_charset() or "utf-8", "replace")

    if body is None:
        body = ""

    # 清掉原有头，用纯文本重发（保持简单、避免签名失效问题）
    for h in list(out.keys()):
        del out[h]
    out["From"] = mail_from
    out["To"] = ", ".join(rcpts)
    out["Subject"] = msg.get("Subject", "(no subject)")
    if msg.get("Date"):
        out["Date"] = msg["Date"]
    out["Message-ID"] = msg.get("Message-ID") or email.utils.make_msgid(
        domain=domain_of(mail_from) or "localhost"
    )
    out.set_content(body)

    ctx = ssl.create_default_context()
    with smtplib.SMTP_SSL(CF_HOST, CF_PORT, context=ctx, timeout=45) as s:
        s.login("api_token", CF_TOKEN)
        s.send_message(out)
    log.info("RELAY OK  from=%s  to=%s  subject=%s",
             mail_from, rcpts, msg.get("Subject", "")[:60])


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
            self.mail_from = cmd.split(":", 1)[1].strip().strip("<>")
            d = domain_of(self.mail_from)
            if ALLOW and d not in ALLOW:
                log.warning("REJECT domain not allowed: %s", d)
                self._send("550 5.7.1 sender domain not allowed")
                self.mail_from = ""
                return
            self._send("250 OK")
        elif u.startswith("RCPT TO:"):
            self.rcpts.append(cmd.split(":", 1)[1].strip().strip("<>"))
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
            self._send("250 OK")

    def _handle_data(self):
        try:
            relay(self.data, self.mail_from, self.rcpts)
            self._send("250 2.0.0 Ok: queued")
        except Exception as e:
            log.error("RELAY FAIL from=%s to=%s err=%s", self.mail_from, self.rcpts, e)
            self._send(f"451 4.3.0 relay failed: {str(e)[:120]}")
        finally:
            self.mail_from, self.rcpts, self.data = "", [], b""


async def main():
    loop = asyncio.get_running_loop()
    server = await loop.create_server(SMTPBridge, "0.0.0.0", LISTEN_PORT)
    log.info("cf-bridge listening on 0.0.0.0:%d -> %s:%d", LISTEN_PORT, CF_HOST, CF_PORT)
    if ALLOW:
        log.info("allowed sender domains: %s", sorted(ALLOW))
    async with server:
        await server.serve_forever()


if __name__ == "__main__":
    asyncio.run(main())
