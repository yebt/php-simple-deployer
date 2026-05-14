#!/usr/bin/env bash
# test-when.sh — prueba los casos de 'when' contra el deployer local
# Uso: ./test-when.sh [host]

HOST="${1:-localhost:5173}"
BASE="http://$HOST/webhook/deploy"

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

sep() { echo -e "\n${CYAN}══════════════════════════════════════════${NC}"; }
title() { echo -e "${YELLOW}▶ $1${NC}"; }

# Espera a que el anterior deploy termine antes de lanzar el siguiente
wait_deploy() {
    local max=60
    local i=0
    while [ $i -lt $max ]; do
        finished=$(curl -s "http://$HOST/status/check" | grep -o '"finished":true')
        [ -n "$finished" ] && return 0
        sleep 2
        i=$((i + 2))
    done
    echo "  [WARN] timeout esperando que termine el deploy"
}

sep
title "1. Sin contexto — espera que corra 'no-context' y 'always'"
curl -s -X POST "$BASE" \
    -H "Content-Type: application/json" \
    -d '{}'
echo ""
wait_deploy

sep
title "2. repo=frontend, branch=main — espera frontend+main y any-main"
curl -s -X POST "$BASE" \
    -H "Content-Type: application/json" \
    -d '{"repo":"frontend","branch":"main"}'
echo ""
wait_deploy

sep
title "3. repo=frontend, branch=staging — espera frontend+staging"
curl -s -X POST "$BASE" \
    -H "Content-Type: application/json" \
    -d '{"repo":"frontend","branch":"staging"}'
echo ""
wait_deploy

sep
title "4. repo=backend, branch=main — espera backend y any-main"
curl -s -X POST "$BASE" \
    -H "Content-Type: application/json" \
    -d '{"repo":"backend","branch":"main"}'
echo ""
wait_deploy

sep
title "5. Via query params GET — repo=frontend&branch=main"
curl -s -G "$BASE" \
    --data-urlencode "repo=frontend" \
    --data-urlencode "branch=main"
echo ""
wait_deploy

sep
echo -e "${GREEN}Tests completados. Revisá los logs en http://$HOST/alllogs${NC}"
