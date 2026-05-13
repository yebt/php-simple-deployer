#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────
# test-webhook-deploy.sh — Trigger a standard webhook deploy
#
# Usage:
#   ./test-webhook-deploy.sh [HOST] [TOKEN]
#
# Defaults:
#   HOST  = http://localhost
#   TOKEN = (empty — works when no DEPLOY_TOKEN is configured)
# ──────────────────────────────────────────────────────────────────────────────

HOST="http://localhost:19090"
TOKEN=""

URL="${HOST}/webhook/deploy"

echo "→ POST ${URL}"

curl -s -i \
  -X POST \
  -H "Content-Type: application/json" \
  ${TOKEN:+-H "X-Deploy-Token: ${TOKEN}"} \
  "${URL}"

echo
