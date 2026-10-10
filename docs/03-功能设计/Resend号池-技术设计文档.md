# Resend 号池 技术设计文档

> 项目：mailcow-dockerized-Max
> 功能代号：Resend 号池（Resend Account Pool）
> 文档版本：1.0
> 关联需求：见《Resend 号池 产品需求文档（PRD）》
> 最后更新：2026-10-09

---

## 一、架构概览

### 1.1 分层架构

```
┌───────────────────────────────────────────────────────────────────────┐
│                        浏览器（管理员后台）                              │
│  data/web/templates/admin/tab-config-resend-pool.twig                  │
│  ├─ 账号表 / 域名表 / 容量进度条 / 状态徽章                              │
│  └─ jQuery AJAX  →  /inc/ajax/resend_pool.php（带 csrf_token）          │
└───────────────────────────────┬───────────────────────────────────────┘
                                 │ HTTP (JSON)
┌───────────────────────────────▼───────────────────────────────────────┐
│  接口层  data/web/inc/ajax/resend_pool.php                             │
│  ├─ 鉴权：$_SESSION['mailcow_cc_role'] === 'admin' 否则 403            │
│  ├─ CSRF：写操作校验 $_SESSION['CSRF']['csrf_token']（hash_equals）    │
│  └─ action 分发（switch）                                              │
└───────────────────────────────┬───────────────────────────────────────┘
                                 │ 函数调用
┌───────────────────────────────▼───────────────────────────────────────┐
│  业务层  data/web/inc/functions.resend_pool.inc.php                    │
│  ├─ 账号：accounts / check_key / ensure_relayhost                      │
│  ├─ 分配：assign / auto_assign / remove / sync                         │
│  ├─ 外部：resend_api_call（Resend 官方 API）                           │
│  └─ 外部：resend_pool_cf_request（Cloudflare API，可选）               │
└──────┬──────────────┬───────────────┬───────────────┬─────────────────┘
       │              │               │               │
       ▼              ▼               ▼               ▼
┌───────────┐  ┌───────────┐  ┌────────────┐  ┌──────────────────┐
│  MySQL    │  │  Redis    │  │  Resend    │  │  Cloudflare(可选) │
│ mailcow   │  │ 配置缓存  │  │ api.resend │  │ api.cloudflare   │
│ 数据库    │  │           │  │  .com      │  │ .com             │
│           │  │ DOMAIN_   │  │            │  │                  │
│ resend_*  │  │ LIMIT     │  │ /domains   │  │ /zones/.../dns_  │
│ relayhosts│  │ CF_TOKEN  │  │            │  │ records          │
│ domain    │  │           │  │            │  │                  │
└───────────┘  └───────────┘  └────────────┘  └──────────────────┘
```

### 1.2 关键设计思想

1. **号池 = 多账号聚合**：把受限的单账号容量（3 域）横向扩展为「账号数 × 上限」的总容量。
2. **中继机制复用（核心）**：号池中每个账号自动对应一条 mailcow 原生 `relayhosts` 记录。域名通过既有的 `domain.relayhost` 字段挂到该记录上，从而**零改动 postfix 配置**即可让发信走指定账号。
3. **外部能力可选降级**：Cloudflare DNS 自动写入为可选增强，未配置时绑定流程照常完成，仅跳过 DNS 写入。
4. **接口与业务分离**：接口层只做鉴权/CSRF/分发；业务层集中在 `functions.resend_pool.inc.php`，便于复用与测试。

### 1.3 文件清单

| 文件 | 职责 |
|------|------|
| `data/web/inc/functions.resend_pool.inc.php` | 业务逻辑（Resend/Cloudflare 调用、容量、分配） |
| `data/web/inc/ajax/resend_pool.php` | AJAX 端点（鉴权、CSRF、action 分发） |
| `data/web/templates/admin/tab-config-resend-pool.twig` | 前端界面与交互脚本 |
| `data/web/templates/admin.twig` | 注册标签页导航与 include |
| `data/web/inc/init_db.inc.php` | 定义并创建 `resend_accounts` / `resend_domains` 表、DB 版本 |

---

## 二、数据模型

### 2.1 表结构

#### 2.1.1 `resend_accounts`（号池账号）

| 字段名 | 类型 | 含义 | 约束 / 默认 |
|--------|------|------|-------------|
| `id` | `INT` | 主键，自增 | `NOT NULL AUTO_INCREMENT`，PRIMARY KEY |
| `label` | `VARCHAR(64)` | 账号标签（界面识别用） | `NOT NULL DEFAULT ''` |
| `api_key` | `VARCHAR(128)` | Resend API Key（明文存储，仅服务端使用） | `NOT NULL`，UNIQUE KEY |
| `daily_quota` | `INT` | 每日额度（预留字段，本期未参与限流） | `NOT NULL DEFAULT 100` |
| `active` | `TINYINT(1)` | 是否启用（1 启用 / 0 停用） | `NOT NULL DEFAULT 1` |
| `created` | `DATETIME(0)` | 创建时间 | `NOT NULL DEFAULT NOW(0)` |
| `last_check` | `DATETIME(0)` | 最近一次 Key 校验时间 | `NULL DEFAULT NULL` |
| `check_status` | `VARCHAR(255)` | 最近一次 Key 校验结果文本 | `NOT NULL DEFAULT ''` |

