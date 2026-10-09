#!/usr/bin/env bash
# 回滚：切回上一个 release
# 需要环境变量：DEPLOY_HOST / DEPLOY_USER / DEPLOY_PATH / ENVIRONMENT
set -euo pipefail

: "${DEPLOY_HOST:?未设置 DEPLOY_HOST}"
: "${DEPLOY_USER:?未设置 DEPLOY_USER}"
: "${DEPLOY_PATH:?未设置 DEPLOY_PATH}"
ENVIRONMENT="${ENVIRONMENT:-staging}"

SSH_OPTS=(-i ~/.ssh/deploy_key -o StrictHostKeyChecking=yes -o ConnectTimeout=20)
REMOTE="${DEPLOY_USER}@${DEPLOY_HOST}"

echo "=== 回滚 ${ENVIRONMENT} ==="

ssh "${SSH_OPTS[@]}" "$REMOTE" "
  set -e
  cd '${DEPLOY_PATH}'

  if [ ! -f .previous_release ]; then
    echo '::error::没有可回滚的上一个版本（.previous_release 不存在）'
    exit 1
  fi

  PREV=\$(cat .previous_release)
  if [ ! -d \"\$PREV\" ]; then
    echo \"::error::上一个版本目录不存在: \$PREV\"
    exit 1
  fi

  echo \"  回滚到: \$PREV\"
  ln -sfn \"\$PREV\" '${DEPLOY_PATH}/current.new'
  mv -Tf '${DEPLOY_PATH}/current.new' '${DEPLOY_PATH}/current'

  cd '${DEPLOY_PATH}/current'
  if [ -f docker-compose.yml ]; then
    docker compose up -d 2>&1 | tail -10
  fi

  echo '  回滚完成'
"

echo "=== 回滚结束 ==="
