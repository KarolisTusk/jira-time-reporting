# DigitalOcean App Platform Optimized Dockerfile
# Designed for Laravel 12 + Vue 3 + Queue Processing

FROM node:20-alpine AS frontend-builder

# Cache bust to force rebuild
ARG CACHE_BUST=2025-06-17-06-55

# Update npm to latest version
RUN npm install -g npm@11.4.2

WORKDIR /app

# Copy package files and install dependencies
COPY package*.json ./
RUN npm ci --only=production

# Copy source files and build frontend assets
COPY . .
RUN npm run build

# Production PHP image optimized for DigitalOcean
FROM php:8.2-fpm-alpine

# Cache bust to force rebuild of PHP layers
ARG CACHE_BUST=2025-06-17-06-55
RUN echo "PHP Cache bust: $CACHE_BUST"

# Install runtime dependencies and pre-built Redis extension
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    bash \
    libpng \
    libjpeg-turbo \
    freetype \
    icu \
    libzip \
    postgresql-client \
    libpq

# Install build dependencies and build PHP extensions (skip Redis - use pre-built)
RUN apk add --no-cache --virtual .build-deps \
    postgresql-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    icu-dev \
    oniguruma-dev \
    libzip-dev && \
    docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install \
        pdo \
        pdo_pgsql \
        gd \
        intl \
        mbstring \
        zip \
        bcmath \
        opcache \
        pcntl && \
    apk del .build-deps

# Install Redis extension via Alpine package (avoid compilation issues)
# Cache bust: 2025-06-17-07:10 - Force rebuild from this point
RUN apk add --no-cache php82-redis && \
    echo "extension=redis.so" > /usr/local/etc/php/conf.d/20-redis.ini && \
    echo "Redis extension installed successfully" && \
    php -m | grep redis || echo "Redis extension check failed"

# Install Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# Create application directory
WORKDIR /var/www/html

# Copy composer files and install dependencies
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Copy application files
COPY . .

# Copy built frontend assets from builder stage
COPY --from=frontend-builder /app/public/build ./public/build

# Create required directories and set proper permissions
RUN mkdir -p /var/www/html/bootstrap/cache \
    && mkdir -p /var/www/html/storage/app \
    && mkdir -p /var/www/html/storage/framework/cache \
    && mkdir -p /var/www/html/storage/framework/sessions \
    && mkdir -p /var/www/html/storage/framework/views \
    && mkdir -p /var/www/html/storage/logs \
    && mkdir -p /var/www/html/database \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/storage \
    && chmod -R 775 /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/database

# Copy DigitalOcean specific configuration
# Remove ALL default nginx configs and replace with ours
RUN rm -rf /etc/nginx/http.d/* /etc/nginx/conf.d/* && \
    rm -f /etc/nginx/nginx.conf
COPY docker/digitalocean/nginx.conf /etc/nginx/nginx.conf
COPY docker/digitalocean/default.conf /etc/nginx/http.d/default.conf
COPY docker/digitalocean/supervisord.conf /etc/supervisord.conf
COPY docker/digitalocean/php.ini /usr/local/etc/php/conf.d/99-digitalocean.ini

# Create system directories
RUN mkdir -p /var/log/supervisor \
    && mkdir -p /run/nginx

# Generate optimized autoloader
RUN composer dump-autoload --optimize

# Health check for DigitalOcean
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://localhost:8080/ || exit 1

# Expose DigitalOcean App Platform port
EXPOSE 8080

# Create startup script for DigitalOcean
RUN echo '#!/bin/bash' > /start.sh \
    && echo 'set -e' >> /start.sh \
    && echo '' >> /start.sh \
    && echo '# Ensure storage directories exist with proper permissions' >> /start.sh \
    && echo 'mkdir -p /var/www/html/storage/{app,framework,logs}' >> /start.sh \
    && echo 'mkdir -p /var/www/html/storage/framework/{cache,sessions,views}' >> /start.sh \
    && echo 'mkdir -p /var/www/html/bootstrap/cache' >> /start.sh \
    && echo '' >> /start.sh \
    && echo '# Set proper ownership and permissions' >> /start.sh \
    && echo 'chown -R www-data:www-data /var/www/html/storage' >> /start.sh \
    && echo 'chown -R www-data:www-data /var/www/html/bootstrap/cache' >> /start.sh \
    && echo 'chmod -R 775 /var/www/html/storage' >> /start.sh \
    && echo 'chmod -R 775 /var/www/html/bootstrap/cache' >> /start.sh \
    && echo '' >> /start.sh \
    && echo '# Handle SQLite database for testing' >> /start.sh \
    && echo 'if [ "${DB_CONNECTION}" = "sqlite" ]; then' >> /start.sh \
    && echo '  DB_PATH="${DB_DATABASE:-/var/www/html/database/database.sqlite}"' >> /start.sh \
    && echo '  mkdir -p "$(dirname "$DB_PATH")"' >> /start.sh \
    && echo '  touch "$DB_PATH"' >> /start.sh \
    && echo '  chown www-data:www-data "$DB_PATH"' >> /start.sh \
    && echo '  chmod 664 "$DB_PATH"' >> /start.sh \
    && echo '  echo "SQLite database created at $DB_PATH"' >> /start.sh \
    && echo 'fi' >> /start.sh \
    && echo '' >> /start.sh \
    && echo '# Database connectivity check' >> /start.sh \
    && echo 'echo "Checking database connection..."' >> /start.sh \
    && echo 'php artisan migrate:status --quiet 2>/dev/null || {' >> /start.sh \
    && echo '  echo "Database not ready or migrations needed, continuing..."' >> /start.sh \
    && echo '  sleep 3' >> /start.sh \
    && echo '}' >> /start.sh \
    && echo '' >> /start.sh \
    && echo '# Run Laravel optimization commands' >> /start.sh \
    && echo 'php artisan config:cache' >> /start.sh \
    && echo 'php artisan route:cache' >> /start.sh \
    && echo 'php artisan view:cache' >> /start.sh \
    && echo '' >> /start.sh \
    && echo '# Start services with supervisor' >> /start.sh \
    && echo 'exec /usr/bin/supervisord -c /etc/supervisord.conf' >> /start.sh \
    && chmod +x /start.sh

# Start with our custom script
CMD ["/start.sh"]