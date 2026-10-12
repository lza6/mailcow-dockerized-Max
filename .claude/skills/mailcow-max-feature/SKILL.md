---
name: mailcow-max-feature
description: Use when adding or changing anything in the mailcow-dockerized-Max repo — new admin page, AJAX endpoint, database table, ofelia cron job, warmup/relay feature, or any change to data/web/**, data/conf/**, data/Dockerfiles/** or docker-compose.yml. Enforces the "read docs + memory first, judge staleness, then read real code, then code" order, applies the project's known traps (empty tbody removal, CSRF semantics, init_db version gate, mail-store isolation, **Dockerfiles changes not being applied without build:**, JS-vs-Twig lang key levels), and requires evidence-based acceptance before claiming done.
---

# mailcow-dockerized-Max · 功能开发工作流

本仓库是 mailcow 的中文魔改版，已在**生产**运行（`mail.pony-it.com`）。
下面这套顺序不是形式主义 —— 本项目历史上绝大多数返工，都来自「跳过前两步直接写代码」。

---

## 第 0 步：先读，不要先写（强制）

按顺序读这些，**读完再动手**：

1. `workflow_status.md` — 需求追踪矩阵、**验证台账**（哪些验证做过、何时才需重跑）、部署清单
2. `CLAUDE.md`（项目级）— 架构、语言包约定、环境约束、Do/Don't
3. `docs/README.md` — 文档总索引
4. 记忆目录（`…/projects/…-mailcow-dockerized/memory/`）的 `MEMORY.md` 及各文件 — 踩过的坑
5. `docs/04-运维与工程/SOP-新增功能与API.md` — 本工作流的完整版

## 第 1 步：判断文档是否过时（不要默认文档对）

- 文档点名的文件**是否仍存在**？引用的函数名/端点/表名**能否 grep 到**？
- `db_version`、表结构、端点清单与代码是否一致？
- **判断依据永远是代码，不是文档。** 过时就先更新文档再开工（同一提交里带上）。

## 第 2 步：判断影响面 → 决定要做什么

| 改了 | 必须同时做 |
|---|---|
| PHP / Twig / JS | `docker restart php-fpm-mailcow`（`opcache.validate_timestamps=Off`） |
| JS | 另加 `rm -f data/web/cache/*.js`（js_minifier 缓存） |
| 任何 `.twig` | 每个 JS 填表的 `<tbody>` **必须放占位行**；跑 `node helper-scripts/check_empty_tbody.cjs` |
| 新增表 | 改 `$tables` **并且提升 `$db_version`**，部署后 `SHOW TABLES` 复核 |
| 改已有表的列/索引 | `init_db` 不会 ALTER，自己写迁移 SQL 并在部署脚本里跑 |
| `docker-compose.yml` | `up -d --force-recreate --no-deps <容器>`，**随后重启 nginx**（缓存上游 IP） |
| `data/Dockerfiles/**` | ⚠️ **默认根本不生效** —— compose 全是 `image: ghcr.io/...`、无 `build:` 段。要给该服务加 `build:` 再 `docker compose build <svc>` + 重建容器；`restart`/挂载都没用。Windows 检出上构建前先把行尾归一到 LF（否则 `#!/bin/bash\r` → exit 127） |
| `data/web/inc/dns_parallel.inc.php` | 无外部依赖，但改了解析逻辑要跑 `.github/scripts/test-dns-parse.php` |
| 新定时任务 | 标签挂在**承载它的容器**上（php-fpm 或 dovecot），不是 ofelia |
| 新写端点 | 显式 CSRF 判定（见下）；无参动作尤其危险 |

## 第 3 步：编码

- **先 grep 有没有现成实现**，复用优先（号池/养号的写操作就是直接复用既有端点）
- 边界处校验所有外部输入（域名/邮箱/主机名都有现成白名单校验函数）
- 异常**不许静默吞**：至少 `error_log` 带异常类型
- 敏感值（密码、应用专用密码、密钥）**不进日志、不进审计明细、不进前端**

## 第 4 步：验证（要证据，不要"应该可以"）

**先跑测试套件**（都可从仓库根直接调用、零第三方依赖）：

```bash
php .github/scripts/test-runner.php              # 语言包结构 / 回退 / 安全
php .github/scripts/test-domain-health.php       # 域名健康纯函数（60 断言）
php .github/scripts/test-render-domain-health.php# 真实 Twig 渲染 × 3 语言（26 断言）
php .github/scripts/test-dns-parse.php           # 并发 DNS 报文解析，离线（32 断言）
php .github/scripts/test-lang-fallback.php       # 语言包运行期回退
python3 helper-scripts/cf-email-bridge/test_bridge.py   # 桥接（24 项）
node .github/scripts/check-lang.cjs              # 语言包缺键
node .github/scripts/check-placeholders.cjs      # 占位符/标签一致性
```

这些**已经进 CI**，所以本地跑通 ≈ CI 会绿。除此之外按改动类型补：

| 验收 | 工具 | 何时必跑 |
|---|---|---|
| 渲染正确 | jsdom 加载**真实页面 + 真实 bundle**，数 `querySelectorAll('tr').length`；模板类改动至少跑上面的 Twig 渲染测试 | 改模板/JS |
| 无假按钮 | 扫模板控件 id/class，逐个核对 JS 有无绑定（**脚本尚未沉淀，目前需人工**） | 改模板/JS |
| 无空 tbody | `helper-scripts/check_empty_tbody.cjs` | 改任何 `.twig` |
| 端点三态 | 伪造 token→**403** / GET→**405** / 合法→**成功**；外加「依赖不可用时降级」——用桩把 `$redis` 置 null 跑一遍端点 | 改端点 |
| 数据库 | `SHOW TABLES` / `DESCRIBE` / `SHOW INDEX` | 改 `init_db` |
| 并发 | 压测脚本 | 改写路径或首屏构建 |
| 改了 `data/Dockerfiles/**` | 比对**运行时产物**（`docker exec <c> md5sum /opt/xxx.sh`），不是比对源码 | 任何 Dockerfiles 改动 |

> **只看响应体不算验证。** 前端功能必须验证**渲染后的结果** ——
> 两者之间隔着一整个 JS 运行时（本项目为此空白过两次）。
>
> **只看源码不算验证。** 改 `data/Dockerfiles/**` 时必须确认它到底有没有进容器
> （默认没有，见第 2 步）。

## 第 5 步：收尾（缺一不可）

1. 更新 `workflow_status.md`（状态 + 验证台账）
2. 更新 `docs/README.md` 索引
3. 新的坑 → 写进记忆目录 + SOP §2
4. 推送到 `main`：`fetch max/main` → `cherry-pick` → 快进推送（**远端与本地无共同祖先，绝不 force push**）+ 建 Release

---

## 四个最高频的坑（先看这四条）

1. **空 `<tbody>` 会被 mailcow 的 `footer.js` 删掉** → 表格永久空白、**无报错**、响应体正常
2. **CSRF**：框架校验通过后会 `unset` 字段并轮换 token；比对 token 值会让**所有合法写请求 403**。
   正确判据是「`$_POST` 是否被清空」
3. **`init_db_schema()` 版本门控**：改表结构不提升 `$db_version` → 表永远建不出来**且不报错**
4. **`data/Dockerfiles/**` 的改动默认不进容器**：compose 全是预构建镜像、无 `build:` 段
   → 改了 `postfix.sh` 之类，重启、挂载都没用。要生效得给该服务加 `build:` 并重建镜像。
   **判定"到底生效没有"要比对运行时产物**（`docker exec <c> md5sum /opt/xxx`），不是比对源码

---

## 交付前的反向自检

- [ ] 需求有没有被偷换？验收标准是**用户说的**还是我以为的？
- [ ] 链路真打通了，还是只做了单点？
- [ ] 异常路径（空值/超时/权限/并发）走过一遍吗？
- [ ] 会不会破坏原有功能？回归验证做了吗？
- [ ] 新环境按文档能跑起来吗？
- [ ] 文档与真实行为一致吗？
- [ ] 有没有**没验证就宣称完成**的结论？

答不上任何一条 → 说明还没做完。
