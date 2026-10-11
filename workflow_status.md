# workflow_status.md — 需求追踪矩阵与工作流状态

> 维护规则：**只记录事实与证据**，不写私有推理；只有观察到交付物 + 验收证据才标 `done`。
> 本文件每次迭代后更新；下次接手先读本文件 + `docs/README.md`，再决定是否需要重跑验证。

- 生产环境：`15.204.205.80` / `mail.pony-it.com`
- 目标仓库：`https://github.com/lza6/mailcow-dockerized-Max`（分支 `main`）
- 本文件最后一次更新：2026-10-11，对应 Release `v2026.07b-cn.7`

---

## 一、需求追踪矩阵

状态取值：`done`（已闭环且有证据） / `partial`（部分闭环） / `todo` / `blocked`（外部条件缺失） / `n/a`

| # | 需求 | 实现位置 | 状态 | 证据 | 缺口 / 下一步 |
|---|---|---|---|---|---|
| R1 | 后台总览看每个域的真实状态 | `data/web/admin/overview.php`、`templates/overview.twig`、`inc/functions.overview.inc.php` | done | 生产页面 200/89KB；jsdom 实测域表 16 行 | — |
| R2 | 邮箱账号可见、可重置密码、可启停、一键进 Webmail | `inc/ajax/overview.php`、`js/site/overview.js` | done | 端到端实测；**Webmail 走 `/sogo-auth.php?login=<邮箱>`**（mailcow 官方入口，需 `ALLOW_ADMIN_EMAIL_LOGIN=y`，已在生产开启） | — |
| R3 | 每一步留痕（审计） | `inc/functions.audit.inc.php`、`admin_audit_log` | done | 审计表实测 1724+ 条；密钥披露单独记 `secret.reveal`，明细不含密钥 | 压测产生的记录保留未删（删审计=伪造审计） |
| R4 | 号池账号管理（增删启停、Key 掩码/显示） | `inc/ajax/resend_pool.php` + overview UI | done | `reveal_secret` 实测返回明文并落审计；GET→405 | — |
| R5 | 号池容量/额度/剩余可见 | `ov_relay_pool()` | done | 实测：1 账号 / 已绑 3-3 / 剩余 0 / 日额度 100 / 上限 3 | 容量已满，需扩账号 |
| R6 | 域名绑定/解绑/一键自动分配 | `inc/ajax/resend_pool.php` | done | 生产绑定表 3 行 verified | — |
| R7 | CF Token 可见/可改 | `reveal_secret kind=cf` | done | 实测返回 53 字符并落审计 | — |
| R8 | **邮箱显示走哪个中继账号** | `ov_relay_pool` → `relay_acct` | done | jsdom 实测：3 个邮箱显示 `Resend-账号1`，其余「直发」 | — |
| R9 | 邮箱表显示最近邮件时间 / 今日邮件数 | — | **todo** | — | 需要从 dovecot/quota2 取「最近一封时间」；`quota2` 无此字段，需查 maildir 或加索引 |
| R10 | 邮箱可改绑定的 re 账号 | — | **todo** | — | 域名级绑定已有；邮箱级覆盖需新表（mailcow 中继是域级的） |
| R11 | 密码可显示/复制 | 登录密码：**不可行** | blocked | bcrypt 单向哈希，数学不可逆 | 见 R12 的替代方案 |
| R12 | 养号用的收件人凭据可保存/使用 | `inc/functions.secretbox.inc.php` | done | 加密→解密往返正确；篡改后返回空串；密钥 0600 且不在 web 目录 | — |
| R13 | 养号：真实进箱/垃圾箱判定 | `inc/functions.warmup_ext.inc.php` + `warmup_messages` | done（待数据） | 代码 + 表 + cron 就位；`\Spam` 属性查找（不硬编码本地化文件夹名） | **需要 Google 应用专用密码**才会产生数据 |
| R14 | 养号：收件人账号化 + 一键验证 | `ajax/warmup.php add_recipient/verify_recipient` | done | 页面控件齐全，按钮审计通过 | 同上 |
| R15 | 养号：自动回复（互动） | `warmup_interact()` | done（待数据） | 每账号每轮 ≤1 封；只回已判定 inbox 的；同封只回一次 | 同上 |
| R16 | 养号：垃圾箱救援 | `wwe_rescue_from_spam()` | done（待数据） | 同上 | 同上 |
| R17 | 养号：下一封什么时候发 | `warmup_next_send()` / `warmup_target_ts()` | done | jsdom 实测显示「2026-10-11 11:32 待发送」 | — |
| R18 | 养号：今日配额可实时编辑 | `ajax/warmup.php edit_plan` + UI 输入框 | done | 页面配额输入框 2/0/3 可改 | — |
| R19 | 养号：垃圾箱率 / 进箱率 / 今日率 | `warmup_delivery_stats()` | done（待数据） | 页面统计条 13 项渲染 | 判定分母只算已判定项 |
| R20 | 养号：逐封明细（发给谁/Message-ID/结果） | `warmup_messages` + UI | done | 表已建、UI 已渲染 | 待首封数据 |
| R21 | 养号：没绑 Resend 的域也要能养 | `ajax/warmup.php add_plans_for_all` | done | 一键为全部未养号邮箱建计划 | — |
| R22 | Google 应用密码获取方法（文档+UI） | UI 弹窗 + Release 说明 | done | 界面「怎么获取应用专用密码？」含 6 步 + 3 个前提 | 可补 `docs/` 独立文档 |
| R23 | **每个按钮都要真实有逻辑** | — | done | 审计脚本：47 个控件全部有处理器；修复了 1 个假按钮 `ov_pool_reveal` | 建议把审计脚本纳入 CI |
| R24 | 域名信誉显示 + 数据来源标注 | 部分：SPF/DKIM/DMARC/MX + RDAP 域龄 | partial | 体检已有；来源是 `dns_get_record` + RDAP | **Google Postmaster 信誉分未接入**（需用户 OAuth 授权） |
| R25 | 高并发/压测 | — | partial | 只读 1950 请求 0 失败；并发写 600 次 0 死锁 | 发现 `pm.max_children=50` 打满；更高并发需调大 |
| R26 | 用户提出的「密码可复制」 | — | blocked | 同 R11 | 只能：重置后显示一次 / 为养号收件人凭据保存（R12） |

