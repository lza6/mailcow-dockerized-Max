# CI/CD 流水线设计与使用说明

> 项目：mailcow-dockerized-Max
> 平台：GitHub Actions
> 目标环境：VPS + Docker Compose

---

## 一、技术栈与设计前提

模板中的占位符已按本项目实际情况补全：

| 占位符 | 本项目实际值 | 依据 |
|---|---|---|
| `[LANGAGE_ET_FRAMEWORK]` | **PHP 8.2 + 原生 JS + Twig** | 102 个 PHP、26 个 JS、79 个 Twig 文件 |
| `[PLATEFORME_CI_CD]` | **GitHub Actions** | 仓库已有 `.github/workflows/` |
| `[ENVIRONNEMENTS]` | **staging + production** | 见下方分支策略 |
| `[INFRASTRUCTURE]` | **VPS + Docker Compose** | 项目本身即 compose 编排 |
| `[BASE_DE_DONNEES]` | **MariaDB 10.11**（容器 `mysql-mailcow`） | docker-compose.yml |

**两个影响设计的项目特性**：

1. **无 `composer.json` / `package.json`** —— 依赖已 vendor 到 `data/web/inc/lib/vendor`，
   因此**没有「安装依赖」这一步**，也不需要依赖缓存。
2. **语言包是本项目最核心的自研资产** —— 因此把「语言包完整性 + 占位符一致性」
   提升为**阻断级检查**，而不是可选检查。

---

## 二、流水线流程图

```
                          ┌──────────────────────────┐
   push / PR              │  触发条件                 │
   ──────────────►        │  push: main, develop,    │
                          │        feature/**,        │
                          │        hotfix/**          │
                          │  PR:   → main, develop    │
                          └────────────┬─────────────┘
                                       │
                    ┌──────────────────▼──────────────────┐
                    │           CI (ci.yml)                │
                    │                                      │
                    │  ┌────────────────────────────────┐  │
                    │  │ Stage 1  quality               │  │
                    │  │  • PHP 语法（php -l 全量）      │  │
                    │  │  • JS 语法（node --check）      │  │
                    │  │  • 语言包完整性  ★阻断          │  │
                    │  │  • 占位符/标签一致性 ★阻断      │  │
                    │  └───────────────┬────────────────┘  │
                    │                  │ needs              │
                    │  ┌───────────────▼────────────────┐  │
                    │  │ Stage 2  test                  │  │
                    │  │  • PHP 断言测试（22 项）        │  │
                    │  │  • 语言包回退逻辑               │  │
                    │  └───────────────┬────────────────┘  │
                    └──────────────────┼───────────────────┘
                                       │ 全部通过
                    ┌──────────────────▼───────────────────┐
                    │           CD (cd.yml)                │
                    │                                      │
                    │  ┌────────────────────────────────┐  │
                    │  │ gate（部署前门禁）              │  │
                    │  │  重复质量检查，输出 target      │  │
                    │  └───────┬────────────────┬───────┘  │
                    │          │                │          │
                    │   target=staging   target=production │
                    │          │                │          │
                    │  ┌───────▼──────┐  ┌──────▼────────┐ │
                    │  │ deploy-      │  │ ⏸ 人工审批     │ │
                    │  │ staging      │  │  （Required    │ │
                    │  │ 自动         │  │   reviewers）  │ │
                    │  └───────┬──────┘  └──────┬────────┘ │
                    │          │                │          │
                    │          │         ┌──────▼────────┐ │
                    │          │         │ 备份数据库     │ │
                    │          │         └──────┬────────┘ │
                    │          └────────┬───────┘          │
                    │          ┌────────▼────────┐         │
                    │          │ 原子部署         │         │
                    │          │ releases/<ts>   │         │
                    │          │ + current 软链   │         │
                    │          └────────┬────────┘         │
                    │          ┌────────▼────────┐         │
                    │          │ 健康检查         │         │
                    │          └───┬─────────┬───┘         │
                    │        成功  │         │ 失败         │
                    │              ▼         ▼             │
                    │           完成    ┌──────────┐       │
                    │                   │ 自动回滚  │       │
                    │                   └────┬─────┘       │
                    │                        ▼             │
                    │                   ┌──────────┐       │
                    │                   │ 通知告警  │       │
                    │                   └──────────┘       │
                    └──────────────────────────────────────┘
```

---

## 三、分支策略

