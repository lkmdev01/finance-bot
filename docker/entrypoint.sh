#!/bin/sh

set -e

# Docker e Nixpacks compartilham a mesma entrada para evitar configuracoes
# diferentes de worker, scheduler e WhatsApp entre ambientes.
exec /bin/bash /var/www/html/start-all.sh
