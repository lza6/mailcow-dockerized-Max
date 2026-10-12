# 号池域名落地页

给发信号池里的域名挂一个**真实可访问的网站**。

## 为什么要做

Gmail / Outlook 等接收方会参考「发件域是否有真实网站内容」来判断域名画像。
15 个号池域都是新注册（5–20 天），且原先的 A 记录指向一台**已不在服务**的占位主机
（Cloudflare 记录里的 comment 就是 `placeholder site`）—— 外部访问全部失败。
完全没有内容的域名更容易被当作垃圾发信源。

## 现状（2026-10-13，已验证）

| 项 | 值 |
|---|---|
| 覆盖域名 | 12 个（见 `sites/` 目录） |
| 落地页 | 每个域**独立文案**的中文企业/业务介绍页（不是同一套模板换名字） |
| 访问方式 | A 记录 → `15.204.205.80`，由本机 nginx 直接提供 |
| HTTP / HTTPS | 均 **200**，证书校验 **OK**（已含在 ACME 证书的 SAN 里） |

## 文件位置

| 位置 | 作用 |
|---|---|
| `sites/<域>/index.html` | 各域页面（本目录，随仓库走） |
| `/opt/mailcow-dockerized/data/web-sites/` | 生产上实际服务的目录（从 `sites/` 同步） |
| `/opt/mailcow-dockerized/data/conf/nginx/zz-pool-sites.conf` | 12 个 `server` 块 |
| `/opt/mailcow-dockerized/docker-compose.override.yml` | 把 `data/web-sites` 只读挂进 nginx |
| `mailcow.conf` 的 `ADDITIONAL_SAN` | 12 个裸域，让 ACME 把它们签进证书 |

## 部署 / 更新

```bash
# 1) 同步页面
rsync -a --delete sites/ /opt/mailcow-dockerized/data/web-sites/
# 2) 若新增域名：往 zz-pool-sites.conf 追加 server 块，并把域名加进 ADDITIONAL_SAN
# 3) 校验并重载
docker exec $(docker ps -qf name=nginx-mailcow) nginx -t
docker exec $(docker ps -qf name=nginx-mailcow) nginx -s reload
```

## ⚠️ 三个踩过的坑（都会导致「看起来配好了但打不开」）

1. **别用 mailcow 的 `site.*.custom` 钩子**。那个 `include` 位于 mailcow 的
   **`server` 块内部**（`sites-default.conf.j2` 末行），只能放 `location` 级指令；
   放 `server` 块会直接 `nginx -t` 失败（`"server" directive is not allowed here`）。
   要独立站点就放 `conf.d/` 下的独立 `.conf` —— `nginx.conf` 里有 http 级的
   `include /etc/nginx/conf.d/*.conf;`。

2. **静态站的 server 块必须显式留 ACME 挑战通道**，否则证书续期会
   `Confirmed A record with IP ..., but HTTP validation failed`：
   ```nginx
   location ^~ /.well-known/acme-challenge/ { root /web; allow all; default_type "text/plain"; }
   ```
   因为本 server 块的 `root` 指向静态站，挑战文件却在 mailcow 的 webroot 里。

3. **`data/web` 在 nginx 容器里是 `:ro` 只读挂载** —— 挑战文件要**从宿主机**
   （或 acme 容器）写，不能在 nginx 容器里写。手工验证时踩过这个。

## 回滚

```bash
rm /opt/mailcow-dockerized/data/conf/nginx/zz-pool-sites.conf
rm /opt/mailcow-dockerized/docker-compose.override.yml
# 还原 mailcow.conf 的 ADDITIONAL_SAN（备份：mailcow.conf.bak_*）
cd /opt/mailcow-dockerized && docker compose up -d --force-recreate --no-deps nginx-mailcow acme-mailcow
```
