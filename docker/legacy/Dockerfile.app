# Simple Dockerfile for DigitalOcean App Platform
# Laravel 12 + Vue 3 + PostgreSQL

FROM php:8.2-cli-alpine

# Install system dependencies
RUN apk add --no-cache \
    postgresql-dev \
    zip \
    unzip \
    git \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    icu-dev \
    oniguruma-dev \
    libzip-dev \
    nodejs \
    npm \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        gd \
        intl \
        mbstring \
        zip \
        bcmath

# Install Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy composer files and install dependencies
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Copy package files and install frontend dependencies
COPY package*.json ./
RUN npm ci --only=production

# Copy application files
COPY . .

# Build frontend assets
RUN npm run build

# Generate optimized autoloader
RUN composer dump-autoload --optimize

# Set permissions
RUN chmod -R 755 storage bootstrap/cache

# Expose port 8080 for DigitalOcean App Platform
EXPOSE 8080

# Start Laravel development server on port 8080
CMD php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=8080