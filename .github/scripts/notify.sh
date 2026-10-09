#!/usr/bin/env bash
# 失败通知（默认走通用 Webhook，兼容 Slack / 飞书 / 企业微信 incoming webhook）
#
# Slack:        {"text": "..."}
# 飞书/企业微信: {"msg_type":"text","content":{"text":"..."}}
# 通过 WEBHOOK_FORMAT 选择：slack | feishu | plain
set -uo pipefail

: "${WEBHOOK_URL:?未设置 WEBHOOK_URL}"
FORMAT="${WEBHOOK_FORMAT:-feishu}"

REPO="${REPO:-unknown}"
BRANCH="${BRANCH:-unknown}"
ACTOR="${ACTOR:-unknown}"
RUN_URL="${RUN_URL:-}"
JOB_STATUS="${JOB_STATUS:-{}}"

TEXT="🚨 部署流水线失败

仓库: ${REPO}
分支: ${BRANCH}
触发人: ${ACTOR}
失败任务: ${JOB_STATUS}
日志: ${RUN_URL}"

case "$FORMAT" in
  slack)
    PAYLOAD=$(printf '{"text": %s}' "$(printf '%s' "$TEXT" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))')")
    ;;
  plain)
    PAYLOAD=$(printf '%s' "$TEXT" | python3 -c 'import json,sys; print(json.dumps({"text": sys.stdin.read()}))')
    ;;
  feishu|*)
    PAYLOAD=$(printf '%s' "$TEXT" | python3 -c 'import json,sys; print(json.dumps({"msg_type":"text","content":{"text": sys.stdin.read()}}))')
    ;;
esac

echo "发送通知（格式: ${FORMAT}）"
HTTP_CODE="$(curl -s -o /tmp/notify_resp.txt -w '%{http_code}' \
  -X POST "$WEBHOOK_URL" \
  -H 'Content-Type: application/json' \
  -d "$PAYLOAD" --max-time 20 || echo 000)"

echo "  响应: HTTP ${HTTP_CODE}"
if [ "$HTTP_CODE" -ge 400 ] 2>/dev/null || [ "$HTTP_CODE" = "000" ]; then
  echo "::warning::通知发送失败"
  cat /tmp/notify_resp.txt 2>/dev/null | head -3
fi

# 通知失败不阻断流水线（原失败状态已由上游 job 反映）
exit 0
