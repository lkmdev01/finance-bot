#!/bin/sh

# Sair se houver erro
set -e

echo "Iniciando script de entrada..."

# Cache de configuração
echo "Gerando caches do Laravel..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Rodar migrations
echo "Rodando migrations..."
php artisan migrate --force

# Garantir permissões de storage
chmod -R 777 storage bootstrap/cache

# Iniciar o Supervisor
echo "Iniciando Supervisor..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
