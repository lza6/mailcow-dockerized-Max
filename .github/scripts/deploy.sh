#!/usr/bin/env bash
# 部署脚本：以原子方式把代码同步到目标服务器并重启相关容器
#
# 目标环境为「VPS + Docker Compose 运行的 mailcow」。
# 部署采用「先上传到 releases/<时间戳>，再切换 current 软链」的方式，
# 保证切换是原子的，且随时可以回滚到上一个 release。
#
# 需要环境变量：
#   DEPLOY_HOST / DEPLOY_USER / DEPLOY_PATH / ENVIRONMENT
set -euo pipefail

: "${DEPLOY_HOST:?未设置 DEPLOY_HOST}"
: "${DEPLOY_USER:?未设置 DEPLOY_USER}"
: "${DEPLOY_PATH:?未设置 DEPLOY_PATH}"
ENVIRONMENT="${ENVIRONMENT:-staging}"

SSH_OPTS=(-i ~/.ssh/deploy_key -o StrictHostKeyChecking=yes -o ConnectTimeout=20)
REMOTE="${DEPLOY_USER}@${DEPLOY_HOST}"
RELEASE_ID="$(date -u +%Y%m%d-%H%M%S)"
RELEASES_DIR="${DEPLOY_PATH}/releases"
RELEASE_DIR="${RELEASES_DIR}/${RELEASE_ID}"

echo "=== 部署到 ${ENVIRONMENT} (${REMOTE}) ==="
echo "    release: ${RELEASE_ID}"

# ---- 1. 准备目录结构 ------------------------------------------------
ssh "${SSH_OPTS[@]}" "$REMOTE" "
  set -e
  mkdir -p '${RELEASES_DIR}'
  mkdir -p '${RELEASE_DIR}'
  echo '  远程目录就绪'
"

# ---- 2. 同步代码（排除运行时数据与密钥）-----------------------------
# 用 tar 流式传输，避免 rsync 依赖；排除：
#   .git            版本库
#   mailcow.conf    含数据库密码等本机配置
#   data/           运行时数据（邮件、DKMS 私钥、备份）
#   .github          CI 配置
#   releases/        历史版本
tar -czf - \
  --exclude='.git' \
  --exclude='mailcow.conf' \
  --exclude='.env' \
  --exclude='data' \
  --exclude='.github' \
  --exclude='releases' \
  --exclude='*.log' \
  . | ssh "${SSH_OPTS[@]}" "$REMOTE" "tar -xzf - -C '${RELEASE_DIR}'"

echo "  代码已同步"

# ---- 3. 继承上一版本的运行时配置 ------------------------------------
# mailcow.conf 属于本机配置，不随代码部署，首次部署时从当前版本复制
ssh "${SSH_OPTS[@]}" "$REMOTE" "
  set -e
  cd '${DEPLOY_PATH}'
  if [ -f '${DEPLOY_PATH}/current/mailcow.conf' ] && [ ! -f '${RELEASE_DIR}/mailcow.conf' ]; then
    cp '${DEPLOY_PATH}/current/mailcow.conf' '${RELEASE_DIR}/mailcow.conf'
    echo '  已继承 mailcow.conf'
  fi
  ln -sfn '${RELEASE_DIR}' '${DEPLOY_PATH}/current.new'
  echo '  准备切换软链'
"

# ---- 4. 原子切换 + 重启服务 -----------------------------------------
ssh "${SSH_OPTS[@]}" "$REMOTE" "
  set -e
  cd '${DEPLOY_PATH}'
  # 记录上一个版本用于回滚
  if [ -L '${DEPLOY_PATH}/current' ]; then
    readlink -f '${DEPLOY_PATH}/current' > '${DEPLOY_PATH}/.previous_release'
  fi
  mv -Tf '${DEPLOY_PATH}/current.new' '${DEPLOY_PATH}/current'
  echo '  软链已切换'

  cd '${DEPLOY_PATH}/current'
  if [ -f docker-compose.yml ]; then
    docker compose pull --quiet 2>/dev/null || true
    docker compose up -d 2>&1 | tail -10
    echo '  服务已重启'
  fi
"

# ---- 5. 清理旧版本（保留最近 5 个）----------------------------------
ssh "${SSH_OPTS[@]}" "$REMOTE" "
  cd '${RELEASES_DIR}' 2>/dev/null || exit 0
  ls -1dt */ 2>/dev/null | tail -n +6 | while read -r d; do
    rm -rf "\${d}"
  done
  echo '  旧版本已清理'
"

echo "=== 部署完成：${ENVIRONMENT} / ${RELEASE_ID} ==="
echo "RELEASE_ID=${RELEASE_ID}" >> "${GITHUB_ENV:-/dev/null}" 2>/dev/null || true
