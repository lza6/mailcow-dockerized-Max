# SOP · 在本项目新增功能 / API 的标准流程

> 适用对象：任何在本仓库上加「新功能、新页面、新 API 端点、新数据库表、新定时任务」的人或 AI。
> **强制顺序：先读文档与记忆 → 判断是否过时 → 再读真实代码 → 才动手写。**
> 跳过前三步直接写代码，是本项目历史上绝大多数返工的来源。

---

## 0. 开工前必读（10 分钟，省掉几小时）

按顺序读，**不要跳**：

| 顺序 | 文件 | 为什么 |
|---|---|---|
| 1 | `workflow_status.md` | 需求追踪矩阵 + **验证台账**（哪些验证做过、什么时候才需重跑）+ 部署清单 |
| 2 | `CLAUDE.md`（项目级） | 架构、语言包约定、环境约束、Do/Don't |
| 3 | `docs/README.md` | 文档总索引 |
| 4 | 记忆目录 `…/memory/MEMORY.md` 及其下各文件 | **踩过的坑**（空 tbody、CSRF 语义、init_db 版本门控、IMAP 四个坑…） |
| 5 | 本 SOP 的 §2「已知陷阱」 | 见下方，全部是真实踩过的 |

### 判断文档是否过时

读完先做一次对照，**别默认文档是对的**：

- 打开文档里点名的每个文件，确认它**仍然存在**；
- 确认文档里引用的**函数名 / 端点 / 表名**在代码里仍能 grep 到；
- 确认 `db_version`、表结构、端点清单与代码一致。

**过时就先更新文档再开工**（在同一个提交里带上，不要留"下次再改"）。
判断依据永远是**代码**，不是文档。

---

## 1. 影响面评估（决定这条路要走多远）

| 你要改的东西 | 必须同时处理的 |
|---|---|
| 新 PHP 文件 / 改 PHP | 部署要 `docker restart php-fpm-mailcow`（`opcache.validate_timestamps=Off`） |
| 改 Twig 模板 | 同上；且**任何 JS 填表的 `<tbody>` 必须放占位行**（见 §2.1） |
| 改 JS | 同上 + `rm -f data/web/cache/*.js`（js_minifier 缓存） |
| 新增数据库表 | 改 `init_db.inc.php` 的 `$tables` **并且提升 `$db_version`**（见 §2.3） |
| 改**已有表**的列/索引 | `init_db` **不会** ALTER，必须自己写迁移 SQL 并在部署脚本里执行 |
| 改 `docker-compose.yml`（ofelia 标签 / 新挂载） | `docker compose up -d --force-recreate --no-deps <容器>`，**随后必须重启 nginx**（它缓存上游 IP） |
| 新增定时任务 | 标签挂在**承载它的容器**上（php-fpm 或 dovecot），不是 ofelia 容器上 |
| 改了 ofelia 标签后 | **必须 `--force-recreate ofelia-mailcow`**，`restart` 无效 —— ofelia **只在启动时**读容器标签注册任务，不热重载。生产实测踩过：php-fpm 在 10-13 重建、ofelia 还是 10-09 启动的，于是新增的 4 个任务（healthcheck / pool_sync / postmaster_sync / retention）**从未执行过**，日志里一条记录都没有，而页面表现为「未检测 / 未知」——看起来像功能坏了。**判定方法**：`docker logs <ofelia> \| grep <job名>` 无输出即为未注册；对照 `docker inspect <php-fpm>` 的标签与两者的 StartedAt |
| 新写操作端点 | 必须做 CSRF 判定（见 §2.2）；无参动作尤其危险 |
| 需要访问邮件存储 | **不要**把 vmail 卷挂进 php-fpm；让 dovecot 侧采集后写聚合表（见 §2.4） |

---

## 2. 已知陷阱（全部真实踩过，每条都有对应记忆文件）

### 2.1 mailcow 会删掉所有空 `<tbody>`

```js
// data/web/js/site/footer.js
$('tbody').filter(function(){ return $(this).children().length < 1; }).remove();
```

**在页面解析阶段**执行 → 空 tbody 被删 → JS 再 `.html()` 写不进去 → **整表永久空白，
且控制台无报错、响应体里元素 ID 齐全**。

- 修法：每个 `<tbody>` 放占位行；JS 侧再用 `body(sel, tableSel)` 兜底重建
- 回归防护：`node helper-scripts/check_empty_tbody.cjs`（改完模板**必跑**）

### 2.2 CSRF 语义反直觉

`sessions.inc.php`：**校验通过** → `unset($_POST['csrf_token'])` 并轮换会话 token；
**校验失败** → `$_POST = array()`。

- ❌ 不能比对 `$_POST['csrf_token']` 与会话 token —— 会话里已是轮换后的新值，
  比对必然不等 → **所有合法写请求 403**
