#!/bin/bash
# Production deploy for pos_admin — executed ON THE VPS by the GitHub
# Actions `deploy` job after tests pass (or by hand). Assumes the repo was
# just `git pull`ed. Every step is idempotent — safe when nothing changed.
set -euo pipefail
cd "$(dirname "$0")/.."
C="docker-compose.prod.yml"

docker compose -f "$C" build
docker compose -f "$C" --profile build run --rm composer
docker compose -f "$C" --profile build run --rm node-build
# The deploy one-shot MUST run before migrate: composer --no-dev just pruned
# dev packages, and the cache-data volume's packages.php may still reference
# them (e.g. Laravel\Pail). The deploy one-shot wipes + rebuilds those caches
# with a bare rm BEFORE any PHP boots; the migrate one-shot has no such
# protection and would crash on the stale manifest.
timeout 300 docker compose -f "$C" --profile deploy run --rm deploy
docker compose -f "$C" --profile migrate run --rm artisan
docker compose -f "$C" up -d
docker restart pos_admin-pos_admin-1

# Verify, don't assume: the page must serve and the log must stay quiet.
sleep 6
code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 https://posadmin.mithqal.net/login)
echo "health: HTTP $code"
[ "$code" = "200" ] || { echo "FAIL: health check"; exit 1; }
errs=$(docker logs --since 1m pos_admin-pos_admin-1 2>&1 | grep -ciE "fatal error|exception" || true)
echo "fresh log errors: $errs"
[ "$errs" -eq 0 ] || { echo "FAIL: errors right after deploy"; exit 1; }
echo "deploy OK"
