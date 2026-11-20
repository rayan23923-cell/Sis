#!/usr/bin/env bash
# Runs once when the codespace is created: dependencies, a build, and a SQLite database with demo data.
set -euo pipefail
cd "$(dirname "$0")/.."

composer install --no-interaction --prefer-dist --no-progress
npm ci --no-audit --no-fund
npm run build

[ -f .env ] || cp .env.example .env
url="https://${CODESPACE_NAME:-localhost}-8000.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN:-app.github.dev}"
set_env() { grep -q "^$1=" .env && sed -i "s|^$1=.*|$1=$2|" .env || echo "$1=$2" >> .env; }
set_env APP_ENV local
set_env APP_DEBUG true
set_env APP_URL "$url"
set_env ASSET_URL "$url"
set_env DB_CONNECTION sqlite
set_env SIS_DEMO_DATA true
set_env SIS_TWO_FACTOR_ROLES ""
set_env PAYMENTS_SANDBOX true
php artisan key:generate --force

touch database/database.sqlite
php artisan migrate:fresh --seed --force

# A demo student account for the portal (the first student), with the password written next to the admin's.
php artisan tinker --execute '
$s = App\Models\Student::orderBy("id")->first();
$svc = app(App\Services\StudentAccountService::class);
$pw = $svc->issue($s, App\Models\User::where("email", config("sis.admin.email"))->first());
App\Models\User::where("email", $svc->loginEmail($s))->update(["must_change_password" => false]);
Illuminate\Support\Facades\Storage::disk("local")->append("admin-password.txt", "student: ".$svc->loginEmail($s)."\nstudent password: ".$pw."\n");
'

echo "SIS is ready. Passwords: storage/app/private/admin-password.txt (kept out of the log on purpose)."
