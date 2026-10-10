#!/usr/bin/env python3
"""
cf-bridge 核心逻辑单元测试

运行：
    python3 -m pytest test_bridge.py -v
或（无 pytest 时）：
    python3 test_bridge.py

覆盖 2026-10-10 修复的三个问题：
  P0-1 邮件内容完整性（原样转发，不丢 HTML/附件/Cc）
  P0-2 白名单解析与 fail-closed 语义
  P2-5 限流冷却
"""

import os
import sys
import types

# 让模块能在无 CF_TOKEN 的情况下被导入做纯函数测试
os.environ.setdefault("CF_API_TOKEN", "test-token")
os.environ.setdefault("ALLOW_DOMAINS", "urbansproutglobal.com,breezeshelf.com")

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import bridge  # noqa: E402


# ---------------- P0-2: MAIL FROM 解析 ----------------

def _parse_mail_from(cmd: str) -> str:
    return bridge.extract_addr(bridge.MAIL_FROM_RE.match(cmd))


def test_mail_from_plain():
    assert _parse_mail_from("MAIL FROM:<a@b.com>") == "a@b.com"


def test_mail_from_with_size_param():
    # 回归：此前会解析出 'b.com> size=1000'，导致合法邮件被静默拒绝
    assert _parse_mail_from("MAIL FROM:<a@b.com> SIZE=1000") == "a@b.com"


def test_mail_from_with_body_param():
    assert _parse_mail_from("MAIL FROM:<a@b.com> BODY=8BITMIME") == "a@b.com"


def test_mail_from_multiple_params():
    assert _parse_mail_from("MAIL FROM:<a@b.com> SIZE=1000 BODY=8BITMIME") == "a@b.com"


def test_mail_from_case_insensitive():
    assert _parse_mail_from("mail from:<a@b.com>") == "a@b.com"


def test_mail_from_without_brackets():
    assert _parse_mail_from("MAIL FROM: a@b.com") == "a@b.com"


def test_rcpt_to_parsing():
    assert bridge.extract_addr(bridge.RCPT_TO_RE.match("RCPT TO:<c@d.com>")) == "c@d.com"
    assert bridge.extract_addr(bridge.RCPT_TO_RE.match("RCPT TO:<c@d.com> NOTIFY=SUCCESS")) == "c@d.com"


# ---------------- P0-2: 白名单语义 ----------------

def test_domain_of_basic():
    assert bridge.domain_of("a@b.com") == "b.com"
    assert bridge.domain_of("A@B.COM") == "b.com"
    assert bridge.domain_of("<a@b.com>") == "b.com"
    # 回归：此前返回 'b.com> size=1000'
    assert bridge.domain_of("a@b.com> size=1000") == "b.com> size=1000"  # 原始输入本身就不合法，此处仅记录行为


def test_allowed_exact():
    assert bridge.allowed("urbansproutglobal.com") is True
    assert bridge.allowed("breezeshelf.com") is True


def test_allowed_subdomain():
    # CF 要求用 send. 子域发信，必须放行
    assert bridge.allowed("send.urbansproutglobal.com") is True
    assert bridge.allowed("cf-bounce.send.breezeshelf.com") is True


def test_allowed_rejects_suffix_confusion():
    # 关键安全用例：endswith(allowed) 的写法会误放行
    assert bridge.allowed("urbansproutglobal.com.evil.com") is False
    assert bridge.allowed("noturbansproutglobal.com") is False
    assert bridge.allowed("evil.com") is False


def test_allowed_empty_domain():
    assert bridge.allowed("") is False


def test_fail_closed_when_allow_empty():
    saved = bridge.ALLOW
    try:
        bridge.ALLOW = set()
        assert bridge.allowed("urbansproutglobal.com") is False
    finally:
        bridge.ALLOW = saved


# ---------------- P0-1: 内容完整性（字节级） ----------------