- 索引：`PRIMARY(id)`、`KEY(api_key)`（用于去重）。
- 属性：`ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC`。

#### 2.1.2 `resend_domains`（域名 → 账号映射）

| 字段名 | 类型 | 含义 | 约束 / 默认 |
|--------|------|------|-------------|
| `id` | `INT` | 主键，自增 | `NOT NULL AUTO_INCREMENT`，PRIMARY KEY |
| `domain` | `VARCHAR(255)` | 域名 | `NOT NULL`，UNIQUE KEY |
| `account_id` | `INT` | 所属号池账号 id（逻辑外键 → `resend_accounts.id`） | `NOT NULL` |
| `resend_domain_id` | `VARCHAR(64)` | Resend 侧域名 id | `NOT NULL DEFAULT ''` |
| `status` | `VARCHAR(32)` | 验证状态（如 `not_started` / `pending` / `verified` / `failed`） | `NOT NULL DEFAULT 'not_started'` |
| `records_json` | `TEXT` | Resend 返回的 DNS 记录 JSON 缓存 | `NULL DEFAULT NULL` |
| `created` | `DATETIME(0)` | 创建时间 | `NOT NULL DEFAULT NOW(0)` |
| `verified_at` | `DATETIME(0)` | 首次验证通过时间 | `NULL DEFAULT NULL` |
| `active` | `TINYINT(1)` | 是否有效 | `NOT NULL DEFAULT 1` |

- 索引：`PRIMARY(id)`、`KEY(domain)`（用于按域名查询 / upsert）。
- 属性：`ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC`。

### 2.2 与既有表的关系

```
resend_accounts (id)
      │  1
      │
      │  N            ┌──────────────────────────────────────┐
      ▼               │ 关联键均为「账号 id」                    │
resend_domains        │ resend_domains.account_id = id        │
  (account_id)        └──────────────────────────────────────┘
      │
      │  通过「一个账号 → 一条 relayhosts」间接联动
      ▼
relayhosts (id)   ←── 由 resend_pool_ensure_relayhost() 自动创建/同步
  hostname = smtp.resend.com:587
  username = resend
  password = <该账号 API Key>
  active   = <该账号 active>
      ▲
      │  domain.relayhost = relayhosts.id
      │
domain (domain, relayhost)  ←── 由 resend_pool_assign() 改写
```

**联动说明**：

| 关系 | 键 | 触发时机 |
|------|----|----------|
| `resend_domains.account_id → resend_accounts.id` | 逻辑外键 | 绑定 / 自动分配时写入 |
| `resend_accounts.id → relayhosts.id` | 由 hostname+username 定位（非 id 直连） | 账号首次被 `assign` / `edit_account` 时 `ensure_relayhost()` |
| `domain.relayhost → relayhosts.id` | mailcow 原生字段 | 域名绑定成功时改写；移除时置 0 |

**关键约束与注意点**：

1. `relayhosts` **没有存账号 id 的列**，因此「账号 ↔ 中继记录」的对应关系**不是靠 id 直接存**，而是靠固定 `hostname='smtp.resend.com:587'` + `username='resend'` 来定位同一条记录。
2. 由此存在一个**隐含假设**：号池的所有 Resend 账号**共用同一条 relayhosts 记录**（因为 hostname+username 相同）。`ensure_relayhost()` 每次都会把该记录的 `password` 更新为**最后一次被操作账号**的 API Key。
3. `domain.relayhost` 是 `VARCHAR(255)`，默认 `'0'`（`'0'` 表示直发，即不中继）。
4. `resend_domains` 是「域名去重」表（`domain` UNIQUE），一个域名同一时刻只属于一个账号。

---

## 三、核心流程

### 3.1 添加账号流程

```
管理员          前端(Twig)          ajax端点        functions层        Resend API    MySQL
  │                │                  │                │                │           │
  │ 填 Key+标签     │                  │                │                │           │
  │───────────────▶│                  │                │                │           │
  │ 点[测试 Key]    │                  │                │                │           │
  │───────────────▶│ POST check_key   │                │                │           │
  │                │─────────────────▶│ action=check_key               │           │
  │                │                  │───────────────▶│ resend_check_key            │
  │                │                  │                │ GET /domains    │           │
  │                │                  │                │───────────────▶│           │
  │                │                  │                │◀───────────────│ 域名列表   │
  │                │                  │◀───────────────│ {ok,message,count}         │
  │                │◀─────────────────│                │                │           │
  │ 显示有效/无效    │                  │                │                │           │
  │                │                  │                │                │           │
  │ 点[添加]        │                  │                │                │           │
  │───────────────▶│ POST add_account │                │                │           │
  │                │─────────────────▶│ 鉴权+CSRF 校验  │                │           │
  │                │                  │ action=add_account              │           │
  │                │                  │ 1. Key 非空?     │                │           │
  │                │                  │ 2. 以 re_ 开头?  │                │           │
  │                │                  │───────────────▶│ resend_check_key            │
  │                │                  │                │ GET /domains ──▶│           │
  │                │                  │                │◀─────────────── │           │
  │                │                  │ 3. 已存在?      │                │           │
  │                │                  │───────────────▶│ SELECT ... WHERE api_key    │
  │                │                  │                │                │───────▶ 查 │
  │                │                  │ 4. INSERT       │                │           │
  │                │                  │───────────────▶│ INSERT resend_accounts ──▶ 写 │
  │                │◀─────────────────│ {ok:true,message}              │           │
  │ 刷新列表        │                  │                │                │           │
```

