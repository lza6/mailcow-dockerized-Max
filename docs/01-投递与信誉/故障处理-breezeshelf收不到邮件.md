# 故障处理记录：breezeshelf.com 收不到邮件

> 发生时间：2026-10-10
> 影响：`alex@breezeshelf.com` 完全收不到外部邮件（含各类验证码）
> 级别：P0（核心功能不可用）
> 状态：**已修复并验证**

---

## 一、现象

用户反馈：

- `alex@breezeshelf.com` **收不到邮件**
- 连**登录验证码都取不到**

与之前排查的"发信进垃圾箱"是**完全不同的问题**：
- 之前是**出站**（我们发出去的邮件被判垃圾）
- 这次是**入站**（外部发给他的邮件根本没到我们服务器）

---

## 二、根因

`breezeshelf.com` 的 **MX 记录指向了第三方主机 `mail16.serv00.com`**（77.79.248.165），
而不是我们自己的邮件服务器。

**其余 15 个域全部正确指向 `mail.pony-it.com`，只有这一个域是例外。**

### DNS 现状对比（修复前）

| 域名 | MX | 是否正确 |
|------|-----|---------|
| **breezeshelf.com** | **mail16.serv00.com (10)** | ❌ **错误** |
| urbansproutglobal.com | mail.pony-it.com (10) | ✅ |
| hearthcart.com | mail.pony-it.com (10) | ✅ |
| cedarcartco.com | mail.pony-it.com (10) | ✅ |
| brightcrateglobal.com | mail.pony-it.com (10) | ✅ |
| indigobasketglobal.com | mail.pony-it.com (10) | ✅ |
| larkhavensupplyworks.com | mail.pony-it.com (10) | ✅ |
| mangobridgeretail.com | mail.pony-it.com (10) | ✅ |
| maplevalleytrading.com | mail.pony-it.com (10) | ✅ |
| summitnestretail.com | mail.pony-it.com (10) | ✅ |
| trendhollowretail.com | mail.pony-it.com (10) | ✅ |
| xiaomillc.com | mail.pony-it.com (10) | ✅ |
| cedarloomexporthouse.com | mail.pony-it.com (10) | ✅ |
| echolanecommerce.com | mail.pony-it.com (10) | ✅ |
| harborpinetradeworks.com | mail.pony-it.com (10) | ✅ |
| pony-it.com | mail.pony-it.com (10) | ✅ |

MX 决定"外部邮件投递到哪台服务器"。指向 serv00，意味着**所有发给该域的邮件都被投到 serv00，
永远不会到达 mailcow**——邮箱里自然什么都收不到。

---

## 三、诊断证据

| # | 证据 | 说明 |
|---|------|------|
| 1 | `mailbox` 表中 `alex@breezeshelf.com` 存在且 `active=1`、`quota=0`（不限） | 邮箱本身没问题 |
| 2 | postfix 日志中该域**只有出站记录**（`from=<alex@...>`），**无任何入站投递** | 入站链路确实断了 |
| 3 | dovecot 中该邮箱修复前仅 **30 封**（均为测试邮件） | 没有外部新邮件进入 |
| 4 | `mail16.serv00.com` → `77.79.248.165`（第三方免费主机） | MX 指向外部服务 |

三条证据互相印证，指向同一个结论。

---

## 四、修复

在 Cloudflare 修改 `breezeshelf.com` 的 MX：

| 项 | 修复前 | 修复后 |
|----|--------|--------|
| MX 主机 | `mail16.serv00.com` | **`mail.pony-it.com`** |
| 优先级 | 10 | 10 |
| record id | `6af5d98b1afffa88be1c9728c282b704` | 同左（原地修改） |

> 修改前的完整记录已备份（8 条 MX，含 cf-bounce / send 子域记录，均未改动）。

---

## 五、验证（真实端到端）

1. **DNS 传播**：公共权威解析器（1.1.1.1 / 8.8.8.8 / 9.9.9.9）确认 MX 已为 `mail.pony-it.com`
2. **真实外部入站测试**：从 **Gmail（外部来源）** 发一封信到 `alex@breezeshelf.com`
   - postfix 日志：`to=<alex@breezeshelf.com>, relay=dovecot, status=sent (250 ... Saved)`
   - dovecot 邮箱：消息数 **30 → 31**
   - 最新一封：`from: Gmail Test <q13645947407@gmail.com>` / `subject: INBOUND-TEST-1791629557`

**结论：入站投递已恢复正常。**

---

## 六、遗留事项与影响说明

1. **MX 指向 serv00 期间发出的邮件已丢失**
   那段时间发给他的邮件都投到了 serv00，不在我们服务器上。
   **需要重新触发一次验证码**（旧的那封找不回来）。

2. **用户客户端里显示的 301 封邮件来源存疑**
   mailcow 上该邮箱只有 31 封（修复后）。截图中显示的"301 邮件"
   来自另一个邮箱/客户端（很可能是 serv00 侧），需请对方确认在**哪里**看邮件。
   建议统一使用 **https://mail.pony-it.com/** 登录 `alex@breezeshelf.com`。

3. **SPF 中残留 `include:mail16.serv00.com`**（`v=spf1 mx a include:mail16.serv00.com -all`）
   MX 已改，这条 include 已无必要；留着会让 serv00 具备代发该域并通过 SPF 的能力，
   属于**轻微安全面**。建议后续清理为 `v=spf1 mx a:mail.pony-it.com -all`（与其他域一致）。
   **本次未改动**（减少变更面，SPF 变更需单独评估）。

4. **建议补一项自动化检查**：定期比对全部域名的 MX 是否都指向 `mail.pony-it.com`，
   偏差即告警。本次问题本质是"单个域配置漂移且无人发现"。

---

## 七、给反馈方的回复要点

- 原因：该域 MX 被指到了第三方主机，外部邮件没到我们服务器
- 处理：已改回 `mail.pony-it.com`，与其他域一致
- 验证：已从外部实测发送，确认能正常进邮箱
- 请对方：**重新触发一次验证码**（旧邮件已丢），并在 https://mail.pony-it.com/ 查看
