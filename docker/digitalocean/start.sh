#!/bin/bash
set -e

# Startup script for JIRA Reporter - DigitalOcean App Platform
echo "=== JIRA Reporter Startup ==="

# Ensure storage directories exist with proper permissions
echo "Creating storage directories..."
mkdir -p /var/www/html/storage/{app,framework,logs}
mkdir -p /var/www/html/storage/framework/{cache,sessions,views}
mkdir -p /var/www/html/bootstrap/cache

# Set proper ownership and permissions
echo "Setting permissions..."
chown -R www-data:www-data /var/www/html/storage
chown -R www-data:www-data /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage
chmod -R 775 /var/www/html/bootstrap/cache

# Enhanced environment validation
echo "=== Environment Validation ==="
echo "DB_CONNECTION: ${DB_CONNECTION:-NOT SET}"
echo "DB_HOST: ${DB_HOST:-NOT SET}"
echo "DB_PORT: ${DB_PORT:-NOT SET}"
echo "DB_DATABASE: ${DB_DATABASE:-NOT SET}"
echo "APP_ENV: ${APP_ENV:-NOT SET}"
echo "APP_KEY: ${APP_KEY:0:20}..."

# Database connection validation for PostgreSQL
if [ "${DB_CONNECTION}" = "pgsql" ]; then
  echo "Validating PostgreSQL connection..."
  if [ -z "${DB_HOST}" ] || [ -z "${DB_DATABASE}" ] || [ -z "${DB_USERNAME}" ]; then
    echo "ERROR: Missing required database environment variables"
    echo "Required: DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD"
    exit 1
  fi
fi

# Handle SQLite database for testing (fallback)
if [ "${DB_CONNECTION}" = "sqlite" ]; then
  echo "Setting up SQLite database..."
  DB_PATH="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
  mkdir -p "$(dirname "$DB_PATH")"
  touch "$DB_PATH"
  chown www-data:www-data "$DB_PATH"
  chmod 664 "$DB_PATH"
  echo "SQLite database created at $DB_PATH"
fi

# Run Laravel optimization commands with better error handling
echo "=== Laravel Optimization ==="
php artisan config:clear && echo "✓ Config cleared" || echo "✗ Config clear failed"
php artisan config:cache && echo "✓ Config cached" || echo "✗ Config cache failed"
php artisan route:cache && echo "✓ Routes cached" || echo "✗ Route cache failed"
php artisan view:cache && echo "✓ Views cached" || echo "✗ View cache failed"

# Verify PHP extensions are loaded
echo "=== PHP Extensions Verification ==="
php -m | grep -E "(zip|pdo|pgsql|gd)" && echo "✓ Required extensions loaded" || echo "✗ Missing required extensions"

# Start services with supervisor
echo "=== Starting Services ==="
rm -f /var/run/supervisor.sock
mkdir -p /var/log/supervisor /run/nginx
chown -R www-data:www-data /run/nginx
echo "Starting supervisor..."
exec /usr/bin/supervisord -c /etc/supervisord.conf