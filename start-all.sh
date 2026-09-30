#!/bin/bash

# Entrada unica de producao para Nixpacks e Docker/Coolify.
set -Eeuo pipefail

QUEUE_CONNECTION_NAME="${QUEUE_CONNECTION:-database}"
QUEUE_NAME="${QUEUE_NAME:-${REDIS_QUEUE:-default}}"
QUEUE_WORKERS="${QUEUE_WORKERS:-2}"
QUEUE_WORKER_TRIES="${QUEUE_WORKER_TRIES:-3}"
QUEUE_WORKER_TIMEOUT="${QUEUE_WORKER_TIMEOUT:-120}"
QUEUE_WORKER_SLEEP="${QUEUE_WORKER_SLEEP:-0}"
APP_PORT="${PORT:-8000}"
RUN_BILLING_SMOKE="${RUN_BILLING_SMOKE:-true}"
CHILD_PIDS=()

case "$QUEUE_WORKERS" in
  ''|*[!0-9]*|0)
    echo "QUEUE_WORKERS deve ser um inteiro maior que zero." >&2
    exit 1
    ;;
esac

run_supervised() {
  local process_name="$1"
  local process_log="$2"
  local process_pid=''
  local exit_code=0
  shift 2

  stop_process() {
    if [ -n "$process_pid" ] && kill -0 "$process_pid" 2>/dev/null; then
      kill -TERM "$process_pid" 2>/dev/null || true
      wait "$process_pid" 2>/dev/null || true
    fi
    exit 0
  }

  trap stop_process TERM INT

  while true; do
    echo "$(date -Iseconds) Iniciando $process_name" >> "$process_log"
    "$@" >> "$process_log" 2>&1 &
    process_pid=$!

    if wait "$process_pid"; then
      exit_code=0
    else
      exit_code=$?
    fi
    process_pid=''

    echo "$(date -Iseconds) $process_name encerrou com codigo $exit_code; reiniciando em 2s" >> "$process_log"
    sleep 2
  done
}

start_supervised() {
  run_supervised "$@" &
  CHILD_PIDS+=("$!")
}

shutdown() {
  local pid
  trap - EXIT TERM INT

  for pid in "${CHILD_PIDS[@]}"; do
    kill -TERM "$pid" 2>/dev/null || true
  done

  for pid in "${CHILD_PIDS[@]}"; do
    wait "$pid" 2>/dev/null || true
  done
}

trap shutdown EXIT TERM INT

echo "--- 1. Preparando Ambiente ---"
mkdir -p storage/logs
php artisan migrate --force
php artisan storage:link --quiet
php artisan config:cache
php artisan route:cache
php artisan view:cache

if [ "$RUN_BILLING_SMOKE" = "true" ]; then
  echo "--- 1.1 Validando configuracao comercial/billing ---"
  php artisan billing:smoke
fi

echo "--- 2. Iniciando Workers e Scheduler ---"
echo "Conexao da fila: $QUEUE_CONNECTION_NAME"
echo "Fila: $QUEUE_NAME"
echo "Workers: $QUEUE_WORKERS"

php artisan queue:restart

i=1
while [ "$i" -le "$QUEUE_WORKERS" ]; do
  start_supervised \
    "worker $i" \
    "storage/logs/worker-$i.log" \
    php artisan queue:work "$QUEUE_CONNECTION_NAME" \
      --queue="$QUEUE_NAME" \
      --tries="$QUEUE_WORKER_TRIES" \
      --timeout="$QUEUE_WORKER_TIMEOUT" \
      --sleep="$QUEUE_WORKER_SLEEP"
  i=$((i + 1))
done

start_supervised scheduler storage/logs/scheduler.log php artisan schedule:work

echo "--- 3. Iniciando WhatsApp Service ---"
start_supervised whatsapp storage/logs/whatsapp-service.log npm --prefix whatsapp-service start

# Inicializa o probe depois que os workers ja foram iniciados.
php artisan queue:heartbeat || true

echo "--- 4. Iniciando Servidor Web Principal ---"
echo "Rodando na porta: $APP_PORT"
php artisan serve --host=0.0.0.0 --port="$APP_PORT" &
WEB_PID=$!
CHILD_PIDS+=("$WEB_PID")

if wait "$WEB_PID"; then
  WEB_EXIT_CODE=0
else
  WEB_EXIT_CODE=$?
fi

echo "Servidor web encerrou com codigo $WEB_EXIT_CODE; encerrando o container." >&2
exit "$WEB_EXIT_CODE"
