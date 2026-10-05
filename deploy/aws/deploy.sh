#!/usr/bin/env bash
# Deploy or update the Data Speak API stack on the API EC2.
#   ./deploy.sh            pull the current branch, rebuild images, migrate, restart
#   ./deploy.sh --no-pull  rebuild from the working tree as it is
# Requires deploy/aws/.env.prod (see .env.prod.example). Safe to re-run; the worker finishes its current job first.
set -euo pipefail
cd "$(dirname "$0")"
COMPOSE=(docker compose -f docker-compose.prod.yml --env-file .env.prod)
[ -f .env.prod ] || { echo ".env.prod is missing (copy .env.prod.example)"; exit 1; }
if [ "${1:-}" != "--no-pull" ]; then git -C ../.. pull --ff-only; fi

echo "== build images"
"${COMPOSE[@]}" build --pull
echo "== start redis and analytics"
"${COMPOSE[@]}" up -d redis analytics
echo "== run database migrations (RDS)"
"${COMPOSE[@]}" run --rm --no-deps --entrypoint sh app -c 'php artisan migrate --force --no-interaction'
echo "== restart api, worker and scheduler"
"${COMPOSE[@]}" up -d app worker scheduler
"${COMPOSE[@]}" ps
echo "== health"
sleep 5
SERVER_NAME="$(grep -E '^SERVER_NAME=' .env.prod | cut -d= -f2-)"
case "$SERVER_NAME" in http://*|https://*) HEALTH_URL="$SERVER_NAME/api/v1/health" ;; *) HEALTH_URL="https://$SERVER_NAME/api/v1/health" ;; esac
curl -fsS "$HEALTH_URL" && echo
docker image prune -f >/dev/null
