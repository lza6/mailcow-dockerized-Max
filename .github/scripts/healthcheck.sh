#!/usr/bin/env bash
# 部署后健康检查
# 用法：healthcheck.sh <URL>
set -euo pipefail

URL="${1:?用法: healthcheck.sh <URL>}"
RETRIES="${HEALTHCHECK_RETRIES:-12}"
INTERVAL="${HEALTHCHECK_INTERVAL:-10}"
EXPECT_CODES="${HEALTHCHECK_CODES:-200 301 302 401 403}"

echo "=== 健康检查: ${URL} ==="
echo "    重试 ${RETRIES} 次，间隔 ${INTERVAL}s"

for i in $(seq 1 "$RETRIES"); do
  CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 -k "$URL" || echo 000)"
  echo "  [$i/$RETRIES] HTTP ${CODE}"

  for ok in $EXPECT_CODES; do
    if [ "$CODE" = "$ok" ]; then
      echo "=== 健康检查通过 (HTTP ${CODE}) ==="
      exit 0
    fi
  done

  [ "$i" -lt "$RETRIES" ] && sleep "$INTERVAL"
done

echo "::error::健康检查失败：${URL} 连续 ${RETRIES} 次未返回预期状态码"
exit 1
