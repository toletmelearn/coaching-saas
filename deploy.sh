#!/usr/bin/env bash
# deploy.sh — run from the app root on the server as the site user.
# Requires: git, composer, npm (via nvm), PHP ≥ 8.3 with pdo_mysql.
# See docs/DEPLOY.md for the full runbook, one-time setup, and caveats.
set -euo pipefail

cd "$(dirname "$0")"

echo "==> Pulling latest code"
git pull

echo "==> Installing PHP dependencies (no dev, optimised autoloader)"
composer install --no-dev --optimize-autoloader

echo "==> Building front-end assets"
npm ci && npm run build

echo "==> Running migrations"
php artisan migrate --force

echo "==> Caching config / routes / views / events"
php artisan optimize

echo "==> Restarting the queue worker"
# This signals an already-running `php artisan queue:work` process to exit cleanly
# after its current job, so Supervisor will restart it with the new code.
# No-op if no worker is running yet.
php artisan queue:restart

echo "==> Running production preflight checks"
# Exits non-zero if any invariant is violated (debug on, insecure cookies, fake video
# driver in production, etc.). Treat a non-zero exit as "deploy failed".
php artisan app:preflight --require-production

echo "==> Deploy complete."
