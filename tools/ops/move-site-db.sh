#!/usr/bin/env bash
# Move one Deamon site from its own compose MySQL to the shared deamon-db-mysql.
# Run on the coolify VPS as root:   move-site-db.sh <site-slug|domain|app-uuid> [--yes]
#
#   1. Plane: `ops:site-db prepare` creates the schema + user (site keeps running).
#   2. Site goes into maintenance (503 with retry; queue and scheduler pause).
#   3. mysqldump from the site's own MySQL → import into the empty shared schema.
#   4. Table and row counts are compared; any difference aborts before the switch.
#   5. Plane: `ops:site-db switch` writes DB_* to Coolify and restarts the app.
#   6. The new container must report the shared host and answer /up; otherwise the
#      site is switched back to its local MySQL (which still holds the same data).
#
# The site's old MySQL container and volume are left untouched (rollback path).
# Downtime is the maintenance window: dump + import + restart, usually 1–3 minutes.
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

status=$(artisan ops:site-db status "$SITE") || die "Plane does not know this site"
uuid=$(kv APP_UUID "$status"); target_db=$(kv TARGET_DATABASE "$status")
[ "$(kv SHARED "$status")" = no ] || die "already on the shared server"

names=$(docker ps --format '{{.Names}}')
app=$(grep "^app-$uuid" <<< "$names" | head -1)
src=$(grep "^mysql-$uuid" <<< "$names" | head -1)
[ -n "$app" ] && [ -n "$src" ] || die "app or mysql container for $uuid not running"
# Only the new compose joins deamon-db; DB_HOST=mysql looks the same before and after it.
docker inspect "$app" --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}' | grep -qw deamon-db \
  || die "app is not on the deamon-db network yet (deploy the CMS with the deamon-db compose first)"

srcsql() { docker exec -i "$src" sh -c 'exec mysql -udeamon -p"$MYSQL_PASSWORD" -N deamon' 2>/dev/null; }
dstsql() { docker exec -i "$DB_CONTAINER" mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N "$target_db" 2>/dev/null; }
counts() { # "<table> <rows>" per base table, exact counts
  local run=$1 tables
  tables=$($run <<< "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY 1")
  for t in $tables; do echo "$t $($run <<< "SELECT COUNT(*) FROM \`$t\`")"; done
}

size=$(srcsql <<< "SELECT ROUND(SUM(data_length + index_length) / 1048576, 1) FROM information_schema.tables WHERE table_schema = DATABASE()")
log "app=$app src=$src target=$target_db size=${size}MB"
if [ "$YES" != "--yes" ]; then
  read -r -p "Move $SITE (${size} MB) to $DB_CONTAINER/$target_db? Site is in maintenance meanwhile. [y/N] " ok
  [ "$ok" = y ] || die "cancelled"
fi

artisan ops:site-db prepare "$SITE" > /dev/null || die "prepare failed"
existing=$(dstsql <<< "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")
[ "$existing" = 0 ] || die "target schema $target_db is not empty ($existing tables)"

# Coolify runs restarts through its deployment queue (one at a time on this VPS): the
# switch's restart would wait behind any build, with the site in maintenance meanwhile.
coolify_q() { docker exec coolify-db psql -U coolify -d coolify -At -c "$1" 2>/dev/null; }
for i in $(seq 1 60); do
  busy=$(coolify_q "SELECT COUNT(*) FROM application_deployment_queues WHERE status IN ('queued','in_progress')")
  [ "$busy" = 0 ] && break
  [ "$i" = 1 ] && log "waiting for the Coolify deploy queue to drain ($busy job(s))"
  sleep 30
done
[ "$busy" = 0 ] || die "Coolify deploy queue still busy after 30 min; try again later"

log "maintenance on"
docker exec "$app" php artisan down --retry=60 >/dev/null || die "could not enter maintenance"
restore_up() { docker exec "$app" php artisan up >/dev/null 2>&1 || true; }

docker exec "$src" sh -c 'exec mysqldump -udeamon -p"$MYSQL_PASSWORD" --single-transaction --quick --routines --triggers --no-tablespaces --set-gtid-purged=OFF deamon' \
  > "$WORK/dump.sql" 2>"$WORK/dump.err" || { restore_up; die "dump failed: $(head -c 300 "$WORK/dump.err")"; }
log "dumped $(du -h "$WORK/dump.sql" | cut -f1)"

dstsql < "$WORK/dump.sql" || { restore_up; die "import failed"; }
counts srcsql > "$WORK/src.counts"; counts dstsql > "$WORK/dst.counts"
if ! diff -q "$WORK/src.counts" "$WORK/dst.counts" >/dev/null; then
  restore_up; die "row counts differ: $(diff "$WORK/src.counts" "$WORK/dst.counts" | head -5 | tr '\n' ' ')"
fi
log "imported $(wc -l < "$WORK/src.counts") tables, row counts match"

switched_at=$(date -u '+%F %T')
artisan ops:site-db switch "$SITE" > /dev/null || { restore_up; die "switch failed (site still on local MySQL)"; }
log "switched, waiting for Coolify's restart of the app"

# Follow the restart through Coolify's queue, then check the container it produced.
restart_status() { coolify_q "SELECT q.status FROM application_deployment_queues q JOIN applications a ON a.id::text = q.application_id::text WHERE a.uuid = '$uuid' AND q.created_at >= '$switched_at'::timestamp - interval '5 seconds' ORDER BY q.created_at DESC LIMIT 1"; }
for i in $(seq 1 60); do
  s=$(restart_status)
  case "$s" in finished|failed|cancelled*) break;; esac
  sleep 10
done
log "coolify restart: ${s:-not found}"

ok=""
for i in $(seq 1 36); do
  sleep 5
  new=$(docker ps --format '{{.Names}}' | grep "^app-$uuid" | head -1) || true
  [ -n "$new" ] || continue
  host=$(docker exec "$new" printenv DB_HOST 2>/dev/null || true)
  [ "$host" = "$DB_CONTAINER" ] || continue
  # A restarted (not recreated) container still has the maintenance flag on disk.
  docker exec "$new" php artisan up >/dev/null 2>&1 || true
  code=$(docker exec "$new" curl -s -o /dev/null -m 5 -w '%{http_code}' http://127.0.0.1:8080/up 2>/dev/null || true)
  if [ "$code" = 200 ]; then ok=$new; break; fi
done

if [ -z "$ok" ]; then
  log "new container did not come up on the shared DB; switching back to local"
  switched_at=$(date -u '+%F %T')
  artisan ops:site-db local "$SITE" > /dev/null || log "ROLLBACK FAILED: run ops:site-db local $SITE by hand"
  for i in $(seq 1 60); do
    s=$(restart_status)
    case "$s" in finished|failed|cancelled*) break;; esac
    sleep 10
  done
  for i in $(seq 1 24); do
    sleep 5
    back=$(docker ps --format '{{.Names}}' | grep "^app-$uuid" | head -1) || true
    [ -n "$back" ] && [ "$(docker exec "$back" printenv DB_HOST 2>/dev/null)" = mysql ] && { docker exec "$back" php artisan up >/dev/null 2>&1 || true; break; }
  done
  die "rolled back to the local MySQL"
fi

log "DONE: $ok runs on $DB_CONTAINER/$target_db"