- ✅ 判据：`$_POST` 是否被清空（框架只在失败时清），再加「客户端确实提交过 token」
- **无参动作**（不读任何 `$_POST`）必须显式校验，否则伪造 token 也能触发

### 2.3 `init_db_schema()` 是版本门控的

不改 `$db_version` → 新表**永远建不出来，且不报错**（业务侧静默写不进数据）。

- 改完必须提升 `$db_version` → 部署 → 触发一次页面请求 → **`SHOW TABLES` 逐个复核**

### 2.4 不要为了展示字段把邮件存储交给 Web

- php-fpm **没有** vmail 卷（安全设计）
- dockerapi 的 exec **只派发预定义方法**，没有任意命令执行
- 正确做法：让 dovecot 容器采集聚合结果写库，Web 只读那张表
  （见 `data/conf/dovecot/mailstats.sh`）

### 2.5 其他高频坑

| 坑 | 表现 | 做法 |
|---|---|---|
| 测试脚本用管道包住写请求 | token 在子 shell 里丢失 → 报"参数不合法"，像产品 bug | 写请求绝不放管道；输出先落文件再截断 |
| 声称"页面正常"只看响应体 | 前端根本没渲染 | 用 jsdom 加载**真实页面 + 真实 bundle** 验证渲染结果 |
| 远端 main 与本地 master 无共同祖先 | 直接 push 被拒 | `fetch max/main` → cherry-pick → 快进推送，**绝不 force push** |
| jsdom 里 `require('jquery')` 先于挂 `global.window` | 报 "jQuery requires a window with a document"，**看起来像依赖没装** | 先建 DOM、挂 `global.window/document`，**再** require jquery（见 `test-js-postmaster.mjs` 注释） |
| jsdom 里用 `$.ajaxPrefilter` 拦 `$.getJSON` | 拦下来了但 `render()` 不执行 | `$.getJSON` 的成功链在 prefilter 之后；直接**替换 `$.getJSON`**，并且要在**注入被测脚本之前**替换（脚本 ready 时会立刻自拉一次） |
| 逐日累加比例型指标 | 7 天 × 0.5% → 3.5 → 界面显示「350%」 | 比例（errorRatio 等）**按"有数据的天"求平均**，不要相加；另暴露 `days`/`max` 供判断"偶发尖峰 vs 持续问题" |
| Cloudflare 写验证类 CNAME 不给 `proxied` | 记录建得出来、面板看着正常，第三方却永远验不过（橙云把目标藏了） | 验证/签发类记录（TXT/CNAME/MX）一律**显式 `proxied: false`** |
| PHP 8 里只 `catch (Exception)` | `null->method()` / 未定义变量抛的是 **`Error`**，接不住 → 一路逃成 HTTP 500 | 新代码统一 `catch (Throwable)`；`functions.resend_pool.inc.php` 曾因此整个绑定流程崩在中间、留下脏状态 |
| 本地容器（`~/mailcow-e2e`）落后于仓库 | 测试报"文件不存在"，看着像部署缺口，其实是快照旧 | 跑测试前先 `docker compose cp` 同步改动过的文件；别把容器状态当成代码事实 |
| **Twig 会解析 HTML 注释里的定界符** | 模板直接抛 `SyntaxError: Unexpected token "end of print statement"` | 大括号成对的打印/语句定界符**在任何位置都不能出现**（含 `<!-- -->` 注释、`<script>` 正文）。写"提醒别人别写定界符"的注释时自己踩过 |
| jsdom 里提前劫持 `window.setTimeout` | ready 回调永不执行 → 事件处理器没绑上 → 点按钮毫无反应、界面全空（**看起来像被测脚本坏了**） | jQuery 的 `.ready()` 内部就用 `window.setTimeout` 兜底。**先让 ready 跑完再劫持**（见 `test-js-overview.mjs` 的 `hijackTimers()`） |
| jsdom 里 jQuery deferred 是异步的 | 触发 click 后立刻断言 → 界面还是空的 | `$.getJSON().done()` 走微/宏任务；推进可控定时器时**每轮都要先 `await` 一个真实的 tick** |
| RDAP 失败被当成"未注册" | 一次网络抖动把「未知」**固化 24 小时**；cron 侧读回后以为"已有值"不再重试 → 交叉污染永不恢复 | 缓存必须**三态**：成功 24h / 404 未注册 1h / **失败 300s + 带 error 原因**。「确定的结论」与「没查成」是两件事 |
| 比例/速率类指标在页面与 cron 各写一份实现 | 两侧语义漂移、互相污染 | 抽到 `inc/` 共用（如 `ov_domain_age_days()`）；`healthcheck.php` 曾有一份独立 RDAP curl 副本，已删除 |
| jsdom 里给 `$('#id').trigger('click')` 断言不到效果 | 以为处理器没绑 | 先确认该 id 在**真实模板**里存在（测试从 twig 抽 id，不手写 DOM），并确认按钮绑的是哪个函数 |