**要点**：

- 校验顺序：非空 → `re_` 前缀 → Resend `GET /domains` 有效性 → 库内去重 → INSERT。
- 标签为空时自动生成 `账号 MMdd-HHmmss`。
- 添加成功后**不会**立即创建 relayhosts 记录；relayhosts 在**首次绑定域名**或**编辑账号**时才创建（由 `ensure_relayhost()` 负责）。

### 3.2 自动分配流程（含容量计算逻辑）

```
家长           ajax端点              functions层                     MySQL           Resend
 │               │                     │                             │               │
 │ 点[自动分配全部域名]                  │                             │               │
 │──────────────▶│ action=auto_assign  │                             │               │
 │               │ domains 为空?→读所有启用域名                        │               │
 │               │───────────────────────────────────────────────────▶│ domain 表      │
 │               │◀───────────────────────────────────────────────────│ [15 个域]      │
 │               │ resend_pool_auto_assign(domains)                   │               │
 │               │────────────────────▶│                             │               │
 │               │                     │ ① 取启用账号(active=1)        │               │
 │               │                     │   读账号列表                 │               │
 │               │                     │────────────────────────────▶│ resend_accounts│
 │               │                     │ ② 统计各账号已用域名数        │               │
 │               │                     │    SELECT account_id,COUNT(*)│               │
 │               │                     │    FROM resend_domains       │               │
 │               │                     │    WHERE active=1 GROUP BY   │               │
 │               │                     │────────────────────────────▶│               │
 │               │                     │ ③ 剩余容量 cap[id]           │               │
 │               │                     │      = limit - used[id]      │               │
 │               │                     │                             │               │
 │               │                     │  ┌── 对每个待分配域名 ──┐      │               │
 │               │                     │  │ 选 cap 最大的账号    │      │               │
 │               │                     │  │ 若最大 cap ≤ 0 → 失败 │      │               │
 │               │                     │  │ 否则 assign(域名,账号)│──────┼──▶ POST /domains│
 │               │                     │  │ 成功则 cap[best]--    │      │               │
 │               │                     │  └──────────────────────┘      │               │
 │               │◀────────────────────│ {ok,assigned[],failed[],message}             │
 │ 弹窗列出成功/失败 │                     │                             │               │
```

**容量计算逻辑（贪心）**：

```text
limit      = 每账号域名上限（redis RESEND_POOL_DOMAIN_LIMIT，默认 3）
active_acc = 所有 active=1 的账号
used[id]   = 该账号当前 active=1 的 resend_domains 计数（一次 GROUP BY 查询得到）
cap[id]    = limit - used[id]

对每个待分配域名 d：
    best = argmax(cap[id])        # 剩余容量最大的账号
    if best 不存在 或 cap[best] ≤ 0:
        failed += d（所有账号已满）
        continue
    res = assign(d, best)          # 走完整绑定流程（含 Resend 建域、写 DNS、改 relayhost）
    if res.ok:
        cap[best] -= 1             # 分配成功才扣减本地剩余容量
        assigned += d → best
    else:
        failed += d（原因）
```

**要点**：

- 分配以「剩余容量最大」为准，天然把负载摊平到各账号。
- `cap` 在内存中维护，`assign` 成功后立即扣减，避免把超出上限的域名分到同一账号。
- 单个域名 `assign` 失败不影响后续域名，失败项计入 `failed`。
- 每次 `assign` 会调用外部 Resend API，`N` 个域即 `N` 次（含验证），耗时随域名数线性增长。

### 3.3 域名绑定流程（含 DNS 自动写入）

