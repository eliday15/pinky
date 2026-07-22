# Stage 1: Install PHP dependencies (needed for Ziggy during Vite build)
FROM php:8.4-cli AS composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update && apt-get install -y git unzip && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction --ignore-platform-reqs

# Stage 2: Build frontend assets
FROM node:20-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci
COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY resources/ resources/
COPY public/ public/
COPY --from=composer /app/vendor vendor/
RUN npm run build

# Stage 3: PHP application
FROM php:8.4-apache

# Install system dependencies.
# freetds-dev + pdo_dblib give Laravel a SQL Server driver (FreeTDS) able to
# speak legacy TDS 7.0, which the on-prem SQL Server 2014 (basemaquila)
# requires and the Microsoft sqlsrv driver cannot negotiate.
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libxml2-dev \
    libonig-dev \
    unzip \
    freetds-dev \
    freetds-bin \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-configure pdo_dblib --with-libdir=lib/$(gcc -dumpmachine) \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip xml pdo_dblib \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Cloudflared: opens the TCP tunnels to the on-prem SQL Servers (started in
# start.sh as `cloudflared access tcp`). Static binary matched to the arch.
RUN ARCH="$(dpkg --print-architecture)" \
    && curl -fL "https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-${ARCH}" \
       -o /usr/local/bin/cloudflared \
    && chmod +x /usr/local/bin/cloudflared

# FreeTDS server definitions (per-server TDS version: compaq 7.4, basemaquila 7.0)
COPY docker/freetds.conf /etc/freetds/freetds.conf

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Configure Apache to serve from /public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Allow .htaccess overrides
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Custom PHP settings (memory limit, upload size, etc.)
COPY docker/php.ini /usr/local/etc/php/conf.d/99-custom.ini

WORKDIR /var/www/html

# Copy application code
COPY . .

# Copy vendor from composer stage
COPY --from=composer /app/vendor vendor/

# Copy built frontend assets from frontend stage
COPY --from=frontend /app/public/build public/build

# Run post-install scripts
RUN php artisan package:discover --ansi 2>/dev/null || true

# Set permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Create startup script
COPY docker/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

EXPOSE 80

CMD ["/usr/local/bin/start.sh"]
