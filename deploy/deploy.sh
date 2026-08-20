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
deploy_restart_since=$(date -u +%Y-%m-%dT%H:%M:%SZ)
docker compose -f "$C" up -d
# schedule:work starts a fresh schedule:run child each minute. Do not signal it
# here: a restart can kill an active donation sweep and delay recovery an hour.
docker compose -f "$C" restart pos_admin pos_admin_queue_worker nginx

# Verify, don't assume: all long-running PHP processes must be running, the
# page must serve, and their fresh logs must stay quiet.
sleep 6

check_running() {
    local service="$1"
    local container_id
    local state

    container_id=$(docker compose -f "$C" ps -q "$service")
    [ -n "$container_id" ] || { echo "FAIL: $service has no container"; exit 1; }

    state=$(docker inspect --format '{{.State.Status}}' "$container_id")
    echo "$service: $state"
    [ "$state" = "running" ] || { echo "FAIL: $service is not running"; exit 1; }
}

for service in pos_admin pos_admin_scheduler pos_admin_queue_worker; do
    check_running "$service"
done

code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 https://posadmin.mithqal.net/login || true)
echo "health: HTTP $code"
[ "$code" = "200" ] || { echo "FAIL: health check"; exit 1; }

echo "fresh logs (pos_admin, pos_admin_scheduler, pos_admin_queue_worker):"
fresh_logs=$(docker compose -f "$C" logs --since "$deploy_restart_since" --no-color \
    pos_admin pos_admin_scheduler pos_admin_queue_worker 2>&1)
printf '%s\n' "$fresh_logs"
errs=$(printf '%s\n' "$fresh_logs" | grep -ciE "fatal error|exception" || true)
echo "fresh log errors: $errs"
[ "$errs" -eq 0 ] || { echo "FAIL: errors right after deploy"; exit 1; }
echo "deploy OK"
