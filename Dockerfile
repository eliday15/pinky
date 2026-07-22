# El stage final (la app PHP) sale de una imagen base pre-construida que vive en
# el registry local del server (localhost:5000/pinky-base). Esa base trae las
# extensiones de PHP ya compiladas, freetds, cloudflared y la config de Apache —
# todo lo que antes se rehacía en CADA deploy. La receta está en Dockerfile.base;
# para reconstruirla: ./docker/build-base.sh <version>.
ARG BASE_IMAGE=localhost:5000/pinky-base:php8.4-1

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

# Stage 3: PHP application.
# Sale de la base pre-construida (extensiones PHP, freetds, cloudflared y la
# config de Apache ya vienen adentro). Acá solo va lo que cambia por deploy.
FROM ${BASE_IMAGE} AS app

# FreeTDS server definitions (per-server TDS version: compaq 7.4, basemaquila 7.0)
# Se copia acá (y no en la base) para poder ajustar servidores/versiones TDS sin
# reconstruir la imagen base.
COPY docker/freetds.conf /etc/freetds/freetds.conf

# Custom PHP settings (memory limit, upload size, etc.) — también acá para tocar
# límites sin rebuildear la base.
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
