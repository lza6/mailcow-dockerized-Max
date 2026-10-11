#!/bin/bash
#
# mailcow-dockerized-Max — 邮件统计采集器（在 dovecot 容器内由 ofelia 定时调用）
#
# 采集每个邮箱的：
#   · 最近一封收信时间（INBOX 里最新一封的 INTERNALDATE）
#   · 今日收信数（INBOX 里 INTERNALDATE 在今天的封数）
# 写入 `mailbox_mailstats` 表，供「域名总览」页面展示。
#
# 为什么放在 dovecot 容器里跑、而不是让 Web 去查：
#   邮件数据在 vmail 卷里。把 vmail 卷挂进 php-fpm 就等于**把全站邮件暴露给 Web 应用** ——
#   mailcow 的安全设计恰恰是不让 Web 容器碰到邮件存储。而 dockerapi 也不提供任意
#   命令执行（只派发预定义方法）。
#   所以：由本来就该接触邮件的 dovecot 容器采集**聚合结果**，Web 侧只读这张表。
#   这样 Web 组件始终看不到任何邮件内容。
#
# 幂等：用 REPLACE INTO，重复跑不会产生重复行；邮箱被删则统计行也会被清掉。
#
set -u

# 只在 master 上跑（多节点部署时）
if [ "${MASTER:-y}" != "y" ]; then
  echo '[mailstats] not master, skip'
  exit 0
fi

# MariaDB 11 的客户端**默认要求 TLS**，而本地 socket 连接并不支持 ——
# 不加 --skip-ssl 会报 "ERROR 2026 (HY000): TLS/SSL error: SSL is required,
# but the server does not support it"，且被 `2>/dev/null` 静默吞掉，
# 表现为"查出 0 个邮箱"。本地 socket 本来就不需要 TLS，显式关掉。
# 客户端在新版里叫 mariadb（mysql 只是兼容别名，会打弃用警告到 stderr）。
MYSQL_CLIENT=$(command -v mariadb || command -v mysql)
MYSQL=("$MYSQL_CLIENT" --skip-ssl --socket=/var/run/mysqld/mysqld.sock -u"${DBUSER}" -p"${DBPASS}" "${DBNAME}" -N -B)
DOVEADM=(doveadm)

# 今日（doveadm 的日期格式：11-Oct-2026）
TODAY=$(date +%d-%b-%Y)

q() { "${MYSQL[@]}" -e "$1" 2>/dev/null; }

# 确保表存在（init_db 也会建；这里是兜底，避免首次上线时 cron 先于 Web 请求跑）
q "CREATE TABLE IF NOT EXISTS \`mailbox_mailstats\` (
     \`username\` VARCHAR(255) NOT NULL,
     \`last_received\` DATETIME NULL DEFAULT NULL,
     \`today_received\` INT NOT NULL DEFAULT 0,
     \`checked_at\` DATETIME NOT NULL DEFAULT NOW(),
     PRIMARY KEY (\`username\`)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" >/dev/null

MAILBOXES=$(q "SELECT \`username\` FROM \`mailbox\` WHERE \`active\` = 1")

ok=0; skip=0; fail=0
while IFS= read -r U; do
  [ -z "$U" ] && continue

  # ---- 最近一封：先取 uidnext（O(1)），再只抓最后一封 ----
  NEXT=$("${DOVEADM[@]}" mailbox status -u "$U" uidnext INBOX 2>/dev/null | sed 's/.*uidnext=//' | tr -dc '0-9')
  LAST=""
  if [ -n "$NEXT" ] && [ "$NEXT" -gt 1 ] 2>/dev/null; then
    LAST=$("${DOVEADM[@]}" fetch -u "$U" 'date.received' mailbox INBOX uid "$((NEXT - 1))" 2>/dev/null \
             | grep 'date.received:' | tail -1 | sed 's/.*date.received: *//')
  fi
  # 最后一封被删了（uid 空洞）→ 退回按顺序抓，取最后一个
  if [ -z "$LAST" ]; then
    LAST=$("${DOVEADM[@]}" fetch -u "$U" 'date.received' mailbox INBOX uid 1:* 2>/dev/null \
             | grep 'date.received:' | tail -1 | sed 's/.*date.received: *//')
  fi

  # ---- 今日收信数 ----
  TODAYN=$("${DOVEADM[@]}" search -u "$U" mailbox INBOX SINCE "$TODAY" 2>/dev/null | grep -c . )

  if [ -z "$LAST" ]; then
    # 空邮箱：写成 NULL / 0，不要留旧值（否则删光邮件后页面还显示旧时间）
    q "REPLACE INTO \`mailbox_mailstats\` (\`username\`,\`last_received\`,\`today_received\`,\`checked_at\`)
       VALUES ('$(printf '%s' "$U" | sed "s/'/''/g")', NULL, ${TODAYN:-0}, NOW())" >/dev/null
    skip=$((skip + 1))
  else
    q "REPLACE INTO \`mailbox_mailstats\` (\`username\`,\`last_received\`,\`today_received\`,\`checked_at\`)
       VALUES ('$(printf '%s' "$U" | sed "s/'/''/g")', '${LAST}', ${TODAYN:-0}, NOW())" >/dev/null
    ok=$((ok + 1))
  fi
done <<< "$MAILBOXES"

# 清理已删除邮箱的残留统计
q "DELETE m FROM \`mailbox_mailstats\` m
     LEFT JOIN \`mailbox\` b ON b.\`username\` = m.\`username\`
    WHERE b.\`username\` IS NULL" >/dev/null

echo "[mailstats] 有邮件=${ok} 空邮箱=${skip} 今日=${TODAY}"