---

## 二、已知硬边界（不是"没做"，是"做不到"）

| 边界 | 原因 |
|---|---|
| 显示邮箱**登录密码**明文 | mailcow 用 bcrypt 单向哈希，数学上不可逆。任何系统都做不到，除非改存储方式（会削弱安全） |
| 知道邮件进了 Gmail 的哪个文件夹 | 必须能登录**收件人**账号 → 需要该账号的应用专用密码 |
| Google Postmaster 域/IP 信誉分 | 需要用户本人用 Google 账号做 OAuth 授权 |
| Mailtrap 实发 | 卡在账号 `missing_company_info` 合规，需用户填公司信息 |
| serv00 邮件数据迁移 | 需要 serv00 凭据（已下线，但历史邮件在那边） |

---

## 三、验证台账（避免重复劳动）

| 验证项 | 最后执行 | 结论 | 何时需要重跑 |
|---|---|---|---|
| 页面渲染（jsdom 真实 DOM） | 2026-10-11 | 域 16 行 / 邮箱 16 行 / 池 1 / 绑 3 / 计划 1 / 收件人 1 / 汇总 2 / 审计 30 | 改 `overview.twig` / `overview.js` / `functions.overview.inc.php` 后 |
| 空 tbody 检查 | 2026-10-11 | 83 个模板全部通过 | 改任何 `.twig` 后 |
| 按钮处理器审计 | 2026-10-11 | 47 控件全部有处理器 | 改 `overview.twig` / `overview.js` 后 |
| bridge 单元测试 | 2026-10-11 | 24/24 通过 | 改 `bridge.py` 后 |
| CSRF 加固回归 | 2026-10-11 | 伪造→403 / 缺 token→403 / GET→405 / 合法→成功 | 改 `sessions` 相关或端点入口后 |
| 凭据保管箱 | 2026-10-11 | 往返正确 + 篡改检测生效 | 改 `functions.secretbox.inc.php` 后 |
| 并发写压测 | 2026-07b-cn.5 | 600 次 0 失败 0 死锁 | 改 `ov_write()` 或 mailbox 写路径后 |
| 只读压测 | 2026-07b-cn.4 | 1950 请求 0 失败，峰值 ~123 RPS | 改 `overview_build()` 或加首屏网络 I/O 后 |
| 数据库索引 | 2026-07b-cn.5 | `overview_mbox_daily.day`、`warmup_log.plan_run` 已建 | 改 `init_db.inc.php` 后 |

> **下次改到某块代码时，先看本表：如果对应验证项没变，就不用重跑。**

---

## 四、待办（按优先级）

### P1
1. **收件人账号填入 Google 应用专用密码**（用户操作）→ 之后 R13/R14/R15/R16/R19/R20 才会产生真实数据
2. **养号计划 `日增 = 0`** → 配额恒为 2/天、永不增长；建议改 ≥1
3. **号池容量 3/3 已满** → 需新增 Resend 账号或调高单账号上限

### P2
4. R9 邮箱「最近一封邮件时间 / 今日邮件数」——需要新的数据来源（`quota2` 无此字段）
5. R10 邮箱级中继覆盖
6. 把「按钮审计」「空 tbody 检查」纳入 CI
7. Google Postmaster 接入（blocked: 需 OAuth）

### P3
8. 保留期清理任务已上线（audit 180d / warmup_log 365d / overview_mbox_daily 90d），观察一段时间
9. `docs/` 补一份《Google 应用专用密码获取》独立文档

---

## 五、本仓库特有的三个"坑"（已沉淀为记忆 + 脚本）

| 坑 | 症状 | 防护 |
|---|---|---|
| mailcow 的 `footer.js` 会 `$('tbody').filter(空).remove()` | 用 JS 填表的页面**整表永远空白**，控制台无报错，响应体里元素 ID 齐全 | 模板放占位行 + JS `body()` 兜底；`helper-scripts/check_empty_tbody.cjs` |
| CSRF 语义反直觉：通过后 unset 字段并轮换，失败才清空 `$_POST` | 直接比对 token → **所有合法写请求 403** | 判据用「`$_POST` 是否被清空」；无参副作用动作必须显式校验 |
| 远端 `main` 与本地 `master` **无共同祖先** | 直接 push 被拒；force push 会丢历史与 tag | `fetch max/main` → cherry-pick → 快进推送 |

---

## 六、部署清单（改了什么要重启什么）

| 改动的文件 | 需要的动作 |
|---|---|
| `data/web/**/*.php` | `docker restart php-fpm-mailcow`（`opcache.validate_timestamps=Off`） |
| `data/web/templates/**` | 同上 |
| `data/web/js/site/*.js` | 同上 + `rm -f data/web/cache/*.js`（js_minifier 缓存） |
| `docker-compose.yml`（ofelia 标签 / 新挂载） | `docker compose up -d --force-recreate --no-deps php-fpm-mailcow`，**随后必须重启 nginx**（它缓存上游 IP） |
| `data/conf/phpfpm/crons/*.php` | 只重启 php-fpm（目录已挂载） |
| `init_db.inc.php` 新增表 | 无需操作（下次请求自动建）；**改已有表的列必须手工 ALTER** |
