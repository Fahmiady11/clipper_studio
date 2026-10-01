#!/usr/bin/env bash
# Install / update Clipper Studio on the server. Safe to run on every deploy.
#   cd /var/www/clipper && bash deploy/deploy.sh
set -euo pipefail
cd "$(dirname "$0")/.."

[ -f .env ] || { echo "Missing .env — copy .env.example and fill it first."; exit 1; }
grep -q '^APP_DEBUG=false' .env || echo "WARNING: set APP_DEBUG=false in .env for a public server"

git pull --ff-only
composer install --no-dev --optimize-autoloader --no-interaction
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force

npm ci --ignore-scripts
npm run build

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Files written by the web server and the workers
mkdir -p storage/app/music storage/app/temp storage/logs/clipper
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache

# Workers finish their current job, then supervisor starts them on the new code
php artisan queue:restart
sudo supervisorctl restart clipper-scheduler >/dev/null 2>&1 || true

echo "Deployed. Check: sudo supervisorctl status"
