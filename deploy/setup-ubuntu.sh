#!/usr/bin/env bash
# One-time server setup for Clipper Studio on Ubuntu 22.04 / 24.04.
# Run as root (sudo -i). Read it first — it installs packages system-wide.
#
#   DOMAIN=clipper.example.com EMAIL=you@example.com bash deploy/setup-ubuntu.sh
#
set -euo pipefail

: "${DOMAIN:?set DOMAIN=your.domain}"
: "${EMAIL:?set EMAIL=you@example.com for certificate notices}"
APP_DIR="${APP_DIR:-/var/www/clipper}"
PHP_VERSION="${PHP_VERSION:-8.4}"
DB_NAME="${DB_NAME:-clipper}"
DB_USER="${DB_USER:-clipper}"

echo "==> Packages: PHP ${PHP_VERSION}, nginx, PostgreSQL, ffmpeg, supervisor, certbot"
apt-get update
apt-get install -y software-properties-common curl unzip git ca-certificates gnupg
add-apt-repository -y ppa:ondrej/php
apt-get update
apt-get install -y nginx postgresql ffmpeg supervisor certbot python3-certbot-nginx \
  "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-pgsql" "php${PHP_VERSION}-gd" \
  "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-zip" \
  "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-intl"

echo "==> Composer"
command -v composer >/dev/null || curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

echo "==> Node.js 22 (build CSS)"
if ! command -v node >/dev/null; then
  curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
  apt-get install -y nodejs
fi

echo "==> yt-dlp (standalone binary) + Deno (yt-dlp needs a JS runtime for YouTube)"
curl -fsSL https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /usr/local/bin/yt-dlp
chmod a+rx /usr/local/bin/yt-dlp
if ! command -v deno >/dev/null; then
  curl -fsSL https://deno.land/install.sh | DENO_INSTALL=/usr/local sh -s -- -y
fi

echo "==> PostgreSQL database ${DB_NAME} / user ${DB_USER}"
DB_PASS="$(openssl rand -base64 24 | tr -d '/+=')"
sudo -u postgres psql -tc "SELECT 1 FROM pg_roles WHERE rolname='${DB_USER}'" | grep -q 1 \
  || sudo -u postgres psql -c "CREATE USER ${DB_USER} WITH PASSWORD '${DB_PASS}';"
sudo -u postgres psql -tc "SELECT 1 FROM pg_database WHERE datname='${DB_NAME}'" | grep -q 1 \
  || sudo -u postgres createdb -O "${DB_USER}" "${DB_NAME}"
echo "    DB password (put in .env as DB_PASSWORD): ${DB_PASS}"

echo "==> PHP limits for uploads and long renders"
cp "$(dirname "$0")/php-clipper.ini" "/etc/php/${PHP_VERSION}/fpm/conf.d/99-clipper.ini"
cp "$(dirname "$0")/php-clipper.ini" "/etc/php/${PHP_VERSION}/cli/conf.d/99-clipper.ini"
systemctl restart "php${PHP_VERSION}-fpm"

echo "==> nginx site for ${DOMAIN}"
sed -e "s#__DOMAIN__#${DOMAIN}#g" -e "s#__APP_DIR__#${APP_DIR}#g" -e "s#__PHP_VERSION__#${PHP_VERSION}#g" \
  "$(dirname "$0")/nginx.conf" > /etc/nginx/sites-available/clipper
ln -sf /etc/nginx/sites-available/clipper /etc/nginx/sites-enabled/clipper
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

echo "==> HTTPS certificate (domain must already point to this server)"
certbot --nginx -d "${DOMAIN}" --non-interactive --agree-tos -m "${EMAIL}" --redirect

echo "==> Queue workers + scheduler (supervisor)"
sed -e "s#__APP_DIR__#${APP_DIR}#g" "$(dirname "$0")/supervisor.conf" > /etc/supervisor/conf.d/clipper.conf
supervisorctl reread && supervisorctl update

cat <<MSG

Done. Next:
  1. cd ${APP_DIR} && cp .env.example .env   — fill APP_URL=https://${DOMAIN}, DB_*, API keys,
     APP_ENV=production, APP_DEBUG=false, SESSION_SECURE_COOKIE=true, YTDLP_COOKIES=/path/cookies.txt
  2. bash deploy/deploy.sh                    — install, migrate, build, start workers
  3. Open https://${DOMAIN}/register and create your account (sign-up then closes).
MSG