```
main      ●────────●─────────────────●──────────►  生产
          │        ↑                 ↑
          │        │ merge(审批)     │ merge(审批)
          │        │                 │
develop   ●───●────●────●───────●────●──────────►  预发
              ↑         ↑       ↑
              │         │       │
feature/*     ●───●─────┘       │
                                │
hotfix/*                        ●────────────────►  紧急修复
```

| 分支 | 用途 | 触发行为 |
|---|---|---|
| `main` | 生产代码 | push → CI；合并 → CD 部署 production（**需人工审批**） |
| `develop` | 预发集成 | push → CI；合并 → CD 部署 staging（自动） |
| `feature/**` | 功能开发 | push → 仅 CI |
| `hotfix/**` | 紧急修复 | push → 仅 CI；修复后合并 main（走审批） |

**保护规则建议**（在 GitHub 仓库 Settings → Branches 配置）：

- `main` 与 `develop`：
  - 禁止直接 push
  - 要求 PR 通过 `CI / 质量检查` 与 `CI / 测试`
  - 要求至少 1 人次review
  - 要求分支与目标分支同步
- `main` 额外要求：部署前经 `production` environment 的 Required reviewers 批准

---

## 四、必需的 Secrets 与 Variables

在 GitHub 仓库 **Settings → Secrets and variables → Actions** 配置。

### Secrets（加密，不显示）

| 名称 | 用途 |
|---|---|
| `STAGING_HOST` | staging 服务器地址 |
| `STAGING_USER` | staging SSH 用户名 |
| `STAGING_PATH` | staging 部署根目录（如 `/opt/mailcow`） |
| `STAGING_SSH_KEY` | staging 部署私钥（**只读+部署权限**） |
| `STAGING_KNOWN_HOSTS` | staging 的 host key（防中间人） |
| `PRODUCTION_HOST` | 生产服务器地址 |
| `PRODUCTION_USER` | 生产 SSH 用户名 |
| `PRODUCTION_PATH` | 生产部署根目录 |
| `PRODUCTION_SSH_KEY` | 生产部署私钥 |
| `PRODUCTION_KNOWN_HOSTS` | 生产的 host key |
| `NOTIFY_WEBHOOK_URL` | 告警 Webhook 地址（Slack/飞书/企业微信） |

### Variables（明文，可见）

| 名称 | 用途 | 示例 |
|---|---|---|
| `STAGING_URL` | staging 健康检查地址 | `https://staging.example.com/` |
| `PRODUCTION_URL` | 生产健康检查地址 | `https://mail.example.com/` |

### 生成 SSH 密钥对

```bash
# 为部署专门生成密钥（不要复用个人密钥）
ssh-keygen -t ed25519 -C "github-actions-deploy" -f ./deploy_key -N ""

# 公钥放到服务器
ssh-copy-id -i ./deploy_key.pub user@your-server

# 记录 host key（防中间人）
ssh-keyscan -H your-server > known_hosts

# 私钥内容 → 复制到 GitHub Secret（含首尾行）
cat deploy_key
# host key 内容 → 复制到 GitHub Secret
cat known_hosts
```

**部署账号最小权限建议**：只授予部署目录的写权限，不要用 root。

---

## 五、部署机制说明

### 为什么用「releases + 软链」而不是直接覆盖

直接覆盖有**中途失败的窗口期**——文件传到一半、服务重启到一半都可能让站点处于半坏状态。
本方案：

```
/opt/mailcow/
├── releases/
│   ├── 20261009-120000/     ← 新版本（完整上传后才切换）
│   ├── 20261009-090000/     ← 上一版本（回滚目标）
│   └── ...
├── current -> releases/20261009-120000   ← 原子切换
├── backups/                 ← 部署前数据库备份
└── .previous_release        ← 记录回滚目标
```

**原子切换**：`mv -Tf current.new current` 是 POSIX 原子操作，
不存在「指向不存在的目录」的中间状态。

**回滚**：`rollback.sh` 读 `.previous_release` 并切回。
因为整个旧版本目录还在，回滚**秒级完成**。

### 为什么排除 `data/` 和 `mailcow.conf`

- `mailcow.conf` 含数据库密码、随机生成的密钥，**属于本机配置**，不应随代码覆盖
- `data/` 含邮件数据、DKIM 私钥、附件，**绝不能被部署覆盖**

部署脚本从上一版本的 `current/mailcow.conf` 继承配置，保证不丢。

---

## 六、性能优化措施

