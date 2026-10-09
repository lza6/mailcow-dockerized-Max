# Cloudflare Email Sending SMTP 桥接

把 mailcow 的出站邮件经 **Cloudflare Email Sending** 转发。

---

## 为什么需要这个桥接

mailcow 的 postfix 默认用「**587 + STARTTLS**」发信，而 Cloudflare Email Sending
**只接受「465 + 隐式 TLS」**（官方明确不支持 587/STARTTLS，也不支持明文）。

虽然 postfix 本身支持 465，但 **mailcow 的中继机制无法直接指定 465**：

| 机制 | 限制 |
|---|---|
| `sender_dependent_default_transport_maps` | 只接受**纯主机名**，不接受 `transport:host` 格式 |
| `transport_maps` | 按**收件人**匹配，无法按发件域路由 |
| mailcow 生成的 SQL | 硬编码 `'smtp:'` 前缀，且**容器每次启动重新生成**，手改会被覆盖 |

要原生支持 465，必须改 `data/Dockerfiles/postfix/postfix.sh` 并**重建 postfix 镜像**。

**本桥接避免了这个改动**：它在 mailcow 网络内监听一个普通 SMTP 端口，
把邮件经 465 转发给 Cloudflare。邮件系统侧只需把 relayhost 指向本桥接，
走 mailcow 完全原生支持的路径。

```
mailcow postfix ──587/明文──> cf-bridge:2526 ──465/隐式TLS──> Cloudflare ──> 收件方
```

---

## 快速使用

### 1. 启动桥接容器

```bash
cd /path/to/mailcow-dockerized

docker run -d --name cf-bridge --restart unless-stopped \
  --network mailcowdockerized_mailcow-network \
  -e CF_API_TOKEN="<你的 Cloudflare API Token>" \
  -e LISTEN_PORT=2526 \
  -v "$(pwd)/helper-scripts/cf-email-bridge/bridge.py:/app/bridge.py:ro" \
  python:3.12-alpine \
  python /app/bridge.py
```

> Cloudflare API Token 需要 **Email Sending: Edit** 权限
> （在 Cloudflare 后台 → My Profile → API Tokens 创建）。

### 2. 在 mailcow 后台添加中继

**管理员后台 → 配置 → 路由（Routing）→ 中继主机**

| 字段 | 值 |
|---|---|
| 主机名 (Hostname) | `cf-bridge:2526` |
| 用户名 (Username) | `api_token` |
| 密码 (Password) | 留空即可（桥接侧已持有 Token） |
| 激活 | ✅ |

### 3. 把发件域绑定到该中继

**管理员后台 → 配置 → 域名 → 编辑对应域名 → 中继主机** 选择刚创建的条目。

或在数据库侧：

```bash
docker exec $(docker ps -qf name=mysql-mailcow) \
  mysql -uroot -p"$DBROOT" mailcow -e \
  "UPDATE domain SET relayhost=(SELECT id FROM relayhosts WHERE hostname='cf-bridge:2526')
   WHERE domain='your-domain.com';"
```

### 4. 验证

```bash
docker logs cf-bridge --tail 20
```

正常应看到：

```
CONNECT ('172.22.1.253', xxxxx)
RELAY OK  from=user@your-domain.com  to=['someone@example.com']  subject=...
```

---

## 环境变量

| 变量 | 默认值 | 说明 |
|---|---|---|
| `CF_API_TOKEN` | 必填 | Cloudflare API Token（Email Sending 权限） |
| `LISTEN_PORT` | `2526` | 监听端口 |
| `CF_HOST` | `smtp.mx.cloudflare.net` | Cloudflare SMTP 地址 |
| `CF_PORT` | `465` | Cloudflare SMTP 端口（固定 465，隐式 TLS） |
| `ALLOW_DOMAINS` | 空 | 发件域白名单，逗号分隔。**建议设置**，防止被当作开放中继 |

**生产环境务必设置 `ALLOW_DOMAINS`**，例如：

```bash
-e ALLOW_DOMAINS="urbansproutglobal.com,send.urbansproutglobal.com"
```

---

## Cloudflare 侧的准备工作

1. 在 Cloudflare 后台开通 **Email Sending**（需 Workers Paid 计划，$5/月）
2. **Compute → Email Service → Email Sending → Onboard Domain**
   - Cloudflare 会**自动写入**所需 DNS 记录（3 条 MX + SPF + DKIM + DMARC）
   - 记录位于 `<子域>.你的域名`，与现有收信配置**互不干扰**
3. 该域名在 Cloudflare 中显示为 **Verified** 后即可发信

---

## 常见问题

### `451 4.7.1 rate limit exceeded`

Cloudflare 对**新账号**有动态速率限制，会随发信信誉逐步放宽。
额度用尽后需等待恢复（通常按小时/天计）。这是 Cloudflare 侧的限制，
桥接无法绕过。

### `550 5.7.26 ... sender is unauthenticated`（发到 Gmail 被拒）

说明邮件**没有走桥接**，而是直连了收件方。检查：

```bash
docker exec $(docker ps -qf name=postfix-mailcow) \
  postconf sender_dependent_default_transport_maps
# 应输出非空值

# 确认 SQL 映射文件存在
docker exec $(docker ps -qf name=postfix-mailcow) \
  ls -la /opt/postfix/conf/sql/mysql_sender_dependent_default_transport_maps.cf
```

若该参数为空，在 `data/conf/postfix/extra.cf` 中加入：

```
sender_dependent_default_transport_maps = proxy:mysql:/opt/postfix/conf/sql/mysql_sender_dependent_default_transport_maps.cf
```

（`extra.cf` 是 mailcow 官方支持的永久覆盖入口，其内容会被追加到 `main.cf`）

### 邮件进了垃圾箱但没被拒

这与桥接无关，属于**收件方信誉判定**。已实测确认的影响因素：

- **发送速率**：短时间内从多个不同域名群发，会被判为垃圾邮件特征
- **域名历史**：新注册域名的正向记录需要时间积累
- **收件人互动**：收件人点「不是垃圾邮件」对判定权重极高

详见仓库根目录的 `15个域名E2E验证报告.md`。

### 查看桥接收到的完整日志

```bash
docker logs -f cf-bridge
```

---

## 安全说明

- 桥接**仅监听在内网**（mailcow 的 docker 网络），不对外暴露端口
- 建议设置 `ALLOW_DOMAINS` 限制发件域，避免成为开放中继
- Cloudflare API Token 通过环境变量传入，不写入镜像
- 桥接不存储任何邮件内容，转发后即丢弃

---

## 卸载

```bash
docker rm -f cf-bridge
```

然后在 mailcow 后台删除对应的中继条目，并把域名的中继绑定改回默认。
