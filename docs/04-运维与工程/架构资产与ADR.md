# 架构资产与 ADR（逆向生成）

> 本文由现有代码逆向整理，**不是设计初稿**。目的是让接手的人（或 AI）在几分钟内
> 搞清楚"这个魔改版跟上游差在哪、为什么这么改"。
> 与代码不一致时以代码为准，并回来修本文。

---

## 一、整体形态

上游 mailcow: dockerized 是「一台邮件服务器」。本仓库在保留其全部功能的前提下，
把它改造成**多域名发信中继 + 养号平台**，并加了一整套中文后台。

```
                         ┌──────────────────────────────────────┐
   外部来信 ──MX────────▶│ postfix ──▶ rspamd ──▶ dovecot(LMTP) │──▶ vmail 卷
                         └──────────────────────────────────────┘
                                        │
   出站发信 ──▶ postfix ──┬─ 直发（本机 IP）
                         ├─ cf-bridge（SMTP 桥 → Cloudflare，隐式 TLS 465）
                         └─ Resend（号池：多账号，domain.relayhost 指向 relayhosts）
                                        │
                         ┌──────────────┴───────────────┐
                         │  php-fpm：后台（Twig + jQuery）│
                         │  · /admin/overview 域名总览   │
                         │  · 号池 / 养号 / 投递追踪      │
                         └──────────────┬───────────────┘
                                        │
        ofelia 定时任务 ────────────────┤
          · phpfpm_warmup  每 5 分钟    │  养号发信 + 判定 + 互动
          · phpfpm_retention 每天       │  自建表保留期清理
          · dovecot_mailstats 每 10 分钟│  采集各邮箱收信时间
```

---

## 二、本仓库相对上游的增量（文件级）

| 模块 | 文件 | 作用 |
|---|---|---|
| **域名总览页** | `admin/overview.php`+`templates/overview.twig`+`js/site/overview.js`+`inc/functions.overview.inc.php` | 后台首页：域/邮箱/号池/养号一屏 |
| 总览 AJAX | `inc/ajax/overview.php` | `check_*` / `add_*` / `toggle` / `reveal_secret` |
| 审计 | `inc/functions.audit.inc.php` → `admin_audit_log` | 每个写动作留痕 |
| **号池** | `inc/functions.resend_pool.inc.php`+`inc/ajax/resend_pool.php` | Resend 多账号、域名绑定、CF 自动写 DNS |
| **养号** | `inc/functions.warmup.inc.php`+`_ext.inc.php`+`inc/ajax/warmup.php`+`crons/warmup.php` | 计划/配额/发送/投递判定/互动 |
| 凭据保管箱 | `inc/functions.secretbox.inc.php` | AES-256-GCM 可逆加密（收件人应用专用密码） |
| 投递追踪 | `inc/ajax/delivery_trace.php`+`cf-email-bridge/` | Message-ID 串起 postfix→中继→结果 |
| 邮件统计 | `data/conf/dovecot/mailstats.sh` → `mailbox_mailstats` | 各邮箱最近收信/今日收信 |
| 保留期清理 | `data/conf/phpfpm/crons/retention.php` | 三张自建表的过期清理 |
| 自建表 | `inc/init_db.inc.php` | `warmup_*` / `resend_*` / `admin_audit_log` / `overview_mbox_daily` / `mailbox_mailstats` |

---

## 三、ADR（关键决策与理由）

### ADR-001｜出站不直发，走中继（Cloudflare / Resend）
- **背景**：实测 16 个根域从本机 IP（OVH AS16276，`hosting:true`）直发 Gmail，
  **100% 进垃圾箱**，而 SPF/DKIM/DMARC 三项**全部 pass**。
- **决策**：出站优先走已验证的第三方中继。
- **理由**：认证没问题 → 问题在**出口 IP 的个体信誉**。4 种内容变体
  （纯文本/中文/HTML/带退订头）全部进垃圾箱，说明与内容无关。
- **代价**：中继有速率限制（实测遇到过 CF `451 4.7.1`），且免费额度有限。

### ADR-002｜cf-bridge 用"文件日志"而不是 docker logs
- **背景**：cf-bridge 是裸 `docker run` 部署的独立进程；php-fpm 容器**没有 docker socket**
  （这是正确的安全设计 —— 给 Web 应用 docker socket 等于给它主机 root）。
- **决策**：桥把 `RELAY OK/FAIL` 写文件，php-fpm **只读挂载**该目录。
- **理由**：Web 侧要能读链路日志，但不能因此获得主机控制权。
- **代价**：日志格式成为契约（`msgid=` 字段是串联关键），改动会破坏追踪。

### ADR-003｜养号必须"去对方邮箱核对"，不接受估算
- **背景**：Gmail 官方口径是「按域记信誉 / 量慢慢涨 / 看举报率 / **要互动**」。
  在这之前，"进箱率"没有任何可信来源，只能人工抽查。
- **决策**：收件人池从「一个地址」升级为「一个可登录的真实邮箱」，
  用其 IMAP 按 Message-ID 到收件箱与垃圾箱反查，得出真实落点；并用其 SMTP 代回信。
- **代价**：需要收件人账号的**应用专用密码**（可逆存储 → 见 ADR-004）。

### ADR-004｜收件人凭据用**可逆**加密，与登录密码区分开
- **背景**：邮箱登录密码是 bcrypt 单向哈希、**数学上不可逆**，任何界面都显示不了明文。
  但 IMAP/SMTP 必须拿到明文密码。
- **决策**：单独建「凭据保管箱」，AES-256-GCM 加密，密钥放 `data/conf/warmup/secret.key`
  （0600、不在 Web 根目录、已 gitignore）；界面只显示「是否已配置」。