```
家长           ajax端点           functions层              Resend API     Cloudflare    MySQL
 │               │                  │                        │              │           │
 │ 填域名+选账号   │                  │                        │              │           │
 │ 点[绑定]        │                  │                        │              │           │
 │──────────────▶│ action=assign    │                        │              │           │
 │               │─────────────────▶│ resend_pool_assign(d,a)│              │           │
 │               │                  │                        │              │           │
 │               │                  │ ── 前置校验 ──          │              │           │
 │               │                  │ 域名格式 is_valid_domain_name?          │           │
 │               │                  │ 账号存在? 账号 active=1? │              │           │
 │               │                  │ 容量未满? (COUNT active) │──────────────┼──────────▶│
 │               │                  │                        │              │           │
 │               │                  │ ① POST /domains {name} │              │           │
 │               │                  │───────────────────────▶│              │           │
 │               │                  │  成功→取 id+records     │              │           │
 │               │                  │  失败→GET /domains 找现有│             │           │
 │               │                  │      → GET /domains/{id}│              │           │
 │               │                  │                        │              │           │
 │               │                  │ ② resend_pool_write_dns(domain,records)│           │
 │               │                  │   （redis 有 CF_TOKEN? 无→ skip）        │           │
 │               │                  │  zone_id = 逐级根域匹配  │              │           │
 │               │                  │───────────────────────────────────────▶│ GET /zones│
 │               │                  │  每条记录：GET 查存在 → PATCH / POST      │           │
 │               │                  │───────────────────────────────────────▶│ DNS 写入  │
 │               │                  │                        │              │           │
 │               │                  │ ③ POST /domains/{id}/verify            │           │
 │               │                  │───────────────────────▶│              │           │
 │               │                  │ ④ GET /domains/{id} 取最新 status/records│         │
 │               │                  │───────────────────────▶│              │           │
 │               │                  │                        │              │           │
 │               │                  │ ⑤ upsert resend_domains│              │           │
 │               │                  │──────────────────────────────────────────────────▶ │
 │               │                  │ ⑥ ensure_relayhost(账号) → relayhosts.id         │
 │               │                  │──────────────────────────────────────────────────▶ │
 │               │                  │ ⑦ UPDATE domain SET relayhost=:id WHERE domain=:d │
 │               │                  │──────────────────────────────────────────────────▶ │
 │               │◀─────────────────│ {ok,domain_id,status,dns}│             │           │
 │ 弹窗提示结果    │                  │                        │              │           │
```

**Resend 返回的 DNS 记录写入规则**：

| 记录 | 处理 |
|------|------|
| `name` 为空或为 `@` | 展开为根域名 `domain` |
| `name` 非空 | 展开为 `name + '.' + domain` |
| `type` | 大写化（如 `TXT`、`CNAME`、`MX`） |
| `value` | 作为 `content` |
| `priority` | 存在时写为 `priority`（整数，用于 MX） |
| 非 CNAME | 附带 `proxied=false`（避免 TLS/代理干扰邮件记录） |
| `ttl` | 固定 300 |
| 已存在同 `type`+`name` 记录 | PATCH 更新；否则 POST 新建 |

**要点**：

- **upsert**：`resend_domains` 按 `domain` 唯一键 upsert（存在则更新 `account_id`/`resend_domain_id`/`status`/`records_json` 并置 `active=1`）。
- `resend_pool_write_dns()` 在**未配置 CF Token 或无记录**时直接返回 `{written:0, skipped:true, reason:...}`，不抛错，绑定流程继续。
- 绑定最后一步才改 `domain.relayhost`，保证「域名已成功挂到 Resend 账号」后再切中继。

### 3.4 移除流程

```
家长          ajax端点            functions层                    MySQL          Resend API
 │              │                  │                              │               │
 │ 点[移除]并确认 │                  │                              │               │
 │─────────────▶│ action=remove_domain                            │               │
 │              │─────────────────▶│ resend_pool_remove(domain)   │               │
 │              │                  │ 查 resend_domains JOIN 账号 Key│               │
 │              │                  │────────────────────────────▶ │               │
 │              │                  │ 未找到 → {ok:false,'不在号池中'}│               │
 │              │                  │                              │               │
 │              │                  │ ① DELETE /domains/{resend_id}│               │
 │              │                  │─────────────────────────────────────────────▶│
 │              │                  │ ② UPDATE domain SET relayhost=0（还原直发）    │
 │              │                  │────────────────────────────▶ │               │
 │              │                  │ ③ DELETE FROM resend_domains WHERE domain     │
 │              │                  │────────────────────────────▶ │               │
 │              │◀─────────────────│ {ok,message}                 │               │
 │ 刷新列表       │                  │                              │               │
```

**要点**：

- Resend 侧删除失败**不阻断**本地清理：失败信息拼入返回 `message`，本地仍解绑并删除映射（避免"删不干净"）。
- 移除后 `domain.relayhost = 0`，该域还原为 mailcow 直发。

### 3.5 同步验证状态流程

```
家长          ajax端点          functions层                          MySQL      Resend API
 │              │                │                                    │           │
 │ 点[同步验证状态]│                │                                    │           │
 │─────────────▶│ action=sync    │ resend_pool_sync()                 │           │
 │              │───────────────▶│ ① 取所有 resend_domains JOIN Key   │           │
 │              │                │──────────────────────────────────▶ │           │
 │              │                │ 对每条：GET /domains/{id}           │           │
 │              │                │──────────────────────────────────────────────▶│
 │              │                │  成功→UPDATE status/records_json,   │           │
 │              │                │        若 verified→verified_at=NOW() │           │
 │              │                │──────────────────────────────────▶ │           │
 │              │                │ ② 对每个账号：resend_check_key()    │           │
 │              │                │    UPDATE last_check,check_status  │           │
 │              │                │──────────────────────────────────▶ │           │
 │              │◀───────────────│ {ok,updated,errors[]}              │           │
 │ 提示更新数量   │                │                                    │           │
```

