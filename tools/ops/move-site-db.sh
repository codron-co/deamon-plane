#!/usr/bin/env bash
# Move one Deamon site from its own compose MySQL to the shared deamon-db-mysql.
# Run on the coolify VPS as root:   move-site-db.sh <site-slug|domain|app-uuid> [--yes]
#
#   1. Plane: `ops:site-db prepare` creates the schema + user (site keeps running).
#   2. Site goes into maintenance (503 with retry; queue and scheduler pause).
#   3. mysqldump from the site's own MySQL → import into the empty shared schema.
#   4. Exact per-table row counts are compared; any difference aborts before the switch.
#   5. Plane: `ops:site-db switch --no-restart` records the placement and writes DB_* to
#      Coolify's env, so every later Coolify deploy keeps it.
#   6. The same DB_* go into Coolify's generated .env and only the app container is
#      recreated from its existing image (no build, ~15 s). A Coolify "restart" would
#      rebuild the image (≈9 min in maintenance, one more Docker build per site).
#   7. The new container must report the shared host and answer /up; otherwise Plane and
#      the .env are switched back and the app is recreated on its local MySQL, which
#      still holds the same data.
#
# The site's old MySQL container and volume are left untouched (rollback path).
# Downtime is the maintenance window: dump + import + recreate, usually under a minute.
set -euo pipefail

SITE=${1:?usage: move-site-db.sh <site> [--yes]}
YES=${2:-}
DB_CONTAINER=deamon-db-mysql
LOG=/opt/deamon-db/move.log
WORK=$(mktemp -d /opt/deamon-db/move.XXXXXX); chmod 700 "$WORK"
trap 'rm -rf "$WORK"' EXIT
. /opt/deamon-db/.env

log() { echo "$(date '+%F %T') [$SITE] $*" | tee -a "$LOG"; }
die() { log "ABORT: $*"; exit 1; }

plane=$(docker ps --format '{{.Names}}' | grep '^app-d6ovbjzxgpao23faam3vrcve' | head -1)
[ -n "$plane" ] || die "Plane container not found"
artisan() { docker exec "$plane" php artisan "$@"; }
kv() { sed -n "s/^$1=//p" <<< "$2" | head -1; }
coolify_q() { docker exec coolify-db psql -U coolify -d coolify -At -c "$1" 2>/dev/null; }

status=$(artisan ops:site-db status "$SITE") || die "Plane does not know this site"
uuid=$(kv APP_UUID "$status"); target_db=$(kv TARGET_DATABASE "$status")
[ "$(kv SHARED "$status")" = no ] || die "already on the shared server"

APPDIR=/data/coolify/applications/$uuid
[ -f "$APPDIR/docker-compose.yaml" ] && [ -f "$APPDIR/.env" ] || die "no Coolify compose/.env in $APPDIR"
grep -q 'DB_HOST: .${DB_HOST:-mysql}' "$APPDIR/docker-compose.yaml" \
  || die "deployed compose does not read DB_HOST from env yet (deploy the CMS with the deamon-db compose first)"

names=$(docker ps --format '{{.Names}}')
app=$(grep "^app-$uuid" <<< "$names" | head -1)
src=$(grep "^mysql-$uuid" <<< "$names" | head -1)
[ -n "$app" ] && [ -n "$src" ] || die "app or mysql container for $uuid not running"
docker inspect "$app" --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}' | grep -qw deamon-db \
  || die "app is not on the deamon-db network yet (deploy the CMS with the deamon-db compose first)"

busy=$(coolify_q "SELECT COUNT(*) FROM application_deployment_queues q JOIN applications a ON a.id::text = q.application_id::text WHERE a.uuid = '$uuid' AND q.status IN ('queued','in_progress')")
[ "$busy" = 0 ] || die "a Coolify deployment of this app is queued or running; try again after it"

srcsql() { docker exec -i "$src" sh -c 'exec mysql -udeamon -p"$MYSQL_PASSWORD" -N deamon' 2>/dev/null; }
dstsql() { docker exec -i "$DB_CONTAINER" mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N "$target_db" 2>/dev/null; }
counts() { # "<table> <rows>" per base table, exact counts, one query per side
  local run=$1 sql
  sql=$($run <<< "SET SESSION group_concat_max_len = 1048576; SELECT GROUP_CONCAT(CONCAT('SELECT ''', table_name, ''', COUNT(*) FROM \`', table_name, '\`') ORDER BY table_name SEPARATOR ' UNION ALL ') FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")
  [ -n "$sql" ] && [ "$sql" != NULL ] || return 0
  $run <<< "$sql" | tr '\t' ' '
}
srcsql <<< "SELECT 1" >/dev/null || die "cannot read the site's own MySQL"

