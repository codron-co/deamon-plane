#!/usr/bin/env bash
# Nightly dump of every site database on the shared MySQL (cron 03:15).
# One gzip per schema under /opt/deamon-db/backups/<date>/, 7 days kept.
# Runs at the lowest CPU and IO priority: the VPS is CPU-throttled above ~3 h of load.
set -euo pipefail
DIR=/opt/deamon-db; OUT=$DIR/backups/$(date +%F); KEEP_DAYS=7
. "$DIR/.env"
mkdir -p "$OUT"; chmod 700 "$DIR/backups"

schemas=$(docker exec deamon-db-mysql mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -e \
  "SELECT schema_name FROM information_schema.schemata WHERE schema_name NOT IN ('mysql','sys','performance_schema','information_schema')" 2>/dev/null)

failed=0
for db in $schemas; do
  if ! nice -n 19 ionice -c3 docker exec deamon-db-mysql sh -c \
      "exec mysqldump -uroot -p\"\$MYSQL_ROOT_PASSWORD\" --single-transaction --quick --routines --triggers --no-tablespaces --set-gtid-purged=OFF \"$db\"" 2>/dev/null \
      | nice -n 19 gzip -6 > "$OUT/$db.sql.gz.tmp"; then
    failed=$((failed + 1)); rm -f "$OUT/$db.sql.gz.tmp"; echo "$(date '+%F %T') FAIL $db" >> "$DIR/backup.log"; continue
  fi
  mv "$OUT/$db.sql.gz.tmp" "$OUT/$db.sql.gz"
done

find "$DIR/backups" -mindepth 1 -maxdepth 1 -type d -mtime +"$KEEP_DAYS" -exec rm -rf {} +
echo "$(date '+%F %T') ok $(echo "$schemas" | wc -w) schema(s), $failed failed, $(du -sh "$OUT" | cut -f1)" >> "$DIR/backup.log"
if [ "$failed" -gt 0 ] && [ -x /usr/local/bin/wa-alert.sh ]; then
  /usr/local/bin/wa-alert.sh "⚠ deamon-db yedeği: $failed veritabanı yedeklenemedi (/opt/deamon-db/backup.log)" >/dev/null 2>&1 || true
fi