**要点**：

- `verified_at` 仅在状态首次变为 `verified` 时写入（`IF(:s2='verified', NOW(), verified_at)`），不覆盖已有时间。
- 单域名拉取失败计入 `errors[]`，不中断整体同步。

---

## 四、API 接口

**端点**：`/inc/ajax/resend_pool.php`（`data/web/inc/ajax/resend_pool.php`）

**通用约定**：

- 请求方式：写操作用 `POST`，`list` 与 `records` 用 `GET`。
- 响应：`Content-Type: application/json; charset=utf-8`，统一信封 `{"ok": bool, ...}`。
- 鉴权：所有 action 均要求 `$_SESSION['mailcow_cc_role'] === 'admin'`，否则 `403 {"ok":false,"message":"access denied"}`。
- CSRF：写操作需在 `POST` 中携带 `csrf_token`，校验失败返回 `403 {"ok":false,"message":"CSRF token invalid"}`。
- 未知 action → `400 {"ok":false,"message":"unknown action"}`。
- 未捕获异常 → `500 {"ok":false,"message":"服务器错误: ..."}`。

**写操作 action 集合**（需 CSRF）：
`add_account`、`edit_account`、`delete_account`、`assign`、`auto_assign`、`remove_domain`、`set_limit`、`set_cf_token`、`sync`。

### 4.1 `list`（读）

| 项 | 内容 |
|----|------|
| 方法 | `GET` |
| 参数 | 无 |
| 权限 | 管理员 |
| 说明 | 一次返回账号（脱敏）、域名映射、上限、CF 是否已配置 |
| 返回 | `{ok:true, accounts:[...], domains:[...], limit:int, cf_configured:bool}` |

### 4.2 `check_key`（读）

| 项 | 内容 |
|----|------|
| 方法 | `POST` |
| 参数 | `api_key` (string，必填) |
| 权限 | 管理员 |
| 说明 | 用 `GET /domains` 校验 Key；不落库 |
| 返回 | `{ok:bool, message:string, count:int}` |
| 错误 | Key 为空 → `{ok:false, message:'API Key 不能为空'}` |

### 4.3 `add_account`（写，需 CSRF）

| 项 | 内容 |
|----|------|
| 方法 | `POST` |
| 参数 | `api_key` (string，必填)、`label` (string，可选) |
| 说明 | 校验非空 → 以 `re_` 开头 → Resend 有效性 → 去重 → INSERT |
| 返回 | `{ok:true, message:'账号「X」已添加（...）'}` |
| 错误 | 空 Key / 非 `re_` 前缀 / Key 校验失败（提示需 Full access）/ Key 已存在 |

### 4.4 `edit_account`（写，需 CSRF）

| 项 | 内容 |
|----|------|
| 方法 | `POST` |
| 参数 | `id` (int，必填)、`label` (string，可选)、`active` (0/1，可选) |
| 说明 | 更新标签 / 启用状态；随后 `ensure_relayhost()` 同步中继记录 |
| 返回 | `{ok:true, message:'已更新'}` |
| 错误 | `id<=0` → `参数错误`；无可改字段 → `没有要修改的内容` |

### 4.5 `delete_account`（写，需 CSRF）

| 项 | 内容 |
|----|------|
| 方法 | `POST` |
| 参数 | `id` (int，必填) |
| 说明 | 仅当该账号无 `active=1` 的域名时可删 |
| 返回 | `{ok:true, message:'账号已删除'}` |
| 错误 | `id<=0` → `参数错误`；仍绑域 → `该账号仍绑定域名，请先移除域名或改用其他账号` |

### 4.6 `assign`（写，需 CSRF）

| 项 | 内容 |
|----|------|
| 方法 | `POST` |
| 参数 | `domain` (string，必填)、`account_id` (int，必填) |
| 说明 | 手动绑定域名到指定账号（完整绑定流程，见 3.3） |
| 返回 | `{ok:true, message, domain_id, status, dns}` |
| 错误 | 参数错误 / `域名格式无效` / `账号不存在` / `账号已停用` / `该账号已达域名上限（N）...` / `Resend 侧创建失败: ...` |

### 4.7 `auto_assign`（写，需 CSRF）

| 项 | 内容 |
|----|------|
| 方法 | `POST` |
| 参数 | `domains` (array<string>，可选；为空则取 mailcow 全部 `active=1` 域名) |
| 说明 | 贪心自动分配（见 3.2） |
| 返回 | `{ok:true, assigned:[], failed:[], message:'分配完成：成功 N，失败 M'}` |
| 错误 | 无启用账号 → `号池中没有启用的账号`；无域名 → `没有可分配的域名` |

