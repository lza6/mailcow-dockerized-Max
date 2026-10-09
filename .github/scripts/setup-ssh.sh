#!/usr/bin/env bash
# 配置部署用 SSH 密钥
# 需要环境变量：SSH_PRIVATE_KEY、SSH_KNOWN_HOSTS
set -euo pipefail

: "${SSH_PRIVATE_KEY:?未设置 SSH_PRIVATE_KEY}"
: "${SSH_KNOWN_HOSTS:?未设置 SSH_KNOWN_HOSTS}"

mkdir -p ~/.ssh
chmod 700 ~/.ssh

printf '%s\n' "$SSH_PRIVATE_KEY" > ~/.ssh/deploy_key
chmod 600 ~/.ssh/deploy_key

printf '%s\n' "$SSH_KNOWN_HOSTS" > ~/.ssh/known_hosts
chmod 644 ~/.ssh/known_hosts

echo "SSH 配置完成"
