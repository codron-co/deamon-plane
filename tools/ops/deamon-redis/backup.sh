#!/usr/bin/env bash
# Nightly RDB snapshot of deamon-redis (cron 03:40), 7 days kept in /opt/deamon-redis/backups.
# Site data is mostly sessions and cache; the snapshot is for queued jobs and a clean restore.
set -euo pipefail
DIR=/opt/deamon-redis; OUT=$DIR/backups; KEEP_DAYS=7
. "$DIR/.env"
cli() { docker exec deamon-redis redis-cli --no-auth-warning --user plane_admin --pass "$REDIS_ADMIN_PASSWORD" "$@"; }
mkdir -p "$OUT"; chmod 700 "$OUT"
before=$(cli lastsave | tr -d '\r')
cli bgsave >/dev/null
for i in $(seq 1 60); do sleep 2; [ "$(cli lastsave | tr -d '\r')" != "$before" ] && break; done
nice -n 19 gzip -c "$DIR/data/dump.rdb" > "$OUT/dump-$(date +%F).rdb.gz.tmp" && mv "$OUT/dump-$(date +%F).rdb.gz.tmp" "$OUT/dump-$(date +%F).rdb.gz"
find "$OUT" -name 'dump-*.rdb.gz' -mtime +"$KEEP_DAYS" -delete
echo "$(date '+%F %T') ok $(du -h "$OUT/dump-$(date +%F).rdb.gz" | cut -f1)" >> "$DIR/backup.log"
