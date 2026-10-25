#!/usr/bin/env bash
# Unattended fleet run on the coolify VPS (start with setsid nohup, it outlives SSH):
#   1. wait for the Coolify deploy queue to drain (auto-deploy sites after an alpha push),
#   2. deploy each app in DEPLOY_UUIDS one at a time (Plane → Coolify), each when the
#      queue is empty and the load is below MAX_LOAD, and check it comes back healthy,
#   3. move each app in MOVE_UUIDS to the shared MySQL with move-site-db.sh, in order.
# Stops and sends a WhatsApp alert when a site is left unhealthy; a site the move script
# rolled back (still healthy on its own MySQL) is reported and skipped.
#
# Usage: fleet-db-rollout.sh <deploy-list-file> <move-list-file>   (one app uuid per line, # comments)
# Log: /opt/deamon-db/rollout.log
set -uo pipefail
DEPLOY_LIST=${1:?deploy list}; MOVE_LIST=${2:?move list}
LOG=/opt/deamon-db/rollout.log; MAX_LOAD=${MAX_LOAD:-6}; MAX_WAIT_MIN=${MAX_WAIT_MIN:-120}
# Moves do not build (the app is recreated from its image), so they wait on CPU use, not load.
MAX_CPU=${MAX_CPU:-80}
log() { echo "$(date '+%F %T') $*" >> "$LOG"; }
alert() { log "ALERT: $*"; /usr/local/bin/wa-alert.sh "Deamon gece işi: $*" >/dev/null 2>&1 || true; }
q() { docker exec coolify-db psql -U coolify -d coolify -At -c "$1" 2>/dev/null; }
plane() { docker ps --format '{{.Names}}' | grep '^app-d6ovbjzxgpao23faam3vrcve' | head -1; }
uuids() { grep -vE '^\s*(#|$)' "$1" | awk '{print $1}'; }

wait_calm() { # queue empty and load < MAX_LOAD; gives up after MAX_WAIT_MIN
  local i busy load
  for i in $(seq 1 "$MAX_WAIT_MIN"); do
    busy=$(q "SELECT COUNT(*) FROM application_deployment_queues WHERE status IN ('queued','in_progress')")
    load=$(cut -d' ' -f1 /proc/loadavg)
    if [ "$busy" = 0 ] && awk "BEGIN{exit !($load < $MAX_LOAD)}"; then return 0; fi
    sleep 60
  done
  return 1
}

cpu_busy() { # % CPU busy over 5 s, from /proc/stat
  local a b
  a=($(awk '/^cpu /{print $2+$3+$4+$6+$7+$8+$9, $5}' /proc/stat)); sleep 5
  b=($(awk '/^cpu /{print $2+$3+$4+$6+$7+$8+$9, $5}' /proc/stat))
  awk -v bu=$((b[0] - a[0])) -v id=$((b[1] - a[1])) 'BEGIN{ t = bu + id; printf "%d", (t > 0 ? 100 * bu / t : 0) }'
}

wait_cpu() { # CPU below MAX_CPU; gives up after MAX_WAIT_MIN
  local i c
  for i in $(seq 1 "$MAX_WAIT_MIN"); do
    c=$(cpu_busy)
    [ "$c" -lt "$MAX_CPU" ] && return 0
    [ "$i" = 1 ] && log "waiting: CPU ${c}% >= ${MAX_CPU}%"
    sleep 55
  done
  return 1
}

site_ok() { # app uuid → 0 when the app container is healthy and /up answers 200
  local c
  c=$(docker ps --format '{{.Names}}' | grep "^app-$1" | head -1)
  [ -n "$c" ] || return 1
  [ "$(docker inspect "$c" --format '{{.State.Health.Status}}' 2>/dev/null)" = healthy ] || return 1
  [ "$(docker exec "$c" curl -s -o /dev/null -m 10 -w '%{http_code}' http://127.0.0.1:8080/up 2>/dev/null)" = 200 ]
}

name_of() { q "SELECT name FROM applications WHERE uuid = '$1'"; }

log "=== start: deploy $(uuids "$DEPLOY_LIST" | wc -l), move $(uuids "$MOVE_LIST" | wc -l)"

# 1-2. deploys
for u in $(uuids "$DEPLOY_LIST"); do
  n=$(name_of "$u")
  wait_calm || { alert "deploy sırası durdu: $MAX_WAIT_MIN dk boyunca kuyruk/yük sakinleşmedi ($n bekliyordu)"; exit 1; }
  since=$(date -u '+%F %T')
  out=$(docker exec "$(plane)" php artisan tinker --execute "\$s = App\Models\Site::where('coolify_app_uuid','$u')->first(); echo \$s ? json_encode(App\Services\Coolify\CoolifyApplicationService::forSite(\$s)->deploy('$u')) : 'NO_SITE';" 2>&1 | tail -1)
  log "deploy $n ($u): $out"
  s=""
  for i in $(seq 1 90); do
    s=$(q "SELECT q.status FROM application_deployment_queues q JOIN applications a ON a.id::text = q.application_id::text WHERE a.uuid = '$u' AND q.created_at >= '$since'::timestamp - interval '10 seconds' ORDER BY q.created_at DESC LIMIT 1")
    case "$s" in finished|failed|cancelled*) break;; esac
    sleep 20
  done
  sleep 30
  if site_ok "$u"; then log "deploy $n: $s, healthy"; else alert "$n deploy sonrası sağlıksız (deploy: ${s:-?}); sıra durdu"; exit 1; fi
done

# 3. moves
moved=0; skipped=""
for u in $(uuids "$MOVE_LIST"); do
  n=$(name_of "$u")
  wait_cpu || { alert "taşıma sırası durdu: CPU $MAX_WAIT_MIN dk boyunca %$MAX_CPU üstünde kaldı ($n bekliyordu); $moved site taşındı"; exit 1; }
  log "move $n ($u)"
  if /opt/deamon-db/move-site-db.sh "$u" --yes >> "$LOG" 2>&1; then
    moved=$((moved + 1))
  else
    skipped="$skipped $n"
  fi
  sleep 20
  if ! site_ok "$u"; then alert "$n taşıma sonrası sağlıksız; sıra durdu ($moved site taşındı)"; exit 1; fi
  sleep 40
done

log "=== done: $moved moved; skipped:${skipped:- none}"
/usr/local/bin/wa-alert.sh "Deamon gece işi bitti: $moved site paylaşılan MySQL'e taşındı.${skipped:+ Atlanan/geri alınan:$skipped}" >/dev/null 2>&1 || true
