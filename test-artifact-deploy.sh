#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────
# test-artifact-deploy.sh — Trigger an artifact deploy webhook
#
# Usage:
#   ./test-artifact-deploy.sh [HOST] [TOKEN] [PROJECT_ID] [JOB_ID] [BRANCH] [JOB]
#
# Defaults:
#   HOST       = http://localhost
#   TOKEN      = (empty — works when no DEPLOY_TOKEN is configured)
#   PROJECT_ID = test-project
#   JOB_ID     = job-001
#   BRANCH     = main
#   JOB        = deploy
# ──────────────────────────────────────────────────────────────────────────────

HOST="http://localhost:19090"
TOKEN=""
PROJECT_ID="18451681"
JOB_ID="14361478246"
BRANCH="development"
JOB="build_qa"

# "project_id": "18451681",
# "job_id": "14361478246",
# "branch": "development",
# "job": "build_qa"


URL="${HOST}/webhook/artifact-deploy"

PAYLOAD=$(cat <<EOF
{
  "project_id": "${PROJECT_ID}",
  "job_id":     "${JOB_ID}",
  "branch":     "${BRANCH}",
  "job":        "${JOB}"
}
EOF
)

echo "→ POST ${URL}"
echo "  payload: ${PAYLOAD}"
echo

curl -s -i \
  -X POST \
  -H "Content-Type: application/json" \
  ${TOKEN:+-H "X-Deploy-Token: ${TOKEN}"} \
  -d "${PAYLOAD}" \
  "${URL}"

echo
