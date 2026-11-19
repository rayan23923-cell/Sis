#!/bin/sh
# Builds and (re)starts SIS staging on the VPS. Run from the repo root on the server: sh deploy/vps/deploy.sh
# First run: creates .env with random secrets and copies the generated admin password to
# ~/sis-admin-password.txt (readable only by this user). Nothing secret is printed.
set -eu
cd "$(dirname "$0")/../.."

compose() { sudo docker compose --env-file .env -f deploy/vps/docker-compose.yml "$@"; }
rand() { head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c "$1"; }

if [ ! -f .env ]; then
    umask 077
    sed -e "s|^APP_KEY=.*|APP_KEY=base64:$(head -c 32 /dev/urandom | base64)|" \
        -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$(rand 32)|" \
        -e "s|^DB_ROOT_PASSWORD=.*|DB_ROOT_PASSWORD=$(rand 32)|" \
        deploy/vps/env.example > .env
    umask 022
    echo "Created .env with fresh secrets."
fi
# Settings added after the first install (an existing value is never changed).
grep -q '^PAYMENTS_SANDBOX=' .env || printf '\nPAYMENTS_SANDBOX=true\n' >> .env

# Database backup before every update (last 20 kept in ~/sis-backups). A failed backup stops the deploy.
if sudo docker ps --format '{{.Names}}' | grep -qx sis-db; then
    backups="$HOME/sis-backups"
    stamp="$(date +%Y%m%d_%H%M%S)"
    mkdir -p "$backups"
    sudo docker exec sis-db sh -c 'exec mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction --routines "$MARIADB_DATABASE"' \
        | gzip > "$backups/db_$stamp.sql.gz"
    gzip -dc "$backups/db_$stamp.sql.gz" | grep -q 'CREATE TABLE' \
        || { echo "Database backup failed; stopping before the update." >&2; rm -f "$backups/db_$stamp.sql.gz"; exit 1; }
    ls -1t "$backups"/db_*.sql.gz | tail -n +21 | xargs -r rm -f
    echo "Backed up to $backups (db_$stamp.sql.gz)."
fi

# The placeholder page served before the app existed.
sudo docker rm -f sis-web >/dev/null 2>&1 || true

compose build --pull
compose up -d --remove-orphans

echo "Waiting for the app to finish migrating..."
tries=0
until sudo docker exec sis-app test -f storage/app/.seeded 2>/dev/null; do
    tries=$((tries + 1))
    [ "$tries" -ge 150 ] && { echo "App did not finish starting; see: sudo docker logs sis-app" >&2; exit 1; }
    sleep 2
done
compose restart queue scheduler >/dev/null

if [ ! -f "$HOME/sis-admin-password.txt" ] && sudo docker exec sis-app test -f storage/app/private/admin-password.txt; then
    (umask 077; sudo docker exec sis-app cat storage/app/private/admin-password.txt > "$HOME/sis-admin-password.txt")
    echo "Admin password saved to ~/sis-admin-password.txt"
fi

sh deploy/vps/caddy-site.sh

echo "PHP $(sudo docker exec sis-app php -r 'echo PHP_VERSION;')"
compose ps --format '{{.Name}}  {{.Status}}'
