#!/usr/bin/env bash
# test-when.sh — prueba los casos de 'when' contra el deployer local
# Uso: ./test-when.sh [host]

HOST="${1:-localhost:5173}"
BASE="http://$HOST/webhook/deploy"

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
RED='\033[0;31m'
NC='\033[0m'

sep()   { echo -e "\n${CYAN}══════════════════════════════════════════${NC}"; }
title() { echo -e "${YELLOW}▶ $1${NC}"; }
ok()    { echo -e "  ${GREEN}✓ $1${NC}"; }
err()   { echo -e "  ${RED}✗ $1${NC}"; }

# El endpoint síncrono bloquea hasta que el deploy termina.
# Aun así esperamos a que finished=true antes de lanzar el siguiente,
# por si el status file aún no se escribió al momento de retornar el curl.
wait_finished() {
    local max=120
    local i=0
    while [ $i -lt $max ]; do
        local finished
        finished=$(curl -s "http://$HOST/status/check" | grep -o '"finished":true')
        [ -n "$finished" ] && return 0
        sleep 1
        i=$((i + 1))
    done
    err "timeout esperando que termine el deploy"
    return 1
}

run_case() {
    local label="$1"
    local body="$2"

    sep
    title "$label"

    if [ "$body" = "GET" ]; then
        # Caso GET con query params — pasados como tercer argumento
        local qs="$3"
        echo "  → GET $BASE?$qs"
        local http_code
        http_code=$(curl -s -o /dev/null -w "%{http_code}" -G "$BASE" \
            $(echo "$qs" | tr '&' '\n' | sed 's/=/ /' | awk '{print "--data-urlencode "$1"="$2}'))
        echo "  HTTP $http_code"
    else
        echo "  → POST $BASE  body: $body"
        local http_code
        http_code=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE" \
            -H "Content-Type: application/json" \
            -d "$body")
        echo "  HTTP $http_code"
    fi

    wait_finished && ok "deploy terminado" || err "deploy no terminó"
}

# ── casos ───────────────────────────────────────────────────────────────────

run_case \
    "1. Sin contexto — corre: always, no-context, list-files" \
    '{}'

run_case \
    "2. repo=frontend branch=main — corre: always, frontend, frontend+main, any-main, list-files" \
    '{"repo":"frontend","branch":"main"}'

run_case \
    "3. repo=frontend branch=staging — corre: always, frontend, frontend+staging, list-files" \
    '{"repo":"frontend","branch":"staging"}'

run_case \
    "4. repo=backend branch=main — corre: always, backend, any-main, list-files" \
    '{"repo":"backend","branch":"main"}'

run_case \
    "5. Via query params GET — repo=frontend branch=main" \
    "GET" "repo=frontend&branch=main"

sep
echo -e "${GREEN}Tests completados. Revisá los logs en http://$HOST/alllogs${NC}"
echo -e "${GREEN}Live view: http://$HOST/status/live2${NC}"