### 4.8 `remove_domain`（写，需 CSRF）

| 项 | 内容 |
|----|------|
| 方法 | `POST` |
| 参数 | `domain` (string，必填) |
| 说明 | Resend 删域 + 解除 mailcow 中继 + 删除本地映射（见 3.4） |
| 返回 | `{ok:true, message}` |
| 错误 | 参数错误 / `域名不在号池中` |

### 4.9 `set_limit`（写，需 CSRF）

| 项 | 内容 |
|----|------|
| 方法 | `POST` |
| 参数 | `limit` (int，可选，默认 3) |
| 说明 | 设置每账号域名上限，写入 redis `RESEND_POOL_DOMAIN_LIMIT`，值被夹取到 `[1,1000]` |
| 返回 | `{ok:true, message:'域名上限已设为 N', limit:int}` |

### 4.10 `set_cf_token`（写，需 CSRF）

| 项 | 内容 |
|----|------|
| 方法 | `POST` |
| 参数 | `cf_token` (string；空串表示清除) |
| 说明 | 保存 / 清除 redis `RESEND_POOL_CF_TOKEN` |
| 返回 | 保存：`{ok:true, message:'Cloudflare Token 已保存'}`；清除：`{ok:true, message:'已清除 Cloudflare Token'}` |

### 4.11 `sync`（写，需 CSRF）

| 项 | 内容 |
|----|------|
| 方法 | `POST` |
| 参数 | 无 |
| 说明 | 拉取所有域名验证状态 + 复核所有账号 Key（见 3.5） |
| 返回 | `{ok:true, updated:int, errors:[]}` |

### 4.12 `records`（读）

| 项 | 内容 |
|----|------|
| 方法 | `GET` |
| 参数 | `domain` (string，必填) |
| 说明 | 读取本地缓存的 DNS 记录与状态 |
| 返回 | `{ok:true, status, records:[...]}` |
| 错误 | `{ok:false, message:'未找到'}` |

### 4.13 错误码汇总

| HTTP | 场景 | 响应 |
|------|------|------|
| `200` | 正常（含业务失败，`ok:false`） | `{ok:bool,...}` |
| `400` | 未知 action | `{ok:false, message:'unknown action'}` |
| `403` | 非管理员 | `{ok:false, message:'access denied'}` |
| `403` | CSRF 校验失败 | `{ok:false, message:'CSRF token invalid'}` |
| `500` | 未捕获异常 | `{ok:false, message:'服务器错误: ...'}` |

> 说明：业务级失败（如「账号已停用」）走 HTTP 200 + `ok:false`，仅鉴权/CSRF/系统异常使用 4xx/5xx。

---

## 五、关键实现细节

### 5.1 为什么复用 `relayhosts` 表

**问题**：如何让「某个域名」的出站邮件「走某个特定的 Resend 账号」？

**可选方案对比**：

| 方案 | 做法 | 代价 |
|------|------|------|
| A. 改 postfix 配置 | 手工写 `sender_dependent_relayhost_maps`、transport、sasl 密码映射 | 侵入配置、多账号密码管理复杂、升级易冲突 |
| B. 引入 transports 表 | 用 mailcow 的 `transports` 表（`destination/nexthop/username/password`） | 需要额外理解 transports 语义，且与 relayhosts 语义重叠 |
| **C. 复用 `relayhosts` + `domain.relayhost`（本方案）** | 每个账号对应一条 relayhosts 记录，域名挂 `relayhost` | **零改 postfix**，复用 mailcow 原生 UI/机制 |

**选择 C 的理由**：

1. `domain.relayhost` 是 mailcow **原生**支持的字段（`VARCHAR(255) DEFAULT '0'`），设置为某 relayhosts 记录 id 即让该域走该中继——这是 mailcow 既有能力，无需新逻辑。
2. relayhosts 记录本身也是 mailcow 原生管理对象（「配置 → 中继主机」），运维认知成本低。
3. 不触碰 `data/conf/postfix/**`，升级、回滚都安全。
4. 中继密码（API Key）由 mailcow 既有机制注入 postfix，加密/权限沿用现网。

**已知取舍（重要）**：

- 由于 `ensure_relayhost()` 用**固定 `hostname` + `username='resend'`** 定位记录，号池的**所有 Resend 账号实际复用同一条 relayhosts 记录**，其 `password` 会被**最后操作**的账号覆盖。
- 这意味着**多账号在 postfix 层面的密码区分度依赖最后写入的 Key**；要严格做到「不同账号用不同 Key」，需让每个账号使用**彼此独立**的 `hostname` 或 `username`（例如 `username = resend#<id>`），或按账号创建独立 relayhosts 记录并在 `domain.relayhost` 中指向各自 id。
- 当前实现适用于「同一 Resend 账号/同一 Key 覆盖多域名」为主的场景；多账号真正负载分担时，建议按 `resend#<id>` 区分记录（见第八章演进项）。

