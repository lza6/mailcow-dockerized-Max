# Google Postmaster Tools 接入操作指南

> 目的：获取 Google **官方**给出的域名信誉评分与垃圾邮件率数据
> 这是唯一能看到 Google 内部判定的途径

---

## 为什么必须你本人操作

Google 强制验证**域名所有权**，验证记录由 Google 在你登录后动态生成，
**无法由 API 或第三方代生成**。你只需做「登录 + 添加域名 + 把验证码给我」，
其余（写入 DNS）我全自动完成。

---

## 操作步骤（约 5 分钟）

### 第 1 步：登录并添加域名

1. 打开 <https://postmaster.google.com/>
2. 用你的 Google 账号登录
3. 点击右下角 **＋** 按钮
4. 输入域名，例如 `urbansproutglobal.com`
5. 点击 **下一步**

### 第 2 步：获取验证记录

Google 会显示一条 TXT 记录，形如：

```
类型：TXT
名称：@   （或显示为裸域名）
值：  google-site-verification=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

**把这个值复制给我**（整串 `google-site-verification=...`）。

### 第 3 步：我把记录写入 Cloudflare

我收到后会：
1. 用 Cloudflare API 写入 TXT 记录
2. 验证公网可见
3. 告诉你「可以点验证了」

### 第 4 步：回 Google 点「验证」

回到 Postmaster Tools 页面，点击 **验证**。

### 第 5 步：等数据（关键）

**注意**：Postmaster Tools 的**信誉与垃圾率数据需要一定发送量才会显示**。
官方要求是「达到一定量级」后才会出数据，通常需要**每天数百封以上**的稳定发送。

**在当前低发送量下，可能只能看到「暂无数据」** —— 这是正常的，不是操作失败。

---

## 能看到的四种数据

| 数据项 | 含义 | 健康标准 |
|---|---|---|
| **域名信誉** | Google 对你域名的整体评价 | High / Medium 为佳，Bad 需立即处理 |
| **IP 信誉** | 发信 IP 的评价 | 共享 IP 会显示中继商的表现 |
| **垃圾邮件率** | 用户举报比例 | **必须 < 0.30%**，建议 < 0.10% |
| **投递错误** | SPF/DKIM/DMARC 失败等 | 应为 0 |

---

## 15 个域名清单（逐个添加）

```
urbansproutglobal.com
breezeshelf.com
brightcrateglobal.com
cedarcartco.com
cedarloomexporthouse.com
echolanecommerce.com
harborpinetradeworks.com
hearthcart.com
indigobasketglobal.com
larkhavensupplyworks.com
mangobridgeretail.com
maplevalleytrading.com
summitnestretail.com
trendhollowretail.com
xiaomillc.com
```

---

## 替代方案：Search Console

如果你已在 **Google Search Console** 验证过这些域名，
Postmaster Tools 会**自动识别**，无需重复添加验证记录。

检查方法：登录 <https://search.google.com/search-console> 看是否有这些域。

---

## 写入验证记录的命令（供执行方参考）

```bash
# 收到验证码后执行
CF_TOKEN="<token>" ZONE_ID="<zone_id>" DOMAIN="<domain>"
curl -s -X POST "https://api.cloudflare.com/client/v4/zones/${ZONE_ID}/dns_records" \
  -H "Authorization: Bearer ${CF_TOKEN}" \
  -H "Content-Type: application/json" \
  -d "{\"type\":\"TXT\",\"name\":\"${DOMAIN}\",\"content\":\"google-site-verification=xxxx\",\"ttl\":300}"
```

---

## 常见问题

**Q：为什么看不到数据？**
A：发送量不足。Postmaster Tools 只对达到一定体量的发件人展示数据。

**Q：验证后多久出数据？**
A：通常 24-48 小时，但前提是发送量达标。

**Q：一个 Google 账号能验证多个域名吗？**
A：可以，数量不限。

**Q：数据准吗？**
A：这是 Google 官方数据，是所有渠道里最权威的。
