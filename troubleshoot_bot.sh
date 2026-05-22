#!/bin/bash
# Script para diagnosticar problemas do bot WhatsApp no VPS
# Execute: bash troubleshoot_bot.sh

echo "====== TROUBLESHOOTING BOT WHATSAPP ======"
echo ""

# 1. Verificar se PHP está instalado
echo "1️⃣ Verificando PHP..."
php -v | head -1
echo ""

# 2. Verificar conexão com banco de dados
echo "2️⃣ Testando banco de dados..."
php artisan tinker --execute="echo 'Usuários: ' . \App\Models\User::count();"
echo ""

# 3. Executar comando de debug
echo "3️⃣ Executando debug do bot..."
php artisan debug:bot
echo ""

# 4. Mostrar últimas linhas do log
echo "4️⃣ Últimas 20 linhas do log:"
tail -20 storage/logs/laravel.log
echo ""

# 5. Verificar permissões
echo "5️⃣ Verificando permissões..."
ls -la storage/logs/
echo ""

# 6. Verificar fila
echo "6️⃣ Verificando fila..."
php artisan queue:work --max-attempts=1 --timeout=10 --stop-when-empty
