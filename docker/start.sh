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

# Run artisan commands as www-data so any cache files they create (config,
# route, view, permission caches) are owned by the same user Apache runs as.
# Otherwise root-owned cache files leak in and break later HTTP writes.
run_as_web() {
    su -s /bin/bash www-data -c "$*"
}

run_as_web "php artisan storage:link" 2>/dev/null || true
run_as_web "php artisan migrate --force" 2>&1 || echo "Migration failed but continuing..."
# Re-seed roles/permissions (idempotente: firstOrCreate + syncPermissions) para
# que permisos nuevos como payroll.pay_cash existan tras cada deploy sin un paso
# manual. NO crea usuarios ni toca asignaciones usuario->rol.
run_as_web "php artisan db:seed --class=RolesPermissionsSeeder --force" 2>&1 || echo "Roles/permissions seed failed but continuing..."
# Config fiscal (tarifa ISR, subsidio, UMA, % IMSS): idempotente. El flag de
# retenciones queda apagado hasta activarlo manualmente en Configuración.
run_as_web "php artisan db:seed --class=FiscalSettingsSeeder --force" 2>&1 || echo "Fiscal seed failed but continuing..."
# Concepto Aguinaldo (pagado por transferencia): idempotente (firstOrCreate por
# código), no pisa el concepto si ya existe.
run_as_web "php artisan db:seed --class=AguinaldoConceptSeeder --force" 2>&1 || echo "Aguinaldo concept seed failed but continuing..."
run_as_web "php artisan config:cache"
run_as_web "php artisan route:cache"
run_as_web "php artisan view:cache"
run_as_web "php artisan permission:cache-reset" 2>/dev/null || true

echo "=== Starting scheduler ==="
# Scheduler MUST run as www-data; otherwise it writes cache files as root and
# Apache workers (www-data) get "Permission denied" when they later try to
# overwrite the same hashed cache subdirectory.
su -s /bin/bash www-data -c "php artisan schedule:work >> storage/logs/scheduler.log 2>&1" &

echo "=== Starting queue worker ==="
# Worker de colas (QUEUE_CONNECTION=database): lo necesitan los jobs de
# timbrado CFDI (StampPayrollCfdi) y el sync ZKTeco. Loop de reinicio simple:
# si el worker muere, se relanza en 5s; --max-time=3600 recicla el proceso
# cada hora contra fugas de memoria. Mismo patrón www-data que el scheduler.
su -s /bin/bash www-data -c "while true; do php artisan queue:work --tries=3 --timeout=180 --max-time=3600 >> storage/logs/queue.log 2>&1; sleep 5; done" &

# Stream Laravel log to stderr so errors appear in Coolify's log viewer
touch storage/logs/laravel.log
tail -f storage/logs/laravel.log >&2 &

echo "=== Ready ==="
exec apache2-foreground
