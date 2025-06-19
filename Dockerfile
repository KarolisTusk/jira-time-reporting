# DigitalOcean App Platform Optimized Dockerfile
# Designed for Laravel 12 + Vue 3 + Queue Processing
# Fixed for Alpine 3.19 compatibility and proper dependency management

FROM node:20-alpine3.19 AS frontend-builder

# Cache bust to force clean rebuild
ARG CACHE_BUST=2025-06-19-16-00
RUN echo "Frontend Cache bust: $CACHE_BUST"

# Update npm to latest version
RUN npm install -g npm@11.4.2

WORKDIR /app

# Copy package files and install dependencies (include dev dependencies for build)
COPY package*.json ./
RUN npm ci

# Copy source files and build frontend assets
COPY . .
RUN npm run build

# Production PHP image optimized for DigitalOcean with Alpine 3.19
FROM php:8.2-fpm-alpine3.19

# Cache bust to force rebuild of PHP layers
ARG CACHE_BUST=2025-06-19-16-00
RUN echo "PHP Cache bust: $CACHE_BUST"

# Install essential system packages and runtime dependencies
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
    libpq \
    zlib

# Install build dependencies with complete toolchain for PHP extensions
RUN apk add --no-cache --virtual .build-deps \
    $PHPIZE_DEPS \
    autoconf \
    gcc \
    g++ \
    make \
    libc-dev \
    postgresql-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    icu-dev \
    oniguruma-dev \
    libzip-dev \
    zlib-dev \
    linux-headers

# Configure and install PHP extensions with proper error handling
RUN docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-configure zip && \
    docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_pgsql \
        gd \
        intl \
        mbstring \
        zip \
        bcmath \
        opcache \
        pcntl && \
    php -m | grep -E "(zip|pdo|gd|intl|mbstring|bcmath|opcache|pcntl)" && \
    echo "All PHP extensions installed successfully" && \
    apk del .build-deps

# Install Redis extension via PECL (more reliable than Alpine package)
RUN apk add --no-cache --virtual .redis-build-deps \
    $PHPIZE_DEPS && \
    pecl install redis && \
    docker-php-ext-enable redis && \
    apk del .redis-build-deps && \
    echo "Redis extension installed successfully" && \
    php -m | grep redis && echo "Redis extension verified" || echo "Redis extension check failed"

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

# Copy enhanced startup script
COPY docker/digitalocean/start.sh /start.sh
RUN chmod +x /start.sh

# Start with our custom script
CMD ["/start.sh"]