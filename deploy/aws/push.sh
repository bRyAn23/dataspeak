#!/usr/bin/env bash
# Run on your computer: build the images for the server's CPU architecture, ship them and the deployment files over
# SSH, then run deploy.sh on the server. No git access is needed on the server.
#   deploy/aws/push.sh <ssh key> <user@host> [linux/arm64|linux/amd64]
set -euo pipefail
KEY="${1:?ssh key (.pem)}"; HOST="${2:?user@host}"; PLATFORM="${3:-linux/arm64}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SSH=(ssh -i "$KEY" -o StrictHostKeyChecking=accept-new "$HOST")
echo "== build images for $PLATFORM"
docker build --platform "$PLATFORM" -f "$ROOT/docker/backend/Dockerfile.prod" -t dataspeak-api:latest "$ROOT"
docker build --platform "$PLATFORM" -f "$ROOT/docker/analytics/Dockerfile" -t dataspeak-analytics:latest "$ROOT"
echo "== ship images"
docker save dataspeak-api:latest dataspeak-analytics:latest | gzip | "${SSH[@]}" 'gunzip | docker load'
echo "== ship deployment files"
"${SSH[@]}" 'mkdir -p ~/dataspeak/deploy/aws'
rsync -az -e "ssh -i $KEY" "$ROOT/deploy/aws/" "$HOST:~/dataspeak/deploy/aws/"
echo "== deploy"
"${SSH[@]}" 'cd ~/dataspeak/deploy/aws && chmod +x deploy.sh && ./deploy.sh --images'
