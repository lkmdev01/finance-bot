#!/bin/bash

# Script para rodar multiplos processos em um unico servico no Coolify
set -e

QUEUE_CONNECTION_NAME="${QUEUE_CONNECTION:-database}"
QUEUE_NAME="${QUEUE_NAME:-${REDIS_QUEUE:-default}}"
QUEUE_WORKERS="${QUEUE_WORKERS:-2}"
QUEUE_WORKER_TRIES="${QUEUE_WORKER_TRIES:-3}"
QUEUE_WORKER_TIMEOUT="${QUEUE_WORKER_TIMEOUT:-120}"
QUEUE_WORKER_SLEEP="${QUEUE_WORKER_SLEEP:-0}"

echo "--- 1. Preparando Ambiente ---"
mkdir -p storage/logs
php artisan migrate --force
php artisan storage:link --quiet
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "--- 2. Iniciando Workers e Scheduler ---"
echo "Conexao da fila: $QUEUE_CONNECTION_NAME"
echo "Fila: $QUEUE_NAME"
echo "Workers: $QUEUE_WORKERS"

i=1
while [ "$i" -le "$QUEUE_WORKERS" ]; do
  php artisan queue:work "$QUEUE_CONNECTION_NAME" \
    --queue="$QUEUE_NAME" \
    --tries="$QUEUE_WORKER_TRIES" \
    --timeout="$QUEUE_WORKER_TIMEOUT" \
    --sleep="$QUEUE_WORKER_SLEEP" \
    > "storage/logs/worker-$i.log" 2>&1 &
  i=$((i + 1))
done

php artisan schedule:work > storage/logs/scheduler.log 2>&1 &

echo "--- 3. Iniciando WhatsApp Service ---"
cd whatsapp-service
echo "Instalando dependencias do WhatsApp..."
npm install --quiet
npm start &
cd ..

echo "--- 4. Iniciando Servidor Web Principal ---"
echo "Rodando na porta: $PORT"
php artisan serve --host=0.0.0.0 --port=$PORT