### 5.2 容量控制逻辑

| 层 | 位置 | 作用 |
|----|------|------|
| 全局上限 | redis `RESEND_POOL_DOMAIN_LIMIT` ← `resend_pool_domain_limit()` | 单账号可承载域名数，默认 3，范围 1–1000 |
| 账号已用 | SQL `SELECT account_id, COUNT(*) FROM resend_domains WHERE active=1 GROUP BY account_id` | 统计各账号实际承载 |
| 单次绑定校验 | `resend_pool_assign()`：`COUNT(*) ... AND domain <> :d` | 绑定前判是否已满（排除自身，支持重绑幂等） |
| 自动分配 | `resend_pool_auto_assign()`：内存 `cap[id] = limit - used[id]` 贪心递减 | 批量分配保持不超限 |

- 上限调整即时生效（读 redis，无缓存延迟）。
- 容量只统计 `active=1` 的域名，停用/移除的域名不计入占用。

### 5.3 Cloudflare DNS 自动写入

**Token 存储**：redis `RESEND_POOL_CF_TOKEN`（不落库）。

**Zone 定位**（`resend_pool_cf_zone_id`）：对域名逐级去前缀，从最左开始尝试作为根域查询 `/zones?name=<candidate>`，命中即返回 zone id。例如 `mail.a.example.com` 会依次试 `mail.a.example.com` → `a.example.com` → `example.com`。

**记录写入**（`resend_pool_write_dns`）：

```text
若无 CF Token 或 records 为空 → 直接返回 {written:0, skipped:true, reason}
否则：
  zone_id = cf_zone_id(token, domain)
  若 zone_id 为空 → {written:0, skipped:true, reason:'Cloudflare 中找不到该域名'}
  对每条 record：
    full_name = (name 为空/'@') ? domain : name + '.' + domain
    body = {type, name:full_name, content:value, ttl:300}
    if priority 存在: body.priority = int(priority)
    if type != 'CNAME': body.proxied = false
    查 GET /zones/{zone}/dns_records?type=..&name=..
    存在 → PATCH .../dns_records/{id}
    不存在 → POST .../dns_records
    成功 written++，失败记入 failed[]
  返回 {written, total, failed[], skipped:false}
```

**要点**：写前先查（幂等 upsert），失败项不抛异常而是汇总返回，保证即便部分记录写入失败也不影响本地绑定。

### 5.4 错误处理策略

| 场景 | 策略 |
|------|------|
| curl 失败 / 超时 | `resend_api_call` 返回 `{ok:false, http:0, error:<curl_error>}` |
| HTTP 非 2xx | 提取 `message` 或 `error.message` 作为 error 文本 |
| 绑定过程外部失败 | 返回 `{ok:false, message}`，界面弹窗提示，不写坏数据 |
| Cloudflare 未配置 | 优雅跳过（`skipped:true`），绑定照常成功 |
| Resend 删域失败 | 不阻断本地清理，失败原因拼入 message |
| 单域名同步失败 | 计入 `errors[]`，不中断整体 |
| 接口层未捕获异常 | 顶层 `try/catch`，返回 `500 {"ok":false,"message":"服务器错误: ..."}` |
| 前端 | `esc()` 转义所有动态文本，防 XSS；操作失败用 alert / 行内提示 |

---

## 六、安全性设计

| 维度 | 措施 | 代码位置 |
|------|------|----------|
| 鉴权 | 仅 `mailcow_cc_role === 'admin'` 可访问，否则 403 | `ajax/resend_pool.php:14-18` |
| CSRF | 写操作校验 `csrf_token`，`hash_equals` 恒定时间比较 | `ajax/resend_pool.php:23-31` |
| API Key 掩码 | 默认返回 `api_key_masked`（前 8 + `…` + 后 4），不返回明文 | `functions.resend_pool.inc.php:134-138` |
| 注入防护 | 全部 SQL 使用 PDO 预处理（`:param` 绑定），无字符串拼接 | 全部 `$pdo->prepare/execute` |
| 密钥存储 | API Key 存 MySQL（服务端），CF Token / 上限存 redis | `set_cf_token` / `set_limit` |
| 密钥不回传 | `list` 不含明文 Key；CF Token 前端仅显示「已配置/未配置」布尔状态 | `ajax/resend_pool.php:48` |
| 输出转义 | 前端 `esc()` 对 `& < > " '` 全转义 | twig 内 `esc()` |
| 权限最小化 | Cloudflare Token 要求仅 `Zone → DNS → Edit` | twig 说明文案 |
| 错误信息 | 不返回堆栈或敏感数据；外部错误取 message 文本 | `resend_api_call` |
| 域名校验 | 绑定时 `is_valid_domain_name()` | `functions.resend_pool.inc.php:201` |

**待确认的安全加固项**：