size=$(srcsql <<< "SELECT ROUND(SUM(data_length + index_length) / 1048576, 1) FROM information_schema.tables WHERE table_schema = DATABASE()")
log "app=$app src=$src target=$target_db size=${size}MB"
if [ "$YES" != "--yes" ]; then
  read -r -p "Move $SITE (${size} MB) to $DB_CONTAINER/$target_db? Site is in maintenance meanwhile. [y/N] " ok
  [ "$ok" = y ] || die "cancelled"
fi

artisan ops:site-db prepare "$SITE" > /dev/null || die "prepare failed"
existing=$(dstsql <<< "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")
[ "$existing" = 0 ] || die "target schema $target_db is not empty ($existing tables)"

# DB_* in Coolify's generated .env, then recreate only the app from its current image.
cp -p "$APPDIR/.env" "$WORK/env.before"
set_env() { # KEY VALUE
  if grep -q "^$1=" "$APPDIR/.env"; then sed -i "s|^$1=.*|$1=\"$2\"|" "$APPDIR/.env"; else echo "$1=\"$2\"" >> "$APPDIR/.env"; fi
}
recreate_app() { (cd "$APPDIR" && docker compose -p "$uuid" -f docker-compose.yaml up -d --no-build --no-deps --force-recreate app) >> "$LOG" 2>&1; }
wait_app() { # expected DB_HOST → prints container name when /up answers 200
  local want=$1 c i
  for i in $(seq 1 60); do
    sleep 3
    c=$(docker ps --format '{{.Names}}' | grep "^app-$uuid" | head -1) || true
    [ -n "$c" ] && [ "$(docker exec "$c" printenv DB_HOST 2>/dev/null)" = "$want" ] || continue
    docker exec "$c" php artisan up >/dev/null 2>&1 || true
    [ "$(docker exec "$c" curl -s -o /dev/null -m 5 -w '%{http_code}' http://127.0.0.1:8080/up 2>/dev/null)" = 200 ] && { echo "$c"; return 0; }
  done
  return 1
}

log "maintenance on"
docker exec "$app" php artisan down --retry=60 >/dev/null || die "could not enter maintenance"
restore_up() { docker exec "$app" php artisan up >/dev/null 2>&1 || true; }

docker exec "$src" sh -c 'exec mysqldump -udeamon -p"$MYSQL_PASSWORD" --single-transaction --quick --routines --triggers --no-tablespaces --set-gtid-purged=OFF deamon' \
  > "$WORK/dump.sql" 2>"$WORK/dump.err" || { restore_up; die "dump failed: $(head -c 300 "$WORK/dump.err")"; }
log "dumped $(du -h "$WORK/dump.sql" | cut -f1)"

dstsql < "$WORK/dump.sql" || { restore_up; die "import failed"; }
counts srcsql > "$WORK/src.counts"; counts dstsql > "$WORK/dst.counts"
[ -s "$WORK/src.counts" ] || { restore_up; die "could not count source rows"; }
if ! diff -q "$WORK/src.counts" "$WORK/dst.counts" >/dev/null; then
  restore_up; die "row counts differ: $(diff "$WORK/src.counts" "$WORK/dst.counts" | head -5 | tr '\n' ' ')"
fi
log "imported $(wc -l < "$WORK/src.counts") tables, row counts match"

env_out=$(artisan ops:site-db switch "$SITE" --no-restart) || { restore_up; die "switch failed (site still on local MySQL)"; }
for key in DB_HOST DB_PORT DB_DATABASE DB_USERNAME; do set_env "$key" "$(kv "$key" "$env_out")"; done
log "switched (Plane + Coolify env), recreating the app container"
recreate_app || log "compose recreate reported an error"

if ok=$(wait_app "$DB_CONTAINER"); then
  log "DONE: $ok runs on $DB_CONTAINER/$target_db"
  exit 0
fi

log "new container did not come up on the shared DB; switching back to local"
artisan ops:site-db local "$SITE" --no-restart > /dev/null || log "ROLLBACK FAILED in Plane: run ops:site-db local $SITE by hand"
cp -p "$WORK/env.before" "$APPDIR/.env"
recreate_app || log "compose recreate (rollback) reported an error"
wait_app mysql > /dev/null || log "ROLLBACK: app did not answer /up on the local MySQL; check it by hand"
die "rolled back to the local MySQL"
