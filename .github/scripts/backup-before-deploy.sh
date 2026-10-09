#!/usr/bin/env bash
# 部署前备份数据库（仅生产环境调用）
# 需要环境变量：DEPLOY_HOST / DEPLOY_USER / DEPLOY_PATH
set -euo pipefail

: "${DEPLOY_HOST:?未设置 DEPLOY_HOST}"
: "${DEPLOY_USER:?未设置 DEPLOY_USER}"
: "${DEPLOY_PATH:?未设置 DEPLOY_PATH}"

SSH_OPTS=(-i ~/.ssh/deploy_key -o StrictHostKeyChecking=yes -o ConnectTimeout=20)
REMOTE="${DEPLOY_USER}@${DEPLOY_HOST}"
STAMP="$(date -u +%Y%m%d-%H%M%S)"

echo "=== 部署前备份数据库 ==="

ssh "${SSH_OPTS[@]}" "$REMOTE" "
  set -e
  cd '${DEPLOY_PATH}/current' 2>/dev/null || { echo '  当前版本目录不存在，跳过备份'; exit 0; }

  BK_DIR='${DEPLOY_PATH}/backups'
  mkdir -p \"\$BK_DIR\"

  DB=\$(docker ps -qf name=mysql-mailcow || true)
  if [ -z \"\$DB\" ]; then
    echo '  mysql 容器未运行，跳过'
    exit 0
  fi

  DBPASS=\$(grep '^DBROOT=' mailcow.conf 2>/dev/null | cut -d= -f2 || true)
  if [ -z \"\$DBPASS\" ]; then
    echo '::error::无法读取 DBROOT，中止部署'
    exit 1
  fi

  docker exec \"\$DB\" mysqldump -uroot -p\"\$DBPASS\" --single-transaction --routines \
    --events mailcow 2>/dev/null | gzip > \"\$BK_DIR/mailcow-${STAMP}.sql.gz\"

  SIZE=\$(du -h \"\$BK_DIR/mailcow-${STAMP}.sql.gz\" | cut -f1)
  echo \"  备份完成: mailcow-${STAMP}.sql.gz (\$SIZE)\"

  # 只保留最近 10 份
  ls -1t \"\$BK_DIR\"/mailcow-*.sql.gz 2>/dev/null | tail -n +11 | xargs -r rm -f
"

echo "=== 备份结束 ==="
