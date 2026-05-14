#!/usr/bin/env bash
# test-artifact.sh — prueba los casos de 'when' en artifact-deploy.json
# Usa --skip-download para evitar la descarga real de GitLab.
# Uso: ./test-artifact.sh [host]

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOST="${1:-localhost:5173}"
FIXTURE="$SCRIPT_DIR/fixtures/artifact-fixture.zip"
INDEX="$SCRIPT_DIR/index.php"

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
RED='\033[0;31m'
NC='\033[0m'

sep()   { echo -e "\n${CYAN}══════════════════════════════════════════${NC}"; }
title() { echo -e "${YELLOW}▶ $1${NC}"; }
ok()    { echo -e "  ${GREEN}✓ $1${NC}"; }
err()   { echo -e "  ${RED}✗ $1${NC}"; }

wait_finished() {
    local max=60
    local i=0
    while [ $i -lt $max ]; do
        local finished
        finished=$(curl -s "http://$HOST/status/check" | grep -o '"finished":true')
        [ -n "$finished" ] && return 0
        sleep 1
        i=$((i + 1))
    done
    return 1
}

run_artifact() {
    local label="$1"
    local context_json="$2"
    local ctx_b64
    ctx_b64=$(echo -n "$context_json" | base64 -w0)

    sep
    title "$label"
    echo "  context: $context_json"

    # Invoca el CLI directamente con --skip-download
    php "$INDEX" run-artifact-deploy "$HOST" "$ctx_b64" fake-project main fake-job fake-job-id \
        --skip-download "$FIXTURE"

    local exit_code=$?
    if [ $exit_code -eq 0 ]; then
        ok "Finished (exit 0)"
    else
        err "Failed (exit $exit_code)"
    fi

    # Espera a que el status quede libre antes del siguiente caso
    wait_finished || err "Timeout esperando finished"
}

echo -e "\n${CYAN}=== test-artifact.sh ===${NC}"
echo "  fixture : $FIXTURE"
echo "  host    : $HOST"

# --- Caso 1: type=frontend ---
run_artifact "CASO 1: type=frontend (solo tareas frontend deben ejecutarse)" \
    '{"type":"frontend"}'

# --- Caso 2: type=backend ---
run_artifact "CASO 2: type=backend (solo tareas backend deben ejecutarse)" \
    '{"type":"backend"}'

# --- Caso 3: sin type (todas las when deben saltar, solo 'common' corre) ---
run_artifact "CASO 3: sin type (tareas con when=skipped, common corre)" \
    '{}'

sep
echo -e "\n${GREEN}Tests finalizados. Revisá los logs en http://$HOST/logs${NC}\n"