---

## 3. 标准实施流程

### 3.1 设计与拆解
1. 明确「输入 / 输出 / 验收标准」，写成一句话能说清的**契约**
2. 拆成可独立验收的节点；标注依赖与可并行项
3. 复杂功能在 `workflow_status.md` 里登记一行

### 3.2 编码
- **复用既有实现**：先 grep 有没有现成的函数/端点，不要重复造
  （例：号池与养号的写操作直接复用 `resend_pool.php` / `warmup.php`，不另写一套）
- 所有外部输入在边界处校验（域名/邮箱/主机名各有白名单式校验函数可复用）
- 错误处理**不许静默吞**：至少 `error_log` 带上异常类型
- 敏感值（密码、应用专用密码、密钥）绝不进日志、不进审计明细、不进前端

### 3.3 本地验证
> 本地 WSL 栈（`~/mailcow-e2e`）**会周期性自重启**，长任务易被切断；
> 功能验证放本地可以，**压测放生产**更可靠。

### 3.4 生产部署
按 §1 的影响面表执行；**先语法检查再落盘**（`php -l` / `bash -n` / `node --check`）。

**涉及凭据保管箱时必须先确认目录可写**（否则功能静默不可用）：

```bash
# php-fpm worker 以 www-data(82) 运行；绑定挂载源目录若由 docker 自动创建，
# 会是 root:root 0755 → www-data 写不了 secret.key → 所有"保存敏感凭据"全部失败。
ls -ln data/conf/warmup            # 看属主
# 正确做法（按容器里 www-data 的 uid/gid）：
chown -R 82:82 data/conf/warmup && chmod 700 data/conf/warmup
```

影响的路径：Postmaster 的 OAuth Client Secret、养号的收件人 IMAP/SMTP 密码。
界面**会明确报错**（"…加密失败，未保存：请确认 /warmup-secrets 目录可读可写"），
不会假装保存成功 —— 但功能确实不可用，所以部署时就要查。

### 3.5 验收（每条都要有真实证据）
| 验收类型 | 工具 | 必跑条件 |
|---|---|---|
| 渲染正确 | jsdom 加载真实页面 + bundle，数 `querySelectorAll('tr').length` | 改了模板/JS |
| 无假按钮 | 扫模板控件 id/class 逐个核对 JS 里有无绑定 | 改了模板/JS |
| 无空 tbody | `helper-scripts/check_empty_tbody.cjs` | 改了任何 `.twig` |
| 接口通 | curl 打真实端点，含**伪造 token→403 / GET→405 / 合法→成功** 三态 | 改了端点 |
| 数据库 | `SHOW TABLES` / `DESCRIBE` / `SHOW INDEX` 逐个复核 | 改了 `init_db` |
| 并发 | 压测脚本 | 改了写路径或首屏构建 |

### 3.6 收尾（缺一不可）
1. 更新 `workflow_status.md`（状态 + 验证台账）
2. 更新 `docs/README.md` 索引（新增文档时）
3. 有新的坑 → 写进记忆目录 + 本 SOP §2
4. 提交推送到 `main`（走 §2.5 的 cherry-pick 流程）+ 创建 Release

---

## 4. 交付前的反向自检

逐条问自己（答不上就说明还没做完）：

- [ ] 需求有没有被偷换？验收标准是**用户说的**还是我以为的？
- [ ] 链路是不是真的打通了，还是只做了单点？
- [ ] 异常路径（空值、超时、权限不足、并发）我走过一遍吗？
- [ ] 会不会破坏原有功能？回归验证做了吗？
- [ ] 新环境按文档能不能跑起来？
- [ ] 文档与真实行为一致吗？
- [ ] 有没有**没验证就宣称完成**的结论？

---

## 5. 参考：本项目已验证过的能力清单

写新功能前先看这些**已经存在**的东西，避免重复实现：

| 能力 | 位置 |
|---|---|
| 审计（谁/何时/对什么/结果） | `inc/functions.audit.inc.php` → `admin_audit_log` |
| 可逆凭据保管箱（AES-256-GCM） | `inc/functions.secretbox.inc.php` |
| 写操作串行 + 死锁重试 | `ov_write()`（`inc/ajax/overview.php`） |
| IMAP/SMTP 收件人操作 | `inc/functions.warmup_ext.inc.php` |
| 养号调度与配额 | `inc/functions.warmup.inc.php` + `crons/warmup.php` |
| 号池（Resend）与 CF 自动写 DNS | `inc/functions.resend_pool.inc.php` |
| 邮件统计采集 | `data/conf/dovecot/mailstats.sh` → `mailbox_mailstats` |
| 表格渲染兜底 | `overview.js` 的 `body()` / `esc()` / `sogoLogin()` |
| 空 tbody 检查 | `helper-scripts/check_empty_tbody.cjs` |
