# Resend 中继接入 · 材料与操作清单

> 用途：把本清单发给中继服务商对接人 / 内部执行人，按此提供材料即可完成接入
> 更新日期：2026-10-08
> 本清单不含任何密钥，可对外发送

---

## 一、需要对方提供什么

### 1. DNS 记录（必需，共 3 条）

在 Resend 后台 **Domains → 选择域名 → Records 标签页** 获取。

请把该页面**完整截图**，或按以下格式复制成文本：

```
记录 1
  类型 (Type)   : MX 或 CNAME
  名称 (Name)   : ____________________
  值 (Value)    : ____________________
  优先级 (Priority): ______（仅 MX 需要）

记录 2
  类型 (Type)   : TXT 或 CNAME
  名称 (Name)   : ____________________
  值 (Value)    : ____________________

记录 3
  类型 (Type)   : TXT 或 CNAME
  名称 (Name)   : ____________________
  值 (Value)    : ____________________
```

**三条记录各自的用途：**

| # | 类型 | 典型名称 | 用途 |
|---|---|---|---|
| 1 | MX | `send.你的域名` | Return-Path 回信地址（退信/bounce 回收） |
| 2 | TXT | `send.你的域名` | SPF 发信授权（内容形如 `v=spf1 include:amazonses.com ~all`） |
| 3 | TXT | `resend._domainkey.你的域名` | DKIM 公钥（一长串 `p=MIIBIjANBg...`） |

> 视 Resend 版本，也可能全部以 **CNAME** 形式给出（`resend._domainkey`、`send` 等）。两种形式都可以，按后台实际显示为准。
> **务必复制粘贴，不要手工输入** —— 官方明确要求记录值必须与生成值完全一致。

### 2. 域名状态确认

在 Resend 后台确认该域名的状态显示为 **Verified**。

未验证前无法发信。

### 3. 发信 API Key（可选，用于 API 方式发信）

在 Resend 后台 **API Keys 页面**创建。

- 若仅通过 SMTP 中继发信，**不需要**此项
- 若需要程序化调用 API，需要此项
- **注意权限**：请创建 **Full Access** 权限的 Key。若只有 "Sending access" 权限，则无法读取域名列表与验证状态，会限制后续自动化运维

> ⚠️ API Key 属于敏感凭据，请通过安全渠道传递，不要放在公开文档或聊天记录中。

### 4. （可选）账号信息

| 项 | 用途 |
|---|---|
| 使用的 Resend 账号邮箱 | 便于后续排查账号级问题 |
| 所选 Region（区域） | 确认发信出口区域 |
| 套餐类型（Free / Pro / Scale） | 确认额度上限 |

---

## 二、需要对方确认的三个决策点

### 决策点 1：用根域还是子域？ ⭐ 重要

Resend 官方文档原话：

> *"We strongly recommend sending emails from a subdomain (e.g., `notifications.example.com`) instead of your root domain (`example.com`) to conform to deliverability best practices."*

| 选项 | 发件人地址示例 | 官方态度 | 影响 |
|---|---|---|---|
| **子域** | `daniel@send.urbansproutglobal.com` | ✅ 强烈推荐 | 进箱率更好；但地址与现有不一致 |
| **根域** | `daniel@urbansproutglobal.com` | ⚠️ 不推荐 | 地址不变；进箱率可能略低 |

**请明确告知采用哪一种。** 若选子域，请给出子域前缀（如 `send`、`mail`、`notifications`）。

### 决策点 2：Region 选择

选择离**主要收件人**最近的区域。

| 主要收件人位置 | 建议 Region |
|---|---|
| 北美 | US East (N. Virginia) |
| 欧洲 | EU (Ireland) |
| 亚太 | 视 Resend 当前可选区域而定 |

### 决策点 3：是否开启追踪功能

Resend 提供 **打开追踪 / 点击追踪**。

- 开启后邮件会插入追踪像素和链接改写
- **可能影响部分收件方的垃圾判定**
- 建议：**初期关闭**，先确认投递率，再按需开启

---

## 三、⚠️ 三个必须注意的技术要点

### 要点 1：CNAME 记录必须关闭 Cloudflare 代理

