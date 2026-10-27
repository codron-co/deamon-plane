#!/usr/bin/env bash
# Retire one Deamon site for good (coolify VPS, root):  retire-site.sh <slug> --yes
#   1. backup: shared-MySQL dump, the app's volumes, Coolify compose/.env -> /root/retired-sites/<slug>-<date>/ (700)
#   2. verify every archive; nothing is deleted unless all backups are good
#   3. Plane: site status archived (drops out of the watch list)
#   4. Coolify: delete the application with its volumes (also the old per-site mysql/redis)
#   5. shared MySQL: drop schema + user; shared Redis: unlink the prefixed keys in the pair, ACL DELUSER + ACL SAVE
# DNS and CoMail are left to their owners.
set -euo pipefail
SLUG=${1:?usage: retire-site.sh <slug> --yes}; [ "${2:-}" = --yes ] || { echo "add --yes"; exit 1; }
. /opt/deamon-db/.env; . /opt/deamon-redis/.env; export REDIS_ADMIN_PASSWORD
OUT=/root/retired-sites/$SLUG-$(date +%F); LOG=/root/retired-sites/retire.log
mkdir -p "$OUT"; chmod 700 /root/retired-sites "$OUT"
log() { echo "$(date '+%F %T') [$SLUG] $*" | tee -a "$LOG"; }
plane=$(docker ps --format '{{.Names}}' | grep '^app-d6ovbjzxgpao23faam3vrcve' | head -1)
art() { docker exec "$plane" php artisan "$@"; }
kv() { sed -n "s/^$1=//p" <<< "$2" | head -1; }

dbst=$(art ops:site-db status "$SLUG"); rst=$(art ops:site-redis status "$SLUG")
uuid=$(kv APP_UUID "$rst"); db=$(kv DB_DATABASE "$dbst"); dbuser=$(kv DB_USERNAME "$dbst")
rdb=$(kv TARGET_DB "$rst"); rprefix=$(kv PREFIX "$rst"); ruser=$(kv TARGET_USERNAME "$rst")
[[ "$uuid" =~ ^[a-z0-9]{24}$ && "$db" =~ ^site_[a-z0-9_]+$ && "$dbuser" =~ ^site_[a-z0-9_]+$ && "$rprefix" =~ ^site_[a-z0-9_]+:$ && "$ruser" =~ ^site_[a-z0-9_]+$ && "$rdb" =~ ^[0-9]+$ ]] \
  || { log "ABORT: unexpected identifiers uuid=$uuid db=$db user=$dbuser redis=$rdb/$rprefix/$ruser"; exit 1; }
[ "$SLUG" != test ] || { log "ABORT: deamon-test is never retired"; exit 1; }
log "start uuid=$uuid db=$db redis=$rdb,$((rdb+1)) prefix=$rprefix"

# 1. backups
docker exec -e MYSQL_PWD="$MYSQL_ROOT_PASSWORD" deamon-db-mysql mysqldump -uroot --single-transaction --routines --triggers --no-tablespaces "$db" </dev/null | gzip > "$OUT/$db.sql.gz"
for v in $(docker volume ls --format '{{.Name}}' | grep -E "^${uuid}[_-]"); do
  tar czf "$OUT/$v.tar.gz" -C "/var/lib/docker/volumes/$v/_data" .
done
cp -p "/data/coolify/applications/$uuid/docker-compose.yaml" "/data/coolify/applications/$uuid/.env" "$OUT/" 2>/dev/null || true
chmod 600 "$OUT"/* "$OUT"/.env 2>/dev/null || true

# 2. verify
for f in "$OUT"/*.gz; do gzip -t "$f" || { log "ABORT: broken archive $f"; exit 1; }; done
tables=$(zcat "$OUT/$db.sql.gz" | grep -c '^CREATE TABLE' || true)
[ "$tables" -ge 50 ] || { log "ABORT: dump has only $tables tables"; exit 1; }
log "backup ok: $(du -sh "$OUT" | cut -f1), $tables tables, $(ls "$OUT" | wc -l) files in $OUT"

# 3. Plane: archived
art tinker --execute "\$s = App\Models\Site::where('slug', '$SLUG')->firstOrFail(); \$before = \$s->status->value; \$s->status = App\Enums\SiteStatus::Archived; \$s->save(); \$s->auditLogs()->create(['actor_user_id' => null, 'action' => 'site.retired', 'before' => ['status' => \$before], 'after' => ['status' => 'archived', 'backup' => '$OUT'], 'ip' => null]); echo 'archived';" | tail -1

# 4. Coolify: delete the application and its volumes
art tinker --execute "\$s = App\Models\Site::where('slug', '$SLUG')->firstOrFail(); App\Services\Coolify\CoolifyApplicationService::forSite(\$s)->deleteApplication('$uuid', true); echo 'coolify delete requested';" | tail -1
for i in $(seq 1 40); do docker ps -a --format '{{.Names}}' | grep -qE -- "^$uuid-|-$uuid-|^app-$uuid|^mysql-$uuid|^redis-$uuid" || break; sleep 5; done
log "containers left: $(docker ps -a --format '{{.Names}}' | grep -c "$uuid" || true), volumes left: $(docker volume ls --format '{{.Name}}' | grep -cE "^${uuid}[_-]" || true)"

# 5. shared MySQL + Redis
docker exec -e MYSQL_PWD="$MYSQL_ROOT_PASSWORD" deamon-db-mysql mysql -uroot -e "DROP DATABASE IF EXISTS \`$db\`; DROP USER IF EXISTS '$dbuser'@'%';" </dev/null
R() { docker exec -i deamon-redis redis-cli --no-auth-warning --user plane_admin --pass "$REDIS_ADMIN_PASSWORD" "$@"; }
for n in "$rdb" "$((rdb + 1))"; do
  R -n "$n" --scan --pattern "${rprefix}*" --count 1000 </dev/null | xargs -r -d '\n' -n 500 sh -c 'docker exec -i deamon-redis redis-cli --no-auth-warning --user plane_admin --pass "$REDIS_ADMIN_PASSWORD" -n '"$n"' unlink "$@" >/dev/null' _
done
R acl deluser "$ruser" </dev/null >/dev/null; R acl save </dev/null >/dev/null
log "done: schema $db and user dropped, redis keys left $(R -n "$rdb" --scan --pattern "${rprefix}*" </dev/null | wc -l)/$(R -n "$((rdb+1))" --scan --pattern "${rprefix}*" </dev/null | wc -l), ACL user $ruser removed"