- API Key 在库中为**明文**存储（`api_key VARCHAR(128)`）。mailcow 现网对 relayhosts 密码亦为明文注入 postfix，故沿用了同口径；如需更高标准可引入对称加密（见第八章）。
- 服务器端未对 `check_key` / `assign` 等做速率限制，当前依赖「仅管理员 + 单人操作」场景。

---

## 七、部署与验证

### 7.1 部署步骤

```bash
# 1. 更新代码（新增 3 个文件 + init_db/admin.twig 改动）
#    functions.resend_pool.inc.php / ajax/resend_pool.php / tab-config-resend-pool.twig

# 2. 重启 php-fpm（新表由 init_db 在容器启动时自动创建）
docker compose restart php-fpm-mailcow

# 3. （可选）如需 DNS 自动写入，登录后台录入 Cloudflare Token
```

- 语言包无关，无需额外语言文件改动。
- 新表随 mailcow 数据库初始化流程自动创建，DB 版本更新为 `09102026_2100`。

### 7.2 实测结果（本地栈）

| 验证项 | 方法 | 结果 |
|--------|------|------|
| 新表自动创建 | 起栈后查库结构 | ☑ `resend_accounts` / `resend_domains` 创建成功 |
| DB 版本 | 查 `versions` 表 | ☑ 更新为 `09102026_2100` |
| 真实登录 | HTTP 登录请求 | ☑ 302 跳转 |
| 接口正常返回 | 登录态调用接口 | ☑ `{"ok":true,...}` |
| 未授权访问 | 无会话调用接口 | ☑ 403 |
| 系统页渲染 | 访问 `/admin/system` | ☑ 渲染 247KB，控件齐全 |

### 7.3 实测结果（线上 mail.pony-it.com）

| 验证项 | 结果 |
|--------|------|
| 站点访问 | ☑ 200 |
| 未登录接口 | ☑ 403 |
| 管理员登录 | ☑ 302 |
| 登录后接口 | ☑ 200 |
| `/admin/system` 渲染 | ☑ 283KB |
| 容器状态 | ☑ 18 个容器全部 Up |

> 说明：以上为已执行并观察到的结果。「绑定/自动分配在真实 Resend 账号上的端到端效果」「Cloudflare Token 自动写入」需具备对应凭据，属未覆盖项，参见 PRD 验收标准表中标注「待验证」的条目。

---

## 八、已知限制与后续演进

### 8.1 已知限制

| 编号 | 限制 | 影响 |
|------|------|------|
| L-01 | 号池所有账号复用同一条 relayhosts 记录（同 hostname+username），`password` 被最后操作账号覆盖 | 多账号在 postfix 层密码区分不足，可能无法真正"一账号一 Key"出站 |
| L-02 | API Key 明文存储于数据库 | 库泄露即 Key 泄露 |
| L-03 | 仅支持 Cloudflare 作为 DNS 服务商 | 其他 DNS 服务商需手工写入 |
| L-04 | `daily_quota` 字段预留但未参与实时限流 | 无法按日额度动态调度账号 |
| L-05 | 自动分配为贪心，不考虑账号健康度 / 信誉 | 可能把域名分到状态较差的账号 |
| L-06 | 无并发锁 | 多人同时操作同一账号容量可能有竞态 |
| L-07 | 无操作审计日志 | 难以追溯谁在何时改了绑定 |
| L-08 | 无速率限制 | 依赖"仅管理员、单人"假设 |
| L-09 | 绑定即时性依赖 DNS 传播，验证可能滞后 | 状态需经「同步」刷新 |

### 8.2 后续演进建议

| 优先级 | 演进项 | 说明 |
|--------|--------|------|
| P1 | **每账号独立 relayhosts 记录** | 用 `username = resend#<id>`（或独立 hostname）区分，`domain.relayhost` 指向对应记录 id，彻底解决 L-01 |
| P1 | API Key 加密存储 | 对称加密 + 启动校验，缓解 L-02 |
| P2 | DNS 服务商适配层 | 抽象 `DnsProvider` 接口，扩展 Cloudflare 之外的实现（L-03） |
| P2 | 额度感知分配 | 结合 Resend 额度与 `daily_quota` 做限流与调度（L-04） |
| P2 | 分配策略可插拔 | 支持「健康度优先」「轮询」等策略（L-05） |
| P3 | 操作审计日志 | 记录账号/绑定的增删改（L-07） |
| P3 | 并发控制 | 行锁或乐观锁（L-06） |
| P3 | 速率限制 | 对写接口限流（L-08） |
| P3 | 域名自动移除死链 | 账号 Key 失效时批量标记受影响域名 |

### 8.3 小结

本设计以「复用 mailcow 原生 relayhosts + domain.relayhost 机制」为核心，用最小侵入实现了 Resend 多账号号池的容量扩展、自动分配、验证状态管理与可选 DNS 自动化。已验证新表创建、鉴权、接口与页面在本地与线上均可用；剩余未覆盖项集中在需要真实 Resend / Cloudflare 凭据的端到端环节，以及多账号密码区分（L-01）这一需在下一版针对性解决的设计取舍。