- **理由**：这是两类**不同性质**的凭据，不能因为"密码不该明文存"就一刀切。
- **代价**：密钥丢失 = 已存凭据不可恢复（需重新填写），但系统仍可用。

### ADR-005｜邮件统计由 dovecot 侧采集，**不**把 vmail 卷给 Web
- **背景**：要做「每个邮箱最近收信时间 / 今日收信数」，直觉是让 Web 去读邮件。
- **已排除的两条路**：
  1. 把 vmail 卷挂进 php-fpm —— 等于把**全站邮件**暴露给 Web 应用；
  2. 用 dockerapi 执行 doveadm —— 读源码确认它**只派发预定义方法**，无任意命令执行。
- **决策**：由本来就该接触邮件的 **dovecot 容器**用 doveadm 采集**聚合结果**写进
  `mailbox_mailstats`，Web 只 `SELECT` 这张表。
- **收益**：Web 组件**始终看不到任何邮件内容**，同时字段照样能显示。

### ADR-006｜写操作串行 + 死锁重试
- **背景**：压测实测 8 个会话并发对**同一邮箱** toggle，100 次里 1 次
  `SQLSTATE[40001] 1213 Deadlock`（mailcow 的 `mailbox('edit')` 内部连续更新多张表）。
- **决策**：`ov_write()` = 按目标 `GET_LOCK` 串行 + 死锁退避重试（只对 1213/1205 重试）。
- **验证**：修复后 600 次并发写 **0 失败 0 死锁**。

### ADR-007｜首屏零网络 I/O
- **背景**：最初版本对 16 个域逐个做 RDAP（单域最长 12s），首屏 **1.80s**。
- **决策**：首屏数据**服务端内联**到 `window.__ov_initial`；所有外部查询（DNS/RDAP）
  只在「体检」动作触发后写缓存，页面**只读缓存**。
- **验证**：1.80s → **0.05s**。

### ADR-008｜空 tbody 必须放占位行
- **背景**：mailcow 的 `footer.js` 在解析阶段 `$('tbody').filter(空).remove()`，
  把「留空待 JS 填充」的表**整个删掉**，表格永久空白且无报错。
- **决策**：模板放占位行 + JS `body()` 兜底重建 + `check_empty_tbody.cjs` 回归防护。

---

## 四、黄金代码范例（照这些写）

### 4.1 写操作：串行 + 死锁重试（`inc/ajax/overview.php`）

```php
list($ok, $msg) = ov_write($pdo, 'mailbox:' . $user, function () use ($user, $new) {
  return mailbox('edit', 'mailbox', array('username' => $user, 'active' => $new));
});
if (!$ok) { /* 落审计 + 明确报错，绝不假装成功 */ }
```

> 关键：**mailcow 的 `mailbox()` 失败时不抛异常，只 `return false` 并把原因写进
> `$_SESSION['return']`**。不检查返回值就会出现「接口报成功、库里没数据」。

### 4.2 端点入口：CSRF 三态判定（所有写动作复用这套）

```php
if (in_array($action, $write_actions, true)) {
  if (METHOD !== 'POST')                  { 405 }
  if (没提交 csrf_token)                   { 403 '缺少 CSRF token' }
  if (empty($_POST))                       { 403 'CSRF 校验失败' }   // 框架只在失败时清空
}
```

### 4.3 外部数据源缓存 + 只读首屏（`inc/functions.overview.inc.php`）

```php
// 页面传 $cacheOnly = true（零网络）；只有「体检」动作传 false 去刷新
$age = ov_domain_age_days($redis, $domain, $cacheOnly);
```

### 4.4 敏感凭据（`inc/functions.secretbox.inc.php`）

```php
$enc = sb_encrypt($plain);         // 失败返回空串 —— 绝不把明文当"降级"返回
$pw  = sb_decrypt($row['secret_enc']);   // 失败返回空串 + error_log
// 界面/审计只出现「是否已配置」，永远不下发明文
```

### 4.5 表格渲染（`overview.js`）

```js
body('#ov_mbox_body', '#ov_tbl_mbox').html(rows);   // 不是 $('#ov_mbox_body')！
esc(value)                                          // 属性位也用它（已转义引号）
```

### 4.6 采集类定时任务（`data/conf/dovecot/mailstats.sh`）

```sh
MYSQL_CLIENT=$(command -v mariadb || command -v mysql)
MYSQL=("$MYSQL_CLIENT" --skip-ssl --socket=/var/run/mysqld/mysqld.sock ...)
# ↑ 必须 --skip-ssl：MariaDB 11 客户端默认要求 TLS，本地 socket 不支持，
#   而 2>/dev/null 会把它变成"静默查出 0 行"
```

---

## 五、自建数据表

| 表 | 用途 | 增长 | 清理 |
|---|---|---|---|
| `admin_audit_log` | 管理操作审计 | 每次写 +1 | 180 天（retention cron） |
| `overview_mbox_daily` | 「今日新增」的当日基线 | 每邮箱每天 +1 | 90 天 |
| `mailbox_mailstats` | 各邮箱最近收信/今日收信 | 每邮箱 1 行（REPLACE） | 随邮箱删除 |
| `resend_accounts` / `resend_domains` | 号池账号与域名绑定 | 手动 | — |
| `resend_pool_settings` | 号池设置（CF Token 等） | 键值 | — |
| `warmup_plans` | 养号计划 | 手动 | — |
| `warmup_recipients` | 收件人账号（含加密凭据） | 手动 | — |
| `warmup_log` | 养号按天汇总 | 每计划每天 +1 | 365 天 |
| `warmup_messages` | 养号逐封明细（Message-ID/落点/回复） | 每封 +1 | — |

> ⚠️ 在 `init_db.inc.php` 加表**必须同时提升 `$db_version`**，否则表永远建不出来且不报错。
