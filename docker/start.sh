#!/bin/bash
set -e

echo "=== Starting Pinky ==="

# Ensure required storage subdirectories exist (in case a fresh volume was mounted).
# Coolify mounts a persistent volume over /var/www/html/storage, so the directories
# created at image build time are hidden behind it on first boot.
mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/testing \
    storage/logs \
    bootstrap/cache

# Re-apply ownership and permissions at runtime, AFTER volumes are mounted.
# Without this, the host-side ownership of the mounted volume leaks in and
# www-data can't write to storage/framework/cache (Spatie Permission breaks
# every authorization check, returning 500 on every authorized route).
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

# Drop any stale Laravel caches that may have been written by the wrong user
# during a previous boot before this fix landed.
rm -rf storage/framework/cache/data/* bootstrap/cache/*.php 2>/dev/null || true

php artisan storage:link 2>/dev/null || true
php artisan migrate --force 2>&1 || echo "Migration failed but continuing..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan permission:cache-reset 2>/dev/null || true

echo "=== Starting scheduler ==="
php artisan schedule:work >> storage/logs/scheduler.log 2>&1 &

echo "=== Ready ==="
exec apache2-foreground
