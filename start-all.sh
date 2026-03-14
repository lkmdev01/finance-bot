#!/bin/bash

# Script para rodar múltiplos processos em um único serviço no Coolify (sem Dockerfile)

echo "--- Iniciando Setup do Ambiente ---"

# 1. Rodar Migrations
echo "Rodando php artisan migrate --force..."
php artisan migrate --force

# 2. Linkar Storage
echo "Rodando php artisan storage:link..."
php artisan storage:link --quiet

# 3. Limpar e gerar caches
echo "Limpando caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# --- Iniciar Processos ---

echo "--- Iniciando Processos Background ---"

# Iniciar o Worker da Fila
echo "Iniciando Laravel Worker..."
php artisan queue:work --tries=3 --timeout=120 > storage/logs/worker.log 2>&1 &

# Iniciar o Agendador (Scheduler)
echo "Iniciando Laravel Scheduler..."
php artisan schedule:work > storage/logs/scheduler.log 2>&1 &

# Iniciar o WhatsApp Service (Node.js)
echo "Iniciando WhatsApp Service..."
cd whatsapp-service
npm install --quiet
npm start > ../storage/logs/whatsapp.log 2>&1 &
cd ..

echo "--- Iniciando Servidor Web Principal ---"

# Iniciar o Servidor Web (Este deve ser o processo principal, sem o & no final)
# O Coolify usa a variável $PORT automaticamente
php artisan serve --host=0.0.0.0 --port=$PORT
