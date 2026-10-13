#!/usr/bin/env bash
# Build the React frontend here and serve it from the server with nginx on its own host name.
#   deploy/aws/push-frontend.sh <ssh key> <user@host> [frontend host] [api host]
#   defaults: dataspeaknow.com and api.dataspeaknow.com (www.<frontend host> is served too)
# First run installs the nginx sites (API host with its existing certificate; frontend host over HTTP). Issue the
# frontend certificate once DNS points at the server:
#   sudo certbot --nginx -d <frontend host> -d www.<frontend host> --redirect
set -euo pipefail
KEY="${1:?ssh key (.pem)}"; HOST="${2:?user@host}"; FE_HOST="${3:-dataspeaknow.com}"; API_HOST="${4:-api.dataspeaknow.com}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SSH=(ssh -i "$KEY" -o StrictHostKeyChecking=accept-new "$HOST")
echo "== build (API at https://$API_HOST/api/v1)"
(cd "$ROOT/frontend" && VITE_CONFIG_NATIVE_IGNORE_WARNING=true VITE_API_BASE_URL="https://$API_HOST/api/v1" npm run build 2>&1 | grep -E "built in|error" || true)
echo "== upload"
rsync -az --delete -e "ssh -i $KEY" "$ROOT/frontend/dist/" "$HOST:~/dataspeak-frontend-upload/"
sed "s/__API_HOST__/$API_HOST/g" "$ROOT/deploy/aws/nginx/api-ssl.conf.template" | "${SSH[@]}" 'cat > ~/dataspeak-api-site.conf'
sed "s/__FRONTEND_HOST__/$FE_HOST/g" "$ROOT/deploy/aws/nginx/frontend.conf.template" | "${SSH[@]}" 'cat > ~/dataspeak-frontend-site.conf'
echo "== install"
"${SSH[@]}" "set -e
sudo mkdir -p /var/www/dataspeak-frontend /var/www/html
sudo rsync -a --delete ~/dataspeak-frontend-upload/ /var/www/dataspeak-frontend/
sudo chown -R www-data:www-data /var/www/dataspeak-frontend
sudo test -f /etc/letsencrypt/live/$API_HOST/fullchain.pem || { echo 'API certificate missing: sudo certbot --nginx -d $API_HOST'; exit 1; }
sudo cp ~/dataspeak-api-site.conf /etc/nginx/sites-available/dataspeak-api
sudo ln -sf /etc/nginx/sites-available/dataspeak-api /etc/nginx/sites-enabled/dataspeak-api
if [ ! -f /etc/nginx/sites-available/dataspeak-frontend ]; then
  sudo cp ~/dataspeak-frontend-site.conf /etc/nginx/sites-available/dataspeak-frontend   # certbot owns it afterwards
fi
sudo ln -sf /etc/nginx/sites-available/dataspeak-frontend /etc/nginx/sites-enabled/dataspeak-frontend
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx"
echo "== check"
curl -fsS "https://$API_HOST/api/v1/health" && echo
curl -s -o /dev/null -w "frontend via IP (Host header) %{http_code}\n" -H "Host: $FE_HOST" "http://$(dig +short "$API_HOST" A | head -1)/"
