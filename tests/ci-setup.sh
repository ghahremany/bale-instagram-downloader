#!/usr/bin/env bash
# Prepare an installed instance inside CI (no web installer).
#
#   DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=bale_test DB_USER=root DB_PASS=root ./tests/ci-setup.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-bale_test}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS-root}"   # may legitimately be empty

cd "$ROOT"
mkdir -p storage/logs storage/cache storage/tmp storage/otp
chmod -R 770 storage

KEY=$(php -r 'echo bin2hex(random_bytes(32));')
SECRET=$(php -r 'echo bin2hex(random_bytes(24));')
cat > .env <<ENV
APP_NAME="Bale Instagram Downloader"
APP_ENV=production
APP_DEBUG=false
APP_URL=http://127.0.0.1:8080
APP_TIMEZONE=Asia/Tehran
APP_KEY=${KEY}
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT}
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS="${DB_PASS}"
BALE_BOT_TOKEN=""
BALE_API_BASE=http://127.0.0.1:9099
BALE_WEBHOOK_SECRET=${SECRET}
BALE_ADMIN_IDS=""
IG_API_KEY=""
IG_API_URL=http://127.0.0.1:9098/instagram
BOT_MODE=polling
ALLOW_INSECURE_ENDPOINTS=true
ENV
chmod 600 .env

DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" DB_NAME="$DB_NAME" DB_USER="$DB_USER" DB_PASS="$DB_PASS" \
php -r '
require "app/bootstrap.php";
[$h, $p, $d, $u, $w] = [getenv("DB_HOST"), (int) getenv("DB_PORT"), getenv("DB_NAME"), getenv("DB_USER"), getenv("DB_PASS")];
$root = App\Db::connect($h, $p, $u, $w);
$root->exec("CREATE DATABASE IF NOT EXISTS `" . str_replace("`", "", $d) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db = App\Db::connect($h, $p, $u, $w, $d);
foreach (App\Installer::splitSql((string) file_get_contents("database/schema.sql")) as $sql) {
    $db->exec($sql);
}
echo "schema ok\n";
'

CODE=$(DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" DB_NAME="$DB_NAME" DB_USER="$DB_USER" DB_PASS="$DB_PASS" \
php -r '
require "app/bootstrap.php";
use App\{Auth, Db, Otp, Settings};
Db::run(
    "INSERT INTO admins (username, password_hash, display_name, role, is_active, must_change_password, created_at, updated_at)
     VALUES (?, ?, ?, \"owner\", 1, 0, ?, ?)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), must_change_password = 0, is_active = 1",
    ["admin", Auth::hashPassword("CiTestPassword123"), "CI", now_utc(), now_utc()]
);
Db::run("DELETE FROM setup_tokens WHERE purpose = ?", ["first_login"]);
Settings::set("otp_delivery", "file");
Settings::set("require_2fa", "1");
Settings::flush();
echo Otp::createActivationCode(1)["code"];
')

echo "{\"installed_at\":\"$(date -u +%FT%TZ)\",\"version\":\"ci\",\"admin\":\"admin\"}" > storage/installed.lock
chmod 640 storage/installed.lock
echo "activation code: $CODE"
echo "CI setup complete"
