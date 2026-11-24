#!/bin/sh
# Builds and (re)starts SIS staging on the VPS. Run from the repo root on the server: sh deploy/vps/deploy.sh
# First run: creates .env with random secrets and copies the generated admin password to
# ~/sis-admin-password.txt (readable only by this user). Nothing secret is printed.
set -eu
cd "$(dirname "$0")/../.."

# The AWS server adds its own Caddy on top (deploy/aws/deploy.sh sets SIS_COMPOSE_EXTRA).
extra="${SIS_COMPOSE_EXTRA:+-f $SIS_COMPOSE_EXTRA}"
compose() { sudo docker compose --env-file .env -f deploy/vps/docker-compose.yml $extra "$@"; }
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
# The site address when it is not the VPS staging one (AWS); set on every run so a new domain takes effect.
if [ -n "${SIS_APP_URL:-}" ]; then
    sed -i "s|^APP_URL=.*|APP_URL=$SIS_APP_URL|" .env
    sed -i '/^SIS_DOMAIN=/d' .env
    printf '\nSIS_DOMAIN=%s\n' "$SIS_DOMAIN" >> .env
fi
# Settings added after the first install (an existing value is never changed).
grep -q '^PAYMENTS_SANDBOX=' .env || printf '\nPAYMENTS_SANDBOX=true\n' >> .env
# G6a (B-13): the maintenance account (migrations, grants, backups, scheduler), separate from the application's.
grep -q '^DB_MAINT_USERNAME=' .env || printf '\nDB_MAINT_USERNAME=sis_maint\n' >> .env
grep -q '^DB_MAINT_PASSWORD=.' .env || { sed -i '/^DB_MAINT_PASSWORD=$/d' .env; (umask 077; printf 'DB_MAINT_PASSWORD=%s\n' "$(rand 32)" >> .env); }
# The apps and the offline server package (uploaded by their workflows); the containers read it as another user.
mkdir -p public/downloads
chmod 755 public public/downloads
find public/downloads -maxdepth 1 -type f -exec chmod 644 {} +

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
# G6a: the maintenance account exists (and has its current password) before the app migrates with it.
compose up -d --wait db
db_name=$(sed -n 's/^DB_DATABASE=//p' .env)
maint_user=$(sed -n 's/^DB_MAINT_USERNAME=//p' .env)
maint_pw=$(sed -n 's/^DB_MAINT_PASSWORD=//p' .env)
printf "CREATE USER IF NOT EXISTS '%s'@'%%' IDENTIFIED BY '%s';\nALTER USER '%s'@'%%' IDENTIFIED BY '%s';\nGRANT ALL PRIVILEGES ON \`%s\`.* TO '%s'@'%%' WITH GRANT OPTION;\n" \
    "$maint_user" "$maint_pw" "$maint_user" "$maint_pw" "$db_name" "$maint_user" \
    | sudo docker exec -i sis-db sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD"' \
    || { echo "Could not set up the maintenance database account; stopping before the update." >&2; exit 1; }
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

# On the VPS the site is added to the shared Caddy; on AWS SIS runs its own (deploy/aws/docker-compose.aws.yml).
[ "${SIS_OWN_CADDY:-}" = 1 ] || sh deploy/vps/caddy-site.sh

echo "PHP $(sudo docker exec sis-app php -r 'echo PHP_VERSION;')"
compose ps --format '{{.Name}}  {{.Status}}'
