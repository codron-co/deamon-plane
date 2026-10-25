#!/usr/bin/env bash
# Move one Deamon site from its own Redis to the shared deamon-redis, or back with --back.
# Run on the coolify VPS as root:   move-site-redis.sh <site> [--yes] [--back]
#
#   1. Plane: `ops:site-redis prepare` creates the site's ACL user and DB pair (site keeps running)
#      and `flush` drops keys left in that pair by an earlier move or a failed copy.
#   2. Site goes into maintenance (503 + retry; queue worker and scheduler pause); waits until
#      no queued job is reserved.
#   3. redis-copy.php, run in the site's app container, copies every key with its TTL and the
#      new prefix (deamon-database- → site_<slug>:) into the DB pair; counts are compared.
#   4. If no deploy touched the app meanwhile, Plane writes REDIS_* to Coolify (`switch
#      --no-restart`), the same values go into Coolify's generated .env, and only the app
#      container is recreated from its image (no build).
#   5. The new container must read REDIS_HOST=deamon-redis, answer /up and round-trip a cache
#      key; otherwise Plane and .env go back to local and the app is recreated on its own Redis.
# The old redis container and its data stay untouched (rollback path).
set -euo pipefail

SITE=${1:?usage: move-site-redis.sh <site> [--yes] [--back]}
shift
YES=""; BACK=""
for a in "$@"; do case "$a" in --yes) YES=1;; --back) BACK=1;; esac; done
LOG=/opt/deamon-redis/move.log
WORK=$(mktemp -d /opt/deamon-redis/move.XXXXXX); chmod 700 "$WORK"
trap 'rm -rf "$WORK"' EXIT
. /opt/deamon-redis/.env
LOCAL_PREFIX=deamon-database-

log() { echo "$(date '+%F %T') [$SITE] $*" | tee -a "$LOG"; }
die() { log "ABORT: $*"; exit 1; }
coolify_q() { docker exec coolify-db psql -U coolify -d coolify -At -c "$1" 2>/dev/null; }
kv() { sed -n "s/^$1=//p" <<< "$2" | head -1; }

plane=$(docker ps --format '{{.Names}}' | grep '^app-d6ovbjzxgpao23faam3vrcve' | head -1)
[ -n "$plane" ] || die "Plane container not found"
artisan() { docker exec "$plane" php artisan "$@"; }

status=$(artisan ops:site-redis status "$SITE") || die "Plane does not know this site"
uuid=$(kv APP_UUID "$status")
shared=$(kv SHARED "$status")
if [ -n "$BACK" ]; then [ "$shared" = yes ] || die "not on the shared Redis"; else [ "$shared" = no ] || die "already on the shared Redis"; fi

APPDIR=/data/coolify/applications/$uuid
[ -f "$APPDIR/docker-compose.yaml" ] && [ -f "$APPDIR/.env" ] || die "no Coolify compose/.env in $APPDIR"
grep -q 'REDIS_HOST: .${REDIS_HOST:-redis}' "$APPDIR/docker-compose.yaml" \
  || die "deployed compose does not read REDIS_HOST from env yet (deploy the CMS with the shared-Redis compose first)"
app=$(docker ps --format '{{.Names}}' | grep "^app-$uuid" | head -1)
oldredis=$(docker ps --format '{{.Names}}' | grep "^redis-$uuid" | head -1)
[ -n "$app" ] && [ -n "$oldredis" ] || die "app or redis container for $uuid not running"
docker inspect "$app" --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}' | grep -qw deamon-db || die "app is not on the deamon-db network"
busy=$(coolify_q "SELECT COUNT(*) FROM application_deployment_queues q JOIN applications a ON a.id::text = q.application_id::text WHERE a.uuid = '$uuid' AND q.status IN ('queued','in_progress')")
[ "$busy" = 0 ] || die "a Coolify deployment of this app is queued or running"

if [ -z "$BACK" ]; then
  artisan ops:site-redis prepare "$SITE" > /dev/null || die "prepare failed"
  # The site is not on the shared Redis yet, so whatever its pair holds is stale (a copy left
  # behind by --back or by a failed run); redis-copy.php refuses a non-empty target.
  artisan ops:site-redis flush "$SITE" > /dev/null || die "could not clear stale keys in the shared DB pair"
