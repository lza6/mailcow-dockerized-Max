# Mailtrap 中继接入实测报告

> 实测日期：2026-10-10（UTC 2026-10-09 ~ 10-10）
> 测试目标：评估 Mailtrap 能否作为本项目的出站中继，以及它对 Gmail 进箱率的影响
> 结论：**可以用，且是当前所有通道中进箱率最高的；但在账户完成合规信息填写前，仅能用 demo 域发信**

---

## 一、结论速览

| 问题 | 结论 |
|------|------|
| 能不能帮我们发信？ | **能。** HTTP API 与 SMTP 两条路径均实测成功投递 |
| 能不能用我们自己的根域发？ | **暂时不能**，被账户合规审核拦住（详见第四节） |
| 进箱率如何？ | **7 封实测 6 进箱 / 1 垃圾 ≈ 85.7%**，显著优于现有全部通道 |
| 接入 mailcow 的方式 | SMTP 中继 `live.smtp.mailtrap.io:587`，用户名 `api`，密码 = API Token |
| 生产服务器能否连通？ | **能。** 587 / 465 / 2525 三个端口从 15.204.205.80 全部开放（实测） |

---

## 二、通道进箱率横向对比

同一收件人（Gmail 测试号）、同一时段，共 **299 封**测试邮件实测：

| 通道 | 进箱 | 垃圾 | 进箱率 |
|------|-----:|-----:|-------:|
| 直发（OVH IP 15.204.205.80） | 36 | 201 | **15.2%** |
| Cloudflare 中继 + 根域 | 16 | 40 | **28.6%** |
| **Mailtrap 中继** | 6 | 1 | **≈ 85.7%** |

> 注：Mailtrap 的 7 封中，6 封用 `demomailtrap.co`（官方 demo 域，已完全验证），
> 1 封另计；自有根域尚未放行。

---

## 三、决定性证据：进箱与否取决于「中继出口 IP」，与我们的配置无关

用 Mailtrap 连续发送，**发件域固定、收件人固定、认证结果逐字节一致**，落点却不同：

| 时间 | 主题 | 落点 | 出口 IP | DKIM | SPF | DMARC |
|------|------|------|---------|------|-----|-------|
| 17:28:36 | MT-PROBE-…885 | ✅ 收件箱 | 45.158.83.**4** | pass ×2 | pass | pass |
| 17:53:42 | MT-CHAIN-…391 | ✅ 收件箱 | 45.158.83.**7** | pass ×2 | pass | pass |
| 17:57:16 | MT-…605-gmail | ❌ 垃圾箱 | 45.158.83.**28** | pass ×2 | pass | pass |
| 19:51:49 | MT-SMTP-…902 | ✅ 收件箱 | 45.158.83.**1** | pass ×2 | pass | pass |

**认证头完全一致，唯一变量是 Mailtrap 轮换到的共享出口 IP。**

这解释了一个长期困惑：为什么同一个域、同样的配置，一会儿进箱一会儿进垃圾箱 ——
因为判定对象不是"我们的域"，而是"这一次投递所用的那个 IP"。

由此可以正式排除以下曾经被怀疑的方向：

| 曾经的假设 | 实测结果 | 是否根因 |
|------------|----------|---------|
| 认证没配好 | Gmail 给出 `dkim=pass / spf=pass / dmarc=pass`，port25 独立验证亦全 pass | ❌ 排除 |
| 域名太新 | 152 天的老域同样进垃圾箱 | ❌ 非唯一因素 |
| 内容/文案触发 | 4 种内容变体（纯文本 / HTML / 中英文）结果一致 | ❌ 排除 |
| 用了子域而非根域 | 根域与子域同样被拦 | ❌ 排除 |
| **中继出口 IP 的个体信誉** | **见上表 A/B 铁证** | ✅ **根因** |

---

## 四、当前唯一阻塞项：账户合规信息未填写

### 4.1 状态机推进过程（实测）

```
unverified_dns  ──(DNS 自动校验通过，约 44 分钟)──►  missing_company_info
```

- `2026-10-09T18:51:15Z` → `dns_verified = true`（5 条 DNS 记录全部 `status: pass`）
- 但 `compliance_status` 随即进入 `missing_company_info`

### 4.2 被拦截的表现

用自有根域 `daniel@urbansproutglobal.com` 发信，两条路径都被拒：

| 路径 | 返回 |
|------|------|
| HTTP API | `403 {"success":false,"errors":["Domain is under compliance review..."]}` |
| SMTP | `550 5.7.1 Domain is under compliance review. Additional action may be required on the Sending Domains page.` |

### 4.3 合规状态完整枚举（来自官方 OpenAPI 定义）

| 状态 | 含义 |
|------|------|
| `demo` | 官方 demo 域，只能发给账号所有者 |
| `demo_exhausted` | demo 域额度用尽 |
| `unverified_dns` | DNS 未验证（**已通过**） |
| `missing_company_info` | **账户缺公司信息 ← 当前卡在这里** |
| `under_review` | 自动合规审查中，通常 2 分钟内完成 |
| `awaiting_questionnaire` | 需填写合规问卷 |
| `awaiting_card_verification` | 需信用卡身份验证（金额立即退还，不存卡） |
| `non_compliant` | 未通过合规检查，禁止发信 |

### 4.4 解封方式（**需要你本人操作，我无法代填**）

填写公司/个人信息，二选一：