def _build_rich_message() -> bytes:
    """构造含 HTML + 附件 + Cc + List-Unsubscribe 的邮件。"""
    from email.message import EmailMessage
    m = EmailMessage()
    m["From"] = "daniel@urbansproutglobal.com"
    m["To"] = "rcpt@example.com"
    m["Cc"] = "cc@example.com"
    m["Subject"] = "rich message"
    m["List-Unsubscribe"] = "<mailto:unsub@urbansproutglobal.com>"
    m.set_content("plain part")
    m.add_alternative("<html><body><h1>HTML</h1></body></html>", subtype="html")
    m.add_attachment(b"%PDF-1.4 test attachment\n%%EOF\n",
                     maintype="application", subtype="pdf", filename="a.pdf")
    return m.as_bytes()


def test_relay_sends_raw_bytes_unchanged():
    """用假 SMTP 捕获真正发出的字节，断言与原始字节完全一致。

    这是 P0-1 的核心回归测试：修复前重建 MIME 会把 5 个部件压成 1 个纯文本。
    """
    raw = _build_rich_message()
    captured = {}

    class FakeSMTP:
        def __init__(self, *a, **kw):
            pass
        def __enter__(self):
            return self
        def __exit__(self, *a):
            return False
        def login(self, u, p):
            captured["login"] = (u, p)
        def sendmail(self, frm, rcpts, data):
            captured["from"] = frm
            captured["rcpts"] = rcpts
            captured["data"] = data

    saved = bridge.smtplib.SMTP_SSL
    bridge.smtplib.SMTP_SSL = FakeSMTP
    try:
        bridge.relay(raw, "daniel@urbansproutglobal.com", ["rcpt@example.com"])
    finally:
        bridge.smtplib.SMTP_SSL = saved

    assert captured["data"] == raw, "发出的字节必须与原始邮件完全一致"

    # 结构完整性
    import email
    msg = email.message_from_bytes(captured["data"])
    types_ = [p.get_content_type() for p in msg.walk() if p.get_content_maintype() != "multipart"]
    assert "text/html" in types_, "HTML 正文不能丢失"
    assert any(p.get_content_disposition() == "attachment" for p in msg.walk()), "附件不能丢失"
    assert msg["Cc"] == "cc@example.com", "Cc 不能丢失"
    assert msg["List-Unsubscribe"] == "<mailto:unsub@urbansproutglobal.com>", \
        "List-Unsubscribe 不能丢失"


# ---------------- P2-5: 限流冷却 ----------------

def test_rate_limit_sets_cooldown():
    saved_smtp = bridge.smtplib.SMTP_SSL
    saved_until = bridge._rl_until

    class RateLimitedSMTP:
        def __init__(self, *a, **kw):
            pass
        def __enter__(self):
            return self
        def __exit__(self, *a):
            return False
        def login(self, u, p):
            pass
        def sendmail(self, *a):
            raise bridge.smtplib.SMTPDataError(
                451, b"4.7.1 rate limit exceeded, try again later")

    bridge.smtplib.SMTP_SSL = RateLimitedSMTP
    bridge._rl_until = 0
    try:
        try:
            bridge.relay(b"raw", "a@urbansproutglobal.com", ["b@example.com"])
            assert False, "应抛出异常"
        except Exception:
            pass
        assert bridge._rl_until > 0, "命中限流后应设置冷却"

        # 冷却期内再次投递应被拒绝，而不是继续撞击 CF
        try:
            bridge.relay(b"raw", "a@urbansproutglobal.com", ["b@example.com"])
            assert False, "冷却期内应抛出异常"
        except Exception as e:
            assert "cooldown" in str(e).lower()
    finally:
        bridge.smtplib.SMTP_SSL = saved_smtp
        bridge._rl_until = saved_until


# ---------------- 协议层：问候语（防回归） ----------------

class _FakeTransport:
    def __init__(self):
        self.written = b""

    def write(self, data: bytes):
        self.written += data

    def get_extra_info(self, name):
        return ("127.0.0.1", 12345)

    def close(self):
        pass