fi
target=$(artisan ops:site-redis status "$SITE")
tdb=$(kv TARGET_DB "$target"); tprefix=$(kv PREFIX "$target")
[ -n "$tdb" ] && [ -n "$tprefix" ] || die "Plane gave no target DB/prefix"
log "app=$app direction=$([ -n "$BACK" ] && echo to_local || echo to_shared) shared_dbs=$tdb,$((tdb + 1)) prefix=$tprefix"
if [ -z "$YES" ]; then read -r -p "Proceed (site in maintenance ~30 s)? [y/N] " ok; [ "$ok" = y ] || die "cancelled"; fi

APPENV_BEFORE=$WORK/env.before; cp -p "$APPDIR/.env" "$APPENV_BEFORE"
set_env() { # KEY VALUE (Coolify's .env has no trailing newline)
  if grep -q "^$1=" "$APPDIR/.env"; then sed -i "s|^$1=.*|$1=\"$2\"|" "$APPDIR/.env"
  else [ -z "$(tail -c1 "$APPDIR/.env")" ] || echo >> "$APPDIR/.env"; echo "$1=\"$2\"" >> "$APPDIR/.env"; fi
}
recreate_app() { (cd "$APPDIR" && docker compose -p "$uuid" -f docker-compose.yaml up -d --no-build --no-deps --force-recreate app) >> "$LOG" 2>&1; }
wait_app() { # expected REDIS_HOST → prints container when /up answers and a cache key round-trips
  local want=$1 c i
  for i in $(seq 1 60); do
    sleep 3
    c=$(docker ps --format '{{.Names}}' | grep "^app-$uuid" | head -1) || true
    [ -n "$c" ] && [ "$(docker exec "$c" printenv REDIS_HOST 2>/dev/null)" = "$want" ] || continue
    docker exec "$c" php artisan up >/dev/null 2>&1 || true
    [ "$(docker exec "$c" curl -s -o /dev/null -m 5 -w '%{http_code}' http://127.0.0.1:8080/up 2>/dev/null)" = 200 ] || continue
    [ "$(docker exec "$c" php artisan tinker --execute "Cache::put('redis-move-check', 'ok', 60); echo Cache::get('redis-move-check');" 2>/dev/null | tail -1)" = ok ] || continue
    echo "$c"; return 0
  done
  return 1
}

log "maintenance on"
app_id=$(docker inspect "$app" --format '{{.Id}}'); down_at=$(date -u '+%F %T')
docker exec "$app" php artisan down --retry=60 >/dev/null || die "could not enter maintenance"
restore_up() { docker exec "$app" php artisan up >/dev/null 2>&1 || true; }
untouched() {
  [ "$(docker inspect "$app" --format '{{.Id}}' 2>/dev/null)" = "$app_id" ] || return 1
  [ "$(coolify_q "SELECT COUNT(*) FROM application_deployment_queues q JOIN applications a ON a.id::text = q.application_id::text WHERE a.uuid = '$uuid' AND (q.status IN ('queued','in_progress') OR q.created_at >= '$down_at'::timestamp)")" = 0 ]
}

# Let a job that is being worked on finish before its keys are copied.
src_cli() { if [ -n "$BACK" ]; then docker exec deamon-redis redis-cli --no-auth-warning --user plane_admin --pass "$REDIS_ADMIN_PASSWORD" -n "$tdb" "$@"; else docker exec "$oldredis" redis-cli -n 0 "$@"; fi; }
src_prefix=$([ -n "$BACK" ] && echo "$tprefix" || echo "$LOCAL_PREFIX")
for i in $(seq 1 30); do
  [ "$(src_cli zcard "${src_prefix}queues:default:reserved" 2>/dev/null | tr -d '\r')" = 0 ] && break
  sleep 2
done