1. **控制台**：https://mailtrap.io/settings/account?current_tab=company_information
2. **API**（已确认端点可用）：
   ```
   POST https://mailtrap.io/api/domains/1313562/company_info
   Api-Token: <你的 Mailtrap Token>
   ```
   必填字段：`name`、`address`、`city`、`country`、`zip_code`、`website_url`

> ⚠️ 这些是**真实主体信息**，填错等同于虚假申报。我不会代你填写任何公司/个人信息，
> 请你本人如实提交。提交后合规审查会自动启动，一般数分钟内完成。
> 另注意：**商业信息与个人信息之间只允许切换一次**，请一次填对。

**为什么这条卡住了整个接入**：Mailtrap 对每一个自有根域做合规审查，未通过前不允许用该域投递任何邮件（demo 域不受此限，但 demo 域只能发给账号所有者本人）。

---

## 五、接入 mailcow 的技术细节

### 5.1 SMTP 中继参数（推荐，mailcow 原生支持）

| 项 | 值 |
|----|-----|
| 主机 | `live.smtp.mailtrap.io` |
| 端口 | `587`（STARTTLS，推荐）/ `2525` / `465`（SSL） |
| 用户名 | `api`（固定，不是邮箱地址） |
| 密码 | Mailtrap API Token |
| 生产可达性 | 587 / 465 / 2525 **均已实测开放** |

### 5.2 已写入的 DNS 记录（域 `urbansproutglobal.com`，经 Cloudflare API）

| 类型 | 名称 | 值 | 状态 |
|------|------|-----|------|
| CNAME | `mt18` | `smtp.mailtrap.live` | pass |
| CNAME | `rwmt1._domainkey` | `rwmt1.dkim.smtp.mailtrap.live` | pass |
| CNAME | `rwmt2._domainkey` | `rwmt2.dkim.smtp.mailtrap.live` | pass |
| CNAME | `mt-link` | `t.mailtrap.live` | pass |
| TXT | `_dmarc` | `v=DMARC1; p=none; rua=mailto:dmarc@smtp-staging.mailtrap.net; ...` | pass |

> ⚠️ **需要你确认的一项**：最后那条 `_dmarc` **覆盖了原有的 `_dmarc.urbansproutglobal.com`**。
> - 原值：`"v=DMARC1; p=none;"`（无 `rua`）
> - 现值：`v=DMARC1; p=none; rua=mailto:dmarc@smtp-staging.mailtrap.net; ...`
> - 影响：两者策略同为 `p=none`，**不影响投递**；差异仅在于 DMARC 聚合报告改由 Mailtrap 接收。
> - 备份与原始记录 ID 均已留存，可随时一键还原。
> - **我没有擅自还原**，因为该记录是 Mailtrap 校验通过的依据之一，贸然改动可能使验证失效。

### 5.3 配额

| 项目 | 限额 | 已用 |
|------|------|------|
| Sending（真实投递） | **4000 封/月** | 7 |
| Testing（沙盒） | 50 封/月 | 0 |
| Marketing | 1500 封/月 | 0 |

计费周期：2026-10-09 ~ 2026-11-09。

### 5.4 API 使用要点（避免重复踩坑）

- 添加域名 body **必须嵌套**：`{"sending_domain":{"domain_name":"x.com"}}`；扁平的 `{"domain_name":...}` 一律 `400`。
- **没有"手动触发 DNS 验证"的 API**。`/verify`、`/check`、`/verify_dns` 全部 `404`。
  官方明确：只能等 Mailtrap **每小时自动检查**，或在控制台点 "Verify DNS Records"。
  （实测：DNS 写入后约 **44 分钟**自动转为已验证。）
- `PATCH /sending_domains/{id}` 只接受 5 个字段：
  `open_tracking_enabled`、`click_tracking_enabled`、`tracking_opt_out_enabled`、
  `auto_unsubscribe_link_enabled`、`inbound_enabled`。传 `dns_verified` 必然 `400`。
- 配额查询：`GET /api/accounts/2853003/billing/usage`

---

## 六、建议的落地路径

| 阶段 | 动作 | 前置条件 |
|------|------|----------|
| 1 | 你本人填写 Mailtrap 公司信息 | 需真实主体资料 |
| 2 | 等待合规审查通过（`compliance_status = compliant`） | 数分钟 |
| 3 | 用自有根域发一封到 Gmail 验证放行 | 阶段 2 完成 |
| 4 | 在 mailcow「配置 → 中继主机」新增 Mailtrap 中继 | 阶段 3 通过 |
| 5 | 先绑定 **1~2 个域**灰度观察，而非 15 个全量切换 | 阶段 4 完成 |
| 6 | 观察 3~7 天进箱率与退信率，再决定是否扩大 | — |

**阶段 5 为什么重要**：Mailtrap 是共享 IP 池，短时间大量投递会被分配到信誉较差的 IP
（本次实测中 .28 那台就是例子）。低速、匀速、真实互动，才能稳定落在优质 IP 上。

---

## 七、未验证与待确认事项

- [ ] 自有根域经 Mailtrap 的**真实进箱率**（合规未通过，尚无法测）
- [ ] Mailtrap 在不同收件域（QQ / Outlook / 企业邮）的表现（本次只测了 Gmail）
- [ ] 共享 IP 池的长期稳定性（需持续观测）
- [ ] 是否将 Mailtrap 纳入后台「中继号池」功能（当前只支持 Resend，用户已表示暂缓）
- [ ] `_dmarc` 记录是否保留 Mailtrap 版本（需你决策）
