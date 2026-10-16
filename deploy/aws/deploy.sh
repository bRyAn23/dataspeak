#!/usr/bin/env bash
# Deploy or update the Data Speak stack on the server (run on the server, from deploy/aws).
#   ./deploy.sh              pull the current branch, rebuild images, migrate, restart
#   ./deploy.sh --no-pull    rebuild from the working tree as it is
#   ./deploy.sh --images     use images already loaded on this host (docker load), no git, no build
# Reads deploy/aws/.env.prod. PROXY=nginx in that file selects the nginx layout (docker-compose.nginx.yml) and
# installs deploy/aws/nginx/api.conf into nginx. Safe to re-run; the worker finishes its current job first.
set -euo pipefail
cd "$(dirname "$0")"
[ -f .env.prod ] || { echo ".env.prod is missing (copy .env.prod.example)"; exit 1; }
MODE="${1:-}"
PROXY="$(grep -E '^PROXY=' .env.prod | cut -d= -f2- || true)"
COMPOSE=(docker compose -f docker-compose.prod.yml)
[ "$PROXY" = "nginx" ] && COMPOSE+=(-f docker-compose.nginx.yml)
COMPOSE+=(--env-file .env.prod)

if [ "$MODE" = "--images" ]; then
  echo "== using pre-built images (dataspeak-api:latest, dataspeak-analytics:latest)"
  docker image inspect dataspeak-api:latest dataspeak-analytics:latest >/dev/null
else
  [ "$MODE" = "--no-pull" ] || git -C ../.. pull --ff-only
  echo "== build images"
  "${COMPOSE[@]}" build --pull
fi

echo "== start redis and analytics"
"${COMPOSE[@]}" up -d --no-build redis analytics
echo "== run database migrations"
"${COMPOSE[@]}" run --rm --no-deps --entrypoint sh app -c 'php artisan migrate --force --no-interaction'
echo "== restart api, worker and scheduler"
"${COMPOSE[@]}" up -d --no-build app worker worker-translations scheduler
"${COMPOSE[@]}" ps

if [ "$PROXY" = "nginx" ] && [ -f nginx/api.conf ]; then
  echo "== nginx site"
  # Installed once; afterwards certbot owns the file (it adds the HTTPS server block and the redirect), so an
  # existing site file is never overwritten. To apply a changed nginx/api.conf, delete the site file and redeploy,
  # then run certbot again.
  if [ ! -f /etc/nginx/sites-available/dataspeak-api ]; then
    sudo cp nginx/api.conf /etc/nginx/sites-available/dataspeak-api
  fi
  sudo ln -sf /etc/nginx/sites-available/dataspeak-api /etc/nginx/sites-enabled/dataspeak-api
  sudo rm -f /etc/nginx/sites-enabled/default
  sudo nginx -t && sudo systemctl reload nginx
fi

echo "== health"
sleep 5
if [ "$PROXY" = "nginx" ]; then
  curl -fsS http://127.0.0.1:8080/api/v1/health && echo
else
  SERVER_NAME="$(grep -E '^SERVER_NAME=' .env.prod | cut -d= -f2-)"
  case "$SERVER_NAME" in http://*|https://*) HEALTH_URL="$SERVER_NAME/api/v1/health" ;; *) HEALTH_URL="https://$SERVER_NAME/api/v1/health" ;; esac
  curl -fsS "$HEALTH_URL" && echo
fi
docker image prune -f >/dev/null