Resend 官方文档原话：

> *"make sure not to use proxying features (e.g., Cloudflare's orange cloud) for that record, as it will prevent verification from completing."*

即：在 Cloudflare 添加 CNAME 时，**必须把「代理状态」设为关闭（灰色云朵，DNS only）**，否则验证永远无法通过。

### 要点 2：一个域名只能有一条 SPF 记录 ⚠️ 最高风险项

DNS 规范规定：**同一域名下只能存在一条 `v=spf1` 记录**。

如果直接在原 SPF 之外**新增**一条 Resend 的 SPF，会导致：

```
两条 SPF → spf=permerror → 所有接收方都判为 SPF 失败 → 比不改还糟
```

**正确做法是合并**，例如：

```
修改前：v=spf1 mx a:mail.pony-it.com -all
修改后：v=spf1 mx a:mail.pony-it.com include:amazonses.com -all
```

> 本项目的 DNS 改动由执行方通过 Cloudflare API 自动完成，并会在改动前完整备份，可一键回滚。

### 要点 3：验证耗时

Resend 官方文档原话：

> *"your domain will often verify within 15 minutes of adding the DNS records. However, DNS changes can occasionally take up to 72 hours to propagate globally."*

通常 **15 分钟**内完成，极端情况最长 **72 小时**。若超时未通过，可在后台点击 **Restart verification** 重新触发验证。

---

## 四、对方提供材料后的执行流程

| 步骤 | 执行方 | 内容 |
|---|---|---|
| 1 | **对方** | 在 Resend 后台添加域名，取得 3 条 DNS 记录 |
| 2 | **对方** | 将记录（截图或文本）交付执行方 |
| 3 | 执行方 | 读取并备份现有 DNS 记录 |
| 4 | 执行方 | 写入 Resend 提供的 3 条记录（CNAME 关闭代理） |
| 5 | 执行方 | **合并** SPF 记录（不可新增第二条） |
| 6 | 执行方 | 用 `dig` 验证 DNS 已生效 |
| 7 | **对方** | 在 Resend 后台点击 **Verify**，确认状态变为 Verified |
| 8 | 执行方 | 配置邮件系统的出站中继 |
| 9 | 执行方 | 实发测试邮件，读取收件方落点（收件箱 / 垃圾箱） |
| 10 | 执行方 | 输出验证结果报告 |

**对方仅需完成第 1、2、7 步。**

---

## 五、信息交接格式（推荐）

请按以下模板填写后回传，便于执行方直接处理：

```
【Resend 接入信息】

域名            : ____________________
采用根域/子域   : ____________________
Region          : ____________________
当前状态        : Pending / Verified
套餐            : Free / Pro / Scale

── DNS 记录 ──
[1]
类型     :
名称     :
值       :
优先级   :（仅 MX）

[2]
类型     :
名称     :
值       :

[3]
类型     :
名称     :
值       :

── 其他 ──
是否已创建 API Key : 是 / 否
API Key 权限       : Full Access / Sending access / 未创建
是否开启追踪       : 是 / 否
```

---

## 六、常见问题

**Q：为什么必须改 DNS？**
A：DNS 记录是向全世界邮件服务商声明「这个域名授权谁代发邮件」的唯一方式。不改 DNS，接收方会判定为伪造发件人。

**Q：改了 DNS 会影响现在的邮件收发吗？**
A：不会。只新增 SPF 授权和 DKIM 公钥，**不改变**现有 MX（收信）和 A 记录（网站）。

**Q：验证通过后，原来的邮件系统还能用吗？**
A：可以。中继只影响**出站**路径，入站收信与 Web 界面不受影响。

**Q：如果验证一直不通过怎么办？**
A：依次检查：① CNAME 是否误开了 Cloudflare 代理；② 记录值是否与后台完全一致（有无多余引号/空格）；③ 用 `dig` 确认记录已在公网可见；④ 后台点 Restart verification。

**Q：子域和根域将来能改吗？**
A：可以。在 Resend 后台可另行添加子域并单独验证，两者可并存。

---

*文档生成：2026-10-08 · 技术要点依据 Resend 官方文档（resend.com/docs/add-a-domain）*