payload=$(python3 -c 'import json,sys; print(json.dumps({"direction": sys.argv[1], "local": {"host": "redis", "port": 6379, "dbs": [0, 1], "prefix": sys.argv[2]}, "shared": {"host": "deamon-redis", "port": 6379, "dbs": [int(sys.argv[3]), int(sys.argv[3]) + 1], "prefix": sys.argv[4], "user": "plane_admin", "pass": sys.argv[5]}}))' \
  "$([ -n "$BACK" ] && echo to_local || echo to_shared)" "$LOCAL_PREFIX" "$tdb" "$tprefix" "$REDIS_ADMIN_PASSWORD")
# Going back: the site's own Redis still holds the keys from before the move; they are stale.
# It serves only this site, so it is emptied and refilled from the shared copy.
if [ -n "$BACK" ]; then docker exec "$oldredis" redis-cli flushall >/dev/null || { restore_up; die "could not empty the site's own Redis"; }; fi
docker cp /opt/deamon-redis/redis-copy.php "$app:/tmp/redis-copy.php" >/dev/null
copy=$(printf '%s' "$payload" | docker exec -i "$app" php /tmp/redis-copy.php 2>&1) || true
docker exec "$app" rm -f /tmp/redis-copy.php
log "copy: $(tr '\n' ' ' <<< "$copy")"
grep -q '^OK$' <<< "$copy" || { restore_up; die "copy failed; nothing switched"; }
untouched || { restore_up; die "a deploy of this app started during the maintenance window; not switching"; }

if [ -n "$BACK" ]; then
  artisan ops:site-redis local "$SITE" --no-restart > /dev/null || { restore_up; die "Plane local failed"; }
  for key in REDIS_HOST REDIS_PORT; do sed -i "/^$key=/d" "$APPDIR/.env"; done
  for key in REDIS_USERNAME REDIS_PASSWORD REDIS_DB REDIS_CACHE_DB REDIS_PREFIX; do sed -i "/^$key=/d" "$APPDIR/.env"; done
  want=redis
else
  env_out=$(artisan ops:site-redis switch "$SITE" --no-restart --show-secret) || { restore_up; die "switch failed (site still on its own Redis)"; }
  for key in REDIS_HOST REDIS_PORT REDIS_USERNAME REDIS_PASSWORD REDIS_DB REDIS_CACHE_DB REDIS_PREFIX; do
    v=$(kv "$key" "$env_out"); [ -n "$v" ] || { artisan ops:site-redis local "$SITE" --no-restart >/dev/null; restore_up; die "Plane gave no $key"; }
    set_env "$key" "$v"
    grep -qxF "$key=\"$v\"" "$APPDIR/.env" || { artisan ops:site-redis local "$SITE" --no-restart >/dev/null; cp -p "$APPENV_BEFORE" "$APPDIR/.env"; restore_up; die "$key not written cleanly to .env"; }
  done
  want=deamon-redis
fi
log "switched, recreating the app container"
recreate_app || log "compose recreate reported an error"

if ok=$(wait_app "$want"); then log "DONE: $ok on REDIS_HOST=$want"; exit 0; fi

log "new container did not come up on $want; restoring"
c=$(docker ps -a --format '{{.Names}}' | grep "^app-$uuid" | head -1)
{ echo "--- $c state: $(docker inspect "$c" --format '{{.State.Status}} exit={{.State.ExitCode}} restarts={{.RestartCount}}' 2>&1)"; echo "--- last log lines:"; docker logs --tail 40 "$c" 2>&1; } >> "$LOG"
if [ -n "$BACK" ]; then
  artisan ops:site-redis switch "$SITE" --no-restart > /dev/null || log "RESTORE FAILED in Plane"
else
  artisan ops:site-redis local "$SITE" --no-restart > /dev/null || log "ROLLBACK FAILED in Plane: run ops:site-redis local $SITE by hand"
fi
cp -p "$APPENV_BEFORE" "$APPDIR/.env"
recreate_app || log "compose recreate (restore) reported an error"
wait_app "$([ -n "$BACK" ] && echo deamon-redis || echo redis)" > /dev/null || log "RESTORE: app did not come back; check it by hand"
die "restored the previous Redis placement"