| 优化 | 实现 | 收益 |
|---|---|---|
| **并发取消** | `concurrency.cancel-in-progress: true` | 同一分支连续推送只跑最后一次，省额度 |
| **部署串行** | CD 的 `concurrency` 不取消（`false`） | 避免两个部署互相覆盖 |
| **job 依赖** | `test` 需要 `quality` 通过 | 质量不达标不做昂贵的测试 |
| **提前失败** | PHP 语法检查最先跑（秒级） | 语法错误 10 秒内暴露 |
| **限定扫描范围** | 排除 `vendor/`、`lib/`、`js/build/` | 扫描量从数千降到百余文件 |
| **超时保护** | 每个 job 设 `timeout-minutes` | 卡死不会一直占用 |

**未采用 cache 的原因**：本项目**无包管理器**（依赖已 vendor 提交进仓库），
`actions/setup-php` / `setup-node` 本身已有工具链缓存，再加自定义 cache 无收益。

**未采用 matrix 的原因**：仓库已有的 `image_builds.yml` 已用 matrix 并行构建 12 个 Docker 镜像，
那是真正的并行收益点；CI 的语法检查是秒级任务，拆分反而增加调度开销。

---

## 七、故障排查

### CI 失败

| 报错 | 原因 | 处理 |
|---|---|---|
| `语言包校验失败：存在缺失的 key` | 上游新增了 key，中文未同步 | 运行 `helper-scripts/add-new-lang-keys.php zh-cn` 补 key 后翻译 |
| `占位符不一致 xxx.yyy` | 译文丢了 `%s` 等占位符 | 对照报错里的原文补齐 |
| `HTML 标签不一致 xxx.yyy` | 译文多加/漏了 `<b>`、`<br>` | 使标签数量与原文一致 |
| `PHP 语法错误` | 改坏了 PHP | 本地 `php -l <file>` 复现 |
| `JS 语法错误` | 改坏了 JS | 本地 `node --check <file>` 复现 |

**本地提前自检**（提交前跑，避免推上去才发现）：

```bash
node .github/scripts/check-lang.cjs
node .github/scripts/check-placeholders.cjs
php .github/scripts/test-runner.php
```

### CD 失败

| 报错 | 原因 | 处理 |
|---|---|---|
| `Permission denied (publickey)` | SSH 密钥没配好 | 确认 Secret 含**完整**私钥（含 `-----BEGIN/END-----`） |
| `Host key verification failed` | `KNOWN_HOSTS` 不匹配 | 重新 `ssh-keyscan -H <host>` 更新 Secret |
| `没有可回滚的上一个版本` | 首次部署，无历史版本 | 正常，手工修复后重新部署 |
| `健康检查失败` | 服务起不来 | 登录服务器 `docker compose logs` 查容器日志 |
| `无法读取 DBROOT，中止部署` | 服务器上 `mailcow.conf` 缺失 | 检查 `DEPLOY_PATH/current/mailcow.conf` |
| `docker: command not found` | 部署账号无 docker 权限 | `usermod -aG docker <user>` |

### 手动回滚

```bash
# 登录服务器
cd /opt/mailcow
cat .previous_release              # 看回滚目标
PREV=$(cat .previous_release)
ln -sfn "$PREV" current.new && mv -Tf current.new current
cd current && docker compose up -d
```

### 手动触发部署

GitHub → Actions → CD → Run workflow → 选择目标环境。

---

## 八、已知限制

| # | 限制 | 说明 |
|---|---|---|
| 1 | **无 E2E 测试** | 本项目是邮件服务器，E2E 需要拉起完整邮件栈并收发真实邮件，成本高。当前以「语言包校验 + PHP 断言 + 部署后健康检查」作为替代。若要补 E2E，建议用 `helper-scripts/dev_tests/` 扩展。 |
| 2 | **无 SAST / 依赖扫描** | 依赖已 vendor 提交，无包管理器清单可供扫描工具消费。当前以 `test-runner.php` 中的安全检查（语言包不得含 `<script>`、PHP 不得直接输出超全局变量）作为轻量替代。 |
| 3 | **覆盖率未接入** | 无测试框架，无覆盖率工具。当前测试是**行为断言**而非覆盖率驱动。 |
| 4 | **staging/production 需自行准备** | 两个环境的服务器、目录、SSH 凭据都需要你先准备好，流水线只是驱动。 |

---

## 九、快速上手

```bash
# 1. 本地自检（提交前）
node .github/scripts/check-lang.cjs
node .github/scripts/check-placeholders.cjs

# 2. 推送功能分支
git checkout -b feature/xxx
git push -u origin feature/xxx
#    → 自动触发 CI

# 3. 合并到 develop
#    → 自动部署 staging

# 4. 合并到 main
#    → 等待审批 → 部署 production
```
