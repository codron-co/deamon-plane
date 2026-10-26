#!/usr/bin/env bash
# What `php artisan cache:clear` does on a site with its own Redis (FLUSHDB of the cache DB),
# for a site on the shared deamon-redis, where FLUSHDB is denied to the site user.
# Deletes only the keys under the site prefix in its CACHE DB (REDIS_CACHE_DB = pair + 1).
# Sessions and queues live in the other DB of the pair and are not touched.
# Usage (coolify VPS, root):  clear-site-cache.sh <site slug | app uuid>
set -euo pipefail
SITE=${1:?usage: clear-site-cache.sh <site>}
. /opt/deamon-redis/.env; export REDIS_ADMIN_PASSWORD
plane=$(docker ps --format "{{.Names}}" | grep "^app-d6ovbjzxgpao23faam3vrcve" | head -1)
st=$(docker exec "$plane" php artisan ops:site-redis status "$SITE")
kv() { sed -n "s/^$1=//p" <<< "$st" | head -1; }
[ "$(kv SHARED)" = yes ] || { echo "not on the shared Redis: run php artisan cache:clear in the site container"; exit 1; }
db=$(kv REDIS_CACHE_DB); prefix=$(kv REDIS_PREFIX)
[[ "$db" =~ ^[0-9]+$ ]] && [[ "$prefix" =~ ^site_[a-z0-9_]+:$ ]] || { echo "bad db/prefix from Plane: db=$db prefix=$prefix"; exit 1; }
cli() { docker exec -i deamon-redis redis-cli --no-auth-warning --user plane_admin --pass "$REDIS_ADMIN_PASSWORD" -n "$db" "$@"; }
before=$(cli --scan --pattern "${prefix}*" --count 1000 | wc -l)
cli --scan --pattern "${prefix}*" --count 1000 | xargs -r -d "\n" -n 500 sh -c "docker exec -i deamon-redis redis-cli --no-auth-warning --user plane_admin --pass \"\$REDIS_ADMIN_PASSWORD\" -n $db unlink \"\$@\" >/dev/null" _
after=$(cli --scan --pattern "${prefix}*" --count 1000 | wc -l)
echo "$SITE: cache db $db, prefix $prefix: $before keys -> $after"
