# serv00 下线回收报告

> 日期：2026-10-10
> 结论：**serv00 已完全下线，所有域名的收信链路已恢复到自有服务器**
> 状态：已执行完毕并通过一致性核验

---

## 一、背景

`breezeshelf.com` 域名曾将 MX 指向第三方免费主机 **serv00**（`mail16.serv00.com`），
导致该域**完全收不到外部邮件**，用户连登录验证码都取不到。

经确认：**serv00 服务器已不再使用**，需要把所有相关配置回收干净，
恢复到自有邮件服务器 `mail.pony-it.com`。

---

## 二、问题定位过程

### 2.1 关键判断：区分入站与出站

这次故障与之前排查的"发信进垃圾箱"**是两个完全无关的问题**：

| 方向 | 症状 | 根因 | 排查入口 |
|------|------|------|---------|
| 出站 | 我们发出的邮件被判垃圾 | 中继出口 IP 信誉 | `Authentication-Results` 头 |
| **入站** | **收不到邮件 / 收不到验证码** | **MX 记录指向错误** | **MX 记录** |

一开始容易误判为老问题，实际查 MX 后立刻定位。

### 2.2 决定性证据

| # | 证据 | 结论 |
|---|------|------|
| 1 | `breezeshelf.com` MX = `mail16.serv00.com`（77.79.248.165） | 外部邮件全投到 serv00 |
| 2 | 其余 15 个域 MX 均 = `mail.pony-it.com` | 只有这一个域配置漂移 |
| 3 | postfix 日志中该域**只有出站记录，无任何入站投递** | 入站链路确实断在 DNS 层 |
| 4 | dovecot 中该邮箱仅 30 封（均为测试邮件） | 没有外部新邮件进入 |

MX 决定"外部邮件投递到哪台服务器"。指向 serv00，意味着发给该域的邮件
**永远不会到达 mailcow**——邮箱里自然什么都收不到。

---

## 三、回收操作清单

### 3.1 MX 记录

| 域 | 改动前 | 改动后 |
|----|--------|--------|
| `breezeshelf.com` | `mail16.serv00.com` (prio 10) | **`mail.pony-it.com` (prio 10)** |

- 操作方式：Cloudflare API 原地修改（record id `6af5d98b1afffa88be1c9728c282b704`）
- 改动前已备份该域全部 8 条 MX 记录
- TTL 300

### 3.2 SPF 记录

| 域 | 改动前 | 改动后 |
|----|--------|--------|
| `breezeshelf.com` | `v=spf1 mx a include:mail16.serv00.com -all` | **`v=spf1 mx a:mail.pony-it.com -all`** |

- TXT record id `a6ccea842df779ad4123c22edeb4aa02`，改动前已备份
- **为什么必须清掉**：MX 改完后这条 `include` 已无必要，而且**留着等于允许
  serv00 的服务器代发该域邮件并通过 SPF 校验** —— 属于安全面，必须移除。
- 改后与其余 14 个域完全一致

### 3.3 全量扫描确认无其他残留

对 Cloudflare 中全部 **23 个 zone** 做了逐条记录扫描，关键词 `serv00`：

```
修复前命中：1 条（breezeshelf.com 的 SPF）
修复后命中：0 条
```

**除上述两条外，没有任何其他 DNS 记录引用 serv00。**

---

## 四、验证（真实端到端）

### 4.1 DNS 一致性核验

| 检查项 | 结果 |
|--------|------|
| MX 是否全部指向 `mail.pony-it.com` | **16/16 通过** |
| SPF 是否还有 serv00 引用 | **0 处** |
| 异常域数量 | **0** |

### 4.2 真实入站投递测试

从 **Gmail（外部真实来源）** 发一封邮件到 `alex@breezeshelf.com`：

| 环节 | 结果 |
|------|------|
| SMTP 发送 | `HTTP 250`（已接受） |
| postfix 日志 | `to=<alex@breezeshelf.com>, relay=dovecot, status=sent (250 2.0.0 ... Saved)` |
| dovecot 邮箱 | 消息数 **30 → 31** |
| 收到的最新一封 | `from: Gmail Test <q13645947407@gmail.com>` / `subject: INBOUND-TEST-1791629557` |

