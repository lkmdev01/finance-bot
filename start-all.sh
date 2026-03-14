#!/bin/bash

# Script para rodar múltiplos processos em um único serviço no Coolify
set -e

echo "--- 1. Preparando Ambiente ---"
mkdir -p storage/logs
php artisan migrate --force
php artisan storage:link --quiet
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "--- 2. Iniciando Worker e Scheduler ---"
# Rodar worker e scheduler em background
php artisan queue:work --tries=3 --timeout=120 > storage/logs/worker.log 2>&1 &
php artisan schedule:work > storage/logs/scheduler.log 2>&1 &

echo "--- 3. Iniciando WhatsApp Service ---"
# Entrar na pasta do WhatsApp, garantir dependências e iniciar
cd whatsapp-service
echo "Instalando dependências do WhatsApp..."
npm install --quiet
# Garante que o Node rode na porta correta internally se necessário
npm start > ../storage/logs/whatsapp.log 2>&1 &
cd ..

echo "--- 4. Iniciando Servidor Web Principal ---"
# O Nixpacks exige que o processo principal rode na porta definida pela variável $PORT
# Se a porta original no painel foi 3000, o $PORT será 3000. Se mudamos para 8000, será 8000.
echo "Rodando na porta: $PORT"
php artisan serve --host=0.0.0.0 --port=$PORT
