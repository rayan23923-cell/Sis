#!/bin/sh
# Prepares the application, then runs the given command. Only the php-fpm container migrates and seeds;
# the queue worker and scheduler wait for it (storage/app/.seeded).
set -e
cd /var/www/html
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/public storage/app/private

if [ "$1" = "php-fpm" ]; then
    echo "Waiting for the database..."
    tries=0
    until php -r 'try { new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT")?:3306).";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); } catch (Throwable $e) { exit(1); }'; do
        tries=$((tries + 1))
        [ "$tries" -ge 60 ] && { echo "Database did not come up." >&2; exit 1; }
        sleep 2
    done

    # G6a (B-13): migrations and seeding on the maintenance account, then the application account gets its
    # per-table grants (no DDL, audit_logs append-only).
    on=""
    [ -n "${DB_MAINT_USERNAME:-}" ] && on="--database=maintenance"
    php artisan migrate --force --no-interaction $on
    [ -n "$on" ] && php artisan sis:db-grants
    # First install only: unofficial starting policies, the administrator and (staging) demo data.
    # The admin password is written to storage/app/private/admin-password.txt, never to the log.
    if [ ! -f storage/app/.seeded ]; then
        php artisan db:seed --force --no-interaction $on
        touch storage/app/.seeded
    fi
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
    chown -R www-data:www-data storage bootstrap/cache
else
    tries=0
    until [ -f storage/app/.seeded ]; do
        tries=$((tries + 1))
        [ "$tries" -ge 150 ] && { echo "The app container did not finish preparing." >&2; exit 1; }
        sleep 2
    done
fi

# G6a: only the scheduler keeps the maintenance account (backups, trigger checks); the web and the queue drop it.
case "$*" in
    *schedule:work*) exec docker-php-entrypoint "$@" ;;
    *) exec env -u DB_MAINT_USERNAME -u DB_MAINT_PASSWORD docker-php-entrypoint "$@" ;;
esac