def test_connection_sends_greeting():
    """连接建立后必须立即回 220 问候语。

    回归用例：2026-10-10 修复 P0-1/P0-2 时曾漏掉这一行，
    导致 postfix 报 "timed out while receiving the initial server greeting"
    （每封邮件延迟 300 秒后退回队列）。
    """
    proto = bridge.SMTPBridge()
    t = _FakeTransport()
    proto.connection_made(t)
    assert t.written.startswith(b"220 "), \
        f"必须回 220 问候语，实际发出: {t.written[:40]!r}"


def test_ehlo_advertises_size():
    proto = bridge.SMTPBridge()
    t = _FakeTransport()
    proto.connection_made(t)
    proto._handle_cmd("EHLO client.example.com")
    assert b"250" in t.written
    assert b"SIZE" in t.written


def test_mail_from_rejected_when_domain_not_allowed():
    proto = bridge.SMTPBridge()
    t = _FakeTransport()
    proto.connection_made(t)
    proto._handle_cmd("MAIL FROM:<x@evil.com>")
    assert b"550" in t.written, "非白名单域必须被拒"


def test_mail_from_accepted_for_allowed_subdomain():
    proto = bridge.SMTPBridge()
    t = _FakeTransport()
    proto.connection_made(t)
    proto._handle_cmd("MAIL FROM:<x@send.urbansproutglobal.com> SIZE=1000")
    # 不能被拒（550）；应回 250
    assert b"550" not in t.written, "白名单子域不应被拒"
    assert b"250" in t.written


def _run_all():
    fns = [(n, f) for n, f in sorted(globals().items())
           if n.startswith("test_") and isinstance(f, types.FunctionType)]
    failed = 0
    for name, fn in fns:
        try:
            fn()
            print(f"  PASS  {name}")
        except AssertionError as e:
            failed += 1
            print(f"  FAIL  {name}: {e}")
        except Exception as e:
            failed += 1
            print(f"  ERROR {name}: {type(e).__name__}: {e}")
    print(f"\n共 {len(fns)} 项，失败 {failed} 项")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(_run_all())
def test_relay_logs_message_id():
    """回归：relay() 必须在日志里带上 Message-ID。

    背景：曾因缺少 `import email` 导致提取抛 NameError，被 except 静默吞掉，
    日志只显示 msgid=- 而看不出原因，投递链路按月 ID 串不起来。
    """
    import logging as _logging
    records = []

    class _H(_logging.Handler):
        def emit(self, r):
            records.append(r.getMessage())

    logger = bridge.log
    h = _H()
    logger.addHandler(h)
    saved = bridge.smtplib.SMTP_SSL

    class FakeSMTP:
        def __init__(self, *a, **kw): pass
        def __enter__(self): return self
        def __exit__(self, *a): return False
        def login(self, u, p): pass
        def sendmail(self, *a): pass

    bridge.smtplib.SMTP_SSL = FakeSMTP
    try:
        raw = b"\r\n".join([
            b"From: a@urbansproutglobal.com",
            b"To: b@example.com",
            b"Message-ID: <regress-123@urbansproutglobal.com>",
            b"Subject: t",
            b"",
            b"body",
            b"",
        ])
        bridge.relay(raw, "a@urbansproutglobal.com", ["b@example.com"])
    finally:
        bridge.smtplib.SMTP_SSL = saved
        logger.removeHandler(h)

    joined = " | ".join(records)
    assert "RELAY OK" in joined, "relay 成功后必须记 REPLAY OK 日志"
    assert "regress-123@urbansproutglobal.com" in joined, \
        "日志里必须包含 Message-ID（实际：%s）" % joined
    assert "msgid=-" not in joined, "不得出现 msgid=- （说明提取失败）"


def test_email_module_is_imported():
    """回归：email 模块必须导入（relay 用它解析 Message-ID）。"""
    assert hasattr(bridge, "email"), "bridge 必须 import email"

