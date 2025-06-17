# DigitalOcean App Platform Optimized Dockerfile (PRODUCTION VERSION)
# Designed for Laravel 12 + Vue 3 + Queue Processing
# Compatible with ubuntu-22 buildpack

FROM node:20-slim AS frontend-builder

# Cache bust to force complete rebuild - 2025-06-17-07:15
ARG CACHE_BUST=2025-06-17-07-15
RUN echo "Frontend Cache bust: $CACHE_BUST"

# Update npm to latest version and install dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && npm install -g npm@11.4.2

WORKDIR /app

# Copy package files and install dependencies
COPY package*.json ./
RUN npm ci --only=production

# Copy source files and build frontend assets
COPY . .
RUN npm run build

# Production PHP image optimized for DigitalOcean (Ubuntu-based)
FROM php:8.2-fpm

# Cache bust to force rebuild of PHP layers - 2025-06-17-07:15
ARG CACHE_BUST=2025-06-17-07-15
RUN echo "PHP Cache bust: $CACHE_BUST - Ubuntu-based build"

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx \
    supervisor \
    curl \
    bash \
    unzip \
    libpng16-16 \
    libjpeg62-turbo \
    libfreetype6 \
    libicu70 \
    libzip4 \
    libpq5 \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Install build dependencies and PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libicu-dev \
    libonig-dev \
    libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_pgsql \
        gd \
        intl \
        mbstring \
        zip \
        bcmath \
        opcache \
        pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove \
        libpq-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libicu-dev \
        libonig-dev \
        libzip-dev \
    && rm -rf /var/lib/apt/lists/* \
    && echo "Redis extension installed successfully" \
    && php -m | grep redis || echo "Redis extension check failed"

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
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

# Copy DigitalOcean specific configuration (Ubuntu paths)
COPY docker/digitalocean/nginx.conf /etc/nginx/nginx.conf
COPY docker/digitalocean/default.conf /etc/nginx/sites-available/default
COPY docker/digitalocean/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/digitalocean/php.ini /usr/local/etc/php/conf.d/99-digitalocean.ini

# Enable nginx site and remove default
RUN ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default \
    && rm -f /etc/nginx/sites-enabled/default.conf

# Create system directories for Ubuntu
RUN mkdir -p /var/log/supervisor \
    && mkdir -p /var/run/nginx \
    && mkdir -p /etc/supervisor/conf.d

# Generate optimized autoloader and verify installation
RUN composer dump-autoload --optimize \
    && echo "=== Build Verification ===" \
    && echo "PHP Extensions:" \
    && php -m | grep -E "(redis|pdo|gd|zip|intl|mbstring)" \
    && echo "" \
    && echo "Laravel Status:" \
    && php artisan --version \
    && echo "" \
    && echo "Frontend Assets:" \
    && ls -la /var/www/html/public/build/ \
    && echo "" \
    && echo "Configuration Files:" \
    && ls -la /etc/nginx/sites-available/ \
    && ls -la /etc/supervisor/ \
    && echo "Build verification completed successfully"

# Health check for DigitalOcean
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://localhost:8080/ || exit 1

# Expose DigitalOcean App Platform port
EXPOSE 8080

# Create comprehensive startup script with logging
RUN echo '#!/bin/bash' > /start.sh \
    && echo 'set -e' >> /start.sh \
    && echo 'set -x' >> /start.sh \
    && echo '' >> /start.sh \
    && echo '# Build verification and logging' >> /start.sh \
    && echo 'echo "=== DigitalOcean Deployment Startup ==="' >> /start.sh \
    && echo 'echo "Date: $(date)"' >> /start.sh \
    && echo 'echo "PHP Version: $(php --version | head -n1)"' >> /start.sh \
    && echo 'echo "Nginx Version: $(nginx -v 2>&1)"' >> /start.sh \
    && echo 'echo "Extensions: $(php -m | grep -E "(redis|pdo|gd|zip)" | tr "\n" ", ")"' >> /start.sh \
    && echo 'echo ""' >> /start.sh \
    && echo '' >> /start.sh \
    && echo '# Verify directory structure' >> /start.sh \
    && echo 'echo "=== Directory Verification ==="' >> /start.sh \
    && echo 'ls -la /var/www/html/' >> /start.sh \
    && echo 'ls -la /var/www/html/storage/' >> /start.sh \
    && echo 'ls -la /var/www/html/bootstrap/' >> /start.sh \
    && echo 'echo ""' >> /start.sh \
    && echo '' >> /start.sh \
    && echo '# Set proper permissions with verification' >> /start.sh \
    && echo 'echo "=== Setting Permissions ==="' >> /start.sh \
    && echo 'chown -R www-data:www-data /var/www/html/storage' >> /start.sh \
    && echo 'chown -R www-data:www-data /var/www/html/bootstrap/cache' >> /start.sh \
    && echo 'chmod -R 755 /var/www/html/storage' >> /start.sh \
    && echo 'chmod -R 755 /var/www/html/bootstrap/cache' >> /start.sh \
    && echo 'echo "Permissions set successfully"' >> /start.sh \
    && echo '' >> /start.sh \
    && echo '# Database connectivity test' >> /start.sh \
    && echo 'echo "=== Database Connection Test ==="' >> /start.sh \
    && echo 'php artisan migrate:status --quiet && echo "Database connected" || echo "Database connection failed"' >> /start.sh \
    && echo '' >> /start.sh \
    && echo '# Laravel optimization commands' >> /start.sh \
    && echo 'echo "=== Laravel Optimization ==="' >> /start.sh \
    && echo 'php artisan config:cache && echo "Config cached"' >> /start.sh \
    && echo 'php artisan route:cache && echo "Routes cached"' >> /start.sh \
    && echo 'php artisan view:cache && echo "Views cached"' >> /start.sh \
    && echo '' >> /start.sh \
    && echo '# Start services' >> /start.sh \
    && echo 'echo "=== Starting Services ==="' >> /start.sh \
    && echo 'echo "Starting supervisor with PHP-FPM and Nginx..."' >> /start.sh \
    && echo 'exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf' >> /start.sh \
    && chmod +x /start.sh

# Start with our custom script
CMD ["/start.sh"]