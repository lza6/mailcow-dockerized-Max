# CLAUDE.md — mailcow-dockerized-Max 项目开发指南

> 本文件面向在此仓库上工作的 AI 助手与开发者。**每次会话开始先读本文件。**
> 本仓库是 [mailcow: dockerized](https://github.com/mailcow/mailcow-dockerized) 的**中文汉化魔改版**（Max 版）。

---

## 1. 项目定位

- **是什么**：mailcow 是一套基于 Docker 的完整邮件服务器栈（SMTP/IMAP/POP3/群件/反垃圾/杀毒/ACME），本仓库在保留上游功能的前提下做了**中文本地化**，定位为邮件转发中继器。
- **上游**：`https://github.com/mailcow/mailcow-dockerized`
- **本仓库推送目标**：`https://github.com/lza6/mailcow-dockerized-Max`，**推送分支为 `main`**（注意不是 master）。
- **许可**：GPLv3（上游 LICENSE 不得更改/删除）。
- **当前版本基线**：上游 tag `2026-07b`，提交 `02552ffe`。

---

## 2. 架构与关键路径

### 2.1 容器组成

`docker-compose.yml` 定义了约 18 个容器，核心几个：

| 容器 | 作用 | 关键目录 |
|------|------|----------|
| `nginx-mailcow` | 反向代理、Web 入口、TLS 终结 | `data/conf/nginx/` |
| `php-fpm-mailcow` | Web UI（PHP 8.2 + Twig + opcache） | `data/web/` |
| `postfix-mailcow` | SMTP（收/发/中继） | `data/conf/postfix/` |
| `dovecot-mailcow` | IMAP/POP3/Sieve/LMTP | `data/conf/dovecot/` |
| `rspamd-mailcow` | 反垃圾、DKIM 签名与校验 | `data/conf/rspamd/` |
| `mysql-mailcow` | 配置数据库（域名/邮箱/中继等） | 卷 `mysql-vol-1` |
| `redis-mailcow` | 缓存与封禁列表 | — |
| `sogo-mailcow` | 群件 Webmail | `data/conf/sogo/` |
| `acme-mailcow` | Let's Encrypt 证书 | `data/conf/acme/` |
| `netfilter-mailcow` | fail2ban 式封禁（写入 redis `BAN_LIST`） | — |
| `unbound-mailcow` | 递归 DNS（容器内部解析） | `data/conf/unbound/` |

### 2.2 Web UI 语言加载链路（汉化相关，**必读**）

```
data/web/inc/vars.inc.php
  └─ $AVAILABLE_LANGUAGES   ← 可选语言清单（'zh-cn' => '简体中文 (Simplified Chinese)'）
  └─ $DEFAULT_LANG = 'en-gb'

data/web/inc/prerequisites.inc.php:190-274
  ├─ 会话/Cookie/`?lang=` 决定 $_SESSION['mailcow_locale']
  ├─ $lang = json_decode(data/web/lang/lang.en-gb.json)      ← 总是先加载英文
  └─ if 存在 lang.<locale>.json → array_merge_real($lang, 中文) ← 中文覆盖英文

data/web/inc/lib/array_merge_real.php
  └─ 递归合并：中文缺失的 key 自动回退英文，**不会报错**
```

**结论**：语言包缺失 key 不会导致界面崩坏，只会显示英文。因此**补全率可以增量提升**。

### 2.3 前端消费方式

- Twig 模板：`data/web/templates/**/*.twig` 中 `{{ lang.section.key }}`
- JS：`data/web/js/site/*.js` 中 `lang.section.key`（由 `header.inc.php` 注入 `'lang' => $lang`）

---

## 3. 汉化工作流（本仓库的核心维护任务）

### 3.1 语言包位置与格式约定

| 项 | 约定 |
|----|------|
| 文件 | `data/web/lang/lang.zh-cn.json`（简体）、`lang.zh-tw.json`（繁体） |
| 母本 | `data/web/lang/lang.en-gb.json` —— **所有 key 以它为准** |
| 缩进 | 4 空格 |
| 行尾 | **工作区 CRLF；但 git 索引内是 LF**（`core.autocrlf=true`）。仓库无 `.gitattributes`，提交的是 LF，Linux 部署不受影响 |
| 编码 | UTF-8 无 BOM |
| 排序 | **不要求**按字母序（上游自身也不排序）。新增 key 应插在语义相邻位置，**不要整体重排**，否则 diff 爆炸 |
| 品牌名 | `Pushover`、`HTML`、`TOTP`、`FIDO2/WebAuthn` 等保持原文，不翻译 |

### 3.2 安全改写语言包的方法

**不要**直接 `JSON.stringify(parse(file), null, 4)` 落盘 —— 会因行尾/尾随换行差异污染整个文件。正确做法：

```js
// 读入 -> 原地增改 key -> 按 CRLF 写回
const out = JSON.stringify(lang, null, 4).replace(/\n/g, '\r\n') + '\r\n';
```

### 3.3 汉化后必须跑的校验

```bash
cd data/web/lang
node -e '
const e=JSON.parse(require("fs").readFileSync("lang.en-gb.json","utf8"));
const z=JSON.parse(require("fs").readFileSync("lang.zh-cn.json","utf8"));
const re=/\{\{[^}]*\}\}|\{[A-Za-z0-9_]+\}|%[sd]/g;
let miss=0,plc=0;
for(const s of Object.keys(e))for(const k of Object.keys(e[s]||{})){
  const a=e[s][k]; if(typeof a!=="string")continue;
  const b=z[s]&&z[s][k];
  if(typeof b!=="string"){miss++;console.log("MISS",s+"."+k);continue;}
  const pa=(a.match(re)||[]).sort().join("|"), pb=(b.match(re)||[]).sort().join("|");
  if(pa!==pb){plc++;console.log("PLACEHOLDER",s+"."+k);}
}
console.log("missing:",miss,"placeholder-mismatch:",plc);'
```

**判定标准**：`missing == 0` 且 `placeholder-mismatch == 0`。
（`%s` 等占位符丢失会导致界面显示残缺文案，是必须拦住的错误。）

### 3.4 上游新增 key 的同步

```bash
# 上游自带的辅助脚本（需 PHP）
php helper-scripts/add-new-lang-keys.php zh-cn
```
该脚本会把英文缺 key 以**英文原文**补进目标语言；补完后需人工翻译。

---

## 4. 常用操作

```bash
# 交互式生成配置（会写 mailcow.conf 并生成 .env 符号链接）
./generate_config.sh --dev        # --dev 表示不切换分支
# 首次生成的 mailcow.conf 中 DBROOT/REDISPASS 是随机密码，勿提交

# 生命周期
docker compose pull               # 拉取镜像
docker compose up -d               # 启动
docker compose ps
docker compose logs -f --tail=100 postfix-mailcow
docker compose down                # 停止（保留卷）

# 上游更新脚本
./update.sh
```

### 4.1 常用诊断命令

```bash
# 从 mysql 读配置（域名中继绑定、邮箱属性）
docker exec $(docker ps -qf name=mysql-mailcow) mysql -uroot -p"$DBROOT" mailcow \
  -e "SELECT domain, relayhost FROM domain; SELECT id,hostname,username,active FROM relayhosts;"

# 用 doveadm 直接读某邮箱邮件（绕过 IMAP 客户端）
docker exec $(docker ps -qf name=dovecot-mailcow) doveadm search -u user@dom mailbox INBOX all
docker exec $(docker ps -qf name=dovecot-mailcow) doveadm fetch -u user@dom text mailbox INBOX uid <UID>

# rspamd 学习/统计
docker exec $(docker ps -qf name=rspamd-mailcow) rspamadm stats

# 查看入站/出站邮件日志
docker compose logs --since 24h postfix-mailcow
```

---

## 5. 环境与工具约束（本机 Windows）

- **宿主机没有 docker / php / ruby / gh**。需要容器验证时**必须走 WSL**：
  ```bash
  wsl.exe -e bash -lc "docker version"
  ```
- WSL 发行版：Ubuntu；已装 Docker 29.x + Compose v5.x；`jq`、`sshpass`、`python3` 已装。
- **WSL 的 `~/.docker/config.json` 不能含 `credsStore: desktop.exe`**（会因找不到 Windows credential helper 而拉取失败）。必要时清空为 `{}`。
- 仓库路径含中文，WSL 侧直接操作 `/mnt/c/...` 易出问题；跨到 WSL 做实验时应**先 `tar` 拷贝到 `$HOME` 下的纯 ASCII 路径**，并做一次 `sed -i 's/\r$//'` 归一化行尾（因为工作区是 CRLF，而 WSL 内 `#!/usr/bin/env bash\r` 会直接报错）。
- 本仓库**不使用** `.sh` hook；PowerShell 命令串联用 `; if($?) { }` 而非 `&&`。

---

## 6. 修改须知（Do / Don't）

### 必须做

- 改 `data/web/lang/**` 后跑 §3.3 的校验，确认 `missing=0`、占位符无丢失。
- 改 PHP/Twig 后至少做一次语法/渲染验证（本地栈起得来就跑真实页面）。
- 改动涉及 `data/conf/**` 或 `docker-compose.yml` 时，先在本地栈验证再提交。

### 不要做

- **不要提交任何密钥**：`mailcow.conf`、`.env`、`data/conf/postfix/*.sql` 里可能含明文凭据、`data/dkim/` 与 `data/conf/rspamd/dkim/` 含私钥、`data/conf/mysql/` 含密码。提交前 `git status` 必须核对。
- **不要运行 `git add -A` / `git add .`**，本仓库根目录可能混入本地 `.agents/`、`.claude/`、`.codegraph/` 等工具目录。改 `.gitignore` 时注意不要把这些误加白名单（当前 `.gitignore` 已包含 `mailcow.conf`、`.env`、`data/backup/` 等，请勿削弱）。
- **不要整体重排语言包 key**（见 §3.1），否则 review 无法进行。
- **不要修改 `LICENSE`**。汉化魔改版仍受 GPLv3 约束。
- **不要直接推送上游镜像仓库或覆盖 `origin`**。`origin` 指向的是镜像站，不是本项目目标仓库。
- **不要在未确认的情况下变更服务器 DNS/SPF/DKIM/DMARC**（会影响线上邮件投递）。

---

## 7. 邮件投递（发信进垃圾箱类问题）排查手册

### 7.1 判定顺序（先证明"技术认证是否通过"，再谈"信誉"）

1. **看接收方给出的 `Authentication-Results` 头** —— 这是最权威的判定。
   - Gmail：`dkim= / spf= / dmarc=`
   - 若三项都 `pass` 但仍进垃圾箱 → 问题**不在认证**，在信誉/内容层面。
2. **用第三方验证器交叉验证**：向 `check-auth@verifier.port25.com` 发信，它会回一封包含 SPF / iprev / DKIM 明细的报告。报告回到发件人自己的邮箱，可取用：
   ```bash
   docker exec $(docker ps -qf name=dovecot-mailcow) doveadm fetch -u <user> text mailbox INBOX uid <UID>
   ```
3. **DNS 侧核对**（权威解析器，避免本地缓存）：
   ```bash
   dig +short TXT <domain> @1.1.1.1                    # SPF
   dig +short TXT _dmarc.<domain> @1.1.1.1             # DMARC
   dig +short TXT <selector>._domainkey.<domain> @1.1.1.1   # DKIM 公钥
   dig +short -x <server-ip> @1.1.1.1                  # PTR（必须与 myhostname 一致）
   ```
4. **确认 DKIM selector 与 DNS 记录名一致**。签名头里的 `s=` 就是 selector；`s=dkim` 对应 `dkim._domainkey.<domain>`。**selector 名取错是最常见的 DKIM 失效原因。**
5. **黑名单查询**：用**自建/权威解析器**，公共解析器（1.1.1.1/8.8.8.8）查 Spamhaus 会被返回 `127.255.255.254`（"查询被拒"），**这不是被拉黑**。
   ```bash
   dig +short <reversed-ip>.zen.spamhaus.org @<resolver>
   ```
6. **`detect_bad_asn` 提示要正确理解**：mailcow 报 "The AS of your IP is listed as a banned AS from Spamhaus" 的语义是**"该 AS 被 Spamhaus 禁止使用其免费 DNSBL 服务"**，是**入站过滤**的取数限制，**不等于你的发信信誉差**。对策是申请免费的 Spamhaus DQS key 并填入 `mailcow.conf` 的 `SPAMHAUS_DQS_KEY`。

### 7.2 关键诊断事实（本部署，2026-10-08）

- 发件域 `urbansproutglobal.com`：DKIM selector **`dkim`**，SPF `v=spf1 mx a:mail.pony-it.com -all`，DMARC `p=none`。
- 服务器 `15.204.205.80`，PTR `mail.pony-it.com`，`myhostname = mail.pony-it.com`（**一致**）。
- AS16276（OVH SAS）。
- `relayhost` 为空（当前**直发**）；mailcow 中已配置 3 条中继：Resend、本地 `172.22.1.1:2525`、Gmail SMTP。
- 实测：Gmail 给出 `dkim=pass / spf=pass / dmarc=pass`，但**全部落入垃圾邮件**；port25 独立验证 `SPF/iprev/DKIM` 全 pass。**认证无问题。**

### 7.3 出站中继（relayhost）配置位置

中继在 **Web UI → 配置 → 中继主机（Relayhosts）** 中维护，存入 mysql 的 `relayhosts` 表，再由
`data/conf/postfix/sql/mysql_sender_dependent_default_transport_maps.cf` 等 SQL 映射驱动 postfix。

- **仅设置 relayhost 不够**：若发件域 SPF 未包含中继服务商的 IP/域名，中继后 SPF 会 **fail**，比直发更糟。
  正确顺序是：中继服务商验证域名 → 拿到 SPF include / DKIM 记录 → 更新 DNS → 再在 mailcow 绑定中继。

---

## 8. 验证要求

| 改动类型 | 最低验证 |
|----------|----------|
| 仅语言包 JSON | §3.3 校验通过 + `node -e "JSON.parse(...)"` 能解析 |
| 语言包 + Web UI | 起本地栈，用真实浏览器切到中文，检查菜单/表单渲染 |
| PHP / Twig | 本地栈页面无 500，功能路径可点通 |
| `data/conf/**` / compose | 本地栈能起来且服务健康 |
| 文档（.md） | 链接与代码块语法正确，无残留英文段落 |

**宣称"完成"前必须给出真实执行过的命令与输出。** 未运行的验证明确标注为未验证。

---

## 9. 快速索引

| 想找什么 | 去哪 |
|----------|------|
| 可选语言清单 | `data/web/inc/vars.inc.php` → `$AVAILABLE_LANGUAGES` |
| 语言加载与回退 | `data/web/inc/prerequisites.inc.php:190-274` |
| 递归合并实现 | `data/web/inc/lib/array_merge_real.php` |
| 上游补 key 脚本 | `helper-scripts/add-new-lang-keys.php` |
| 中继映射 SQL | `data/conf/postfix/sql/mysql_sender_dependent_default_transport_maps.cf` |
| 出站过滤/黑名单配置 | `data/conf/postfix/main.cf`、`data/conf/rspamd/` |
| 反垃圾出站/入站策略 | `data/conf/rspamd/local.d/`、`data/conf/rspamd/override.d/` |
| 备份/恢复 | `helper-scripts/backup_and_restore.sh` |