**结论：入站投递已完全恢复。**

---

## 五、当前全部域名状态（回收后）

| 域名 | MX | SPF |
|------|-----|-----|
| urbansproutglobal.com | mail.pony-it.com | `v=spf1 mx a:mail.pony-it.com -all` |
| **breezeshelf.com** | **mail.pony-it.com** | **`v=spf1 mx a:mail.pony-it.com -all`** |
| brightcrateglobal.com | mail.pony-it.com | `v=spf1 mx a:mail.pony-it.com -all` |
| cedarcartco.com | mail.pony-it.com | `v=spf1 mx a:mail.pony-it.com -all` |
| cedarloomexporthouse.com | mail.pony-it.com | `v=spf1 mx a:mail.pony-it.com -all` |
| echolanecommerce.com | mail.pony-it.com | `v=spf1 mx a:mail.pony-it.com -all` |
| harborpinetradeworks.com | mail.pony-it.com | `v=spf1 mx a:mail.pony-it.com -all` |
| hearthcart.com | mail.pony-it.com | `v=spf1 mx a:mail.pony-it.com -all` |
| indigobasketglobal.com | mail.pony-it.com | `v=spf1 mx a:mail.pony-it.com -all` |
| larkhavensupplyworks.com | mail.pony-it.com | `v=spf1 mx a:mail.pony-it.com -all` |
| mangobridgeretail.com | mail.pony-it.com | `v=spf1 mx a:mail.pony-it.com -all` |
| maplevalleytrading.com | mail.pony-it.com | `v=spf1 mx a:mail.pony-it.com -all` |
| summitnestretail.com | mail.pony-it.com | `v=spf1 mx a:mail.pony-it.com -all` |
| trendhollowretail.com | mail.pony-it.com | `v=spf1 mx a:mail.pony-it.com -all` |
| xiaomillc.com | mail.pony-it.com | `v=spf1 mx a:mail.pony-it.com -all` |
| pony-it.com | mail.pony-it.com | `v=spf1 mx -all` |

> `pony-it.com` 是服务器自身域名，MX 即本机，`mx` 机制已覆盖，无需再加 `a:`，属正常。

---

## 六、遗留事项与建议

### 6.1 需要处理

| # | 事项 | 说明 |
|---|------|------|
| 1 | **向 serv00 侧确认数据** | MX 指向 serv00 期间，发给该域的邮件都落在 serv00。若 serv00 账号仍可登录，应导出那批邮件；若已注销，该批邮件**视为丢失** |
| 2 | **请对方重新触发验证码** | 旧的验证码邮件已丢，无法找回 |
| 3 | **确认对方在哪里看邮件** | 反馈方客户端显示 **301 封**，而 mailcow 上该邮箱修复前仅 30 封、修复后 31 封 —— 数量不符，说明他看的**不是这个邮箱**（很可能还是 serv00 侧的账号）。需请其统一改用 **https://mail.pony-it.com/** |

### 6.2 建议（防复发）

| 优先级 | 建议 | 理由 |
|--------|------|------|
| 高 | **增加"全域名 MX 一致性"定期检查** | 本次本质是"**单个域配置漂移且长期无人发现**"。16 个域里有 1 个指错，靠人工很难发现 |
| 中 | **同样检查 SPF 一致性** | 本次 SPF 里也残留了第三方 include，属于潜在安全面 |
| 中 | 记录域名 DNS 变更审计 | 谁在什么时候改了 breezeshelf 的 MX，应有据可查 |

---

## 七、变更留痕（可回滚）

| 记录 | 改动前值 | 备份位置 |
|------|---------|---------|
| MX | `mail16.serv00.com` (prio 10) | Cloudflare 原始 record id `6af5d98b...`；MX 全量 8 条已备份 |
| SPF | `v=spf1 mx a include:mail16.serv00.com -all` | TXT record id `a6ccea84...`，改动前已备份 |

**注意**：serv00 已确认不再使用，**不建议回滚**；备份仅为审计留痕。
