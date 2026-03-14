FROM php:8.3-fpm

# Instalar dependências do sistema
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    supervisor \
    nginx \
    nodejs \
    npm

# Limpar cache do apt
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Instalar extensões PHP
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Definir diretório de trabalho
WORKDIR /var/www/html

# Copiar projeto
COPY . .

# Instalar dependências do Laravel
RUN composer install --no-dev --optimize-autoloader

# Instalar dependências do WhatsApp (Node)
WORKDIR /var/www/html/whatsapp-service
RUN npm ci

# Voltar para o diretório raiz
WORKDIR /var/www/html

# Instalar e buildar dependências do Frontend (Vite)
RUN npm install
RUN npm run build

# Configurações do Supervisor
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Script de entrada
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Expor portas (8000 para Laravel, 3001 para o serviço de WhatsApp se necessário externamente)
EXPOSE 8000 3001

ENTRYPOINT ["entrypoint.sh"]
