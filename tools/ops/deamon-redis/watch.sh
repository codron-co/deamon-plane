#!/usr/bin/env bash
# deamon-redis watch (cron */5): WhatsApp alert when it stops answering, memory passes 70%
# of maxmemory, keys get evicted, or a site user is denied by its ACL. One message per
# incident; recovery is reported.
set -u
DIR=/opt/deamon-redis; STATE=$DIR/watch.state; LOG=$DIR/watch.log
. "$DIR/.env"
log() { echo "$(date '+%F %T') $*" >> "$LOG"; }
alert() { log "ALERT $*"; /usr/local/bin/wa-alert.sh "deamon-redis: $*" >/dev/null 2>&1 || true; }
cli() { docker exec deamon-redis redis-cli --no-auth-warning --user plane_admin --pass "$REDIS_ADMIN_PASSWORD" "$@" 2>/dev/null; }

prev_down=0; prev_mem=0; prev_evicted=-1; prev_acl=""
[ -f "$STATE" ] && read -r prev_down prev_mem prev_evicted prev_acl < "$STATE"

if [ "$(cli ping | tr -d '\r')" != PONG ]; then
  down=$((prev_down + 1))
  [ "$down" = 2 ] && alert "cevap vermiyor (2 kontrol üst üste). Bütün Deamon siteleri etkilenir."
  echo "$down $prev_mem $prev_evicted $prev_acl" > "$STATE"; exit 0
fi
[ "$prev_down" -ge 2 ] && alert "yeniden cevap veriyor."

info=$(cli info | tr -d '\r')
used=$(sed -n 's/^used_memory://p' <<< "$info"); max=$(sed -n 's/^maxmemory://p' <<< "$info")
evicted=$(sed -n 's/^evicted_keys://p' <<< "$info")
pct=0; [ "${max:-0}" -gt 0 ] && pct=$((100 * used / max))

mem_alarm=$prev_mem
if [ "$pct" -ge 70 ] && [ "$prev_mem" = 0 ]; then alert "bellek %$pct (maxmemory'nin). Sınırı büyütmek ya da büyük anahtarları bulmak gerekiyor."; mem_alarm=1; fi
if [ "$pct" -lt 60 ] && [ "$prev_mem" = 1 ]; then alert "bellek %$pct, normale döndü."; mem_alarm=0; fi
if [ "$prev_evicted" -ge 0 ] && [ "${evicted:-0}" -gt "$prev_evicted" ]; then alert "$((evicted - prev_evicted)) anahtar bellek dolduğu için silindi (evicted_keys=$evicted)."; fi

# A site's code hitting its ACL (a denied command such as FLUSHDB/KEYS, or another prefix)
# fails only inside that site; the newest ACL LOG entry (id + repeat count) tells it apart.
acl=$(cli acl log 1 | tr -d '\r' | awk 'NR%2{k=$0; next} {v[k]=$0} END{if ("entry-id" in v) print v["entry-id"]"."v["count"]"|"v["username"]"|"v["reason"]"|"v["object"]}')
acl_sig=${acl%%|*}; acl_sig=${acl_sig:-none}
if [ -n "$prev_acl" ] && [ "$acl_sig" != none ] && [ "$acl_sig" != "$prev_acl" ]; then
  IFS='|' read -r _ acl_user acl_reason acl_object <<< "$acl"
  alert "ACL reddi: $acl_user ($acl_reason: $acl_object). Sitenin kodu izin verilmeyen bir komut ya da anahtar kullanıyor; ayrıntı: ACL LOG."
fi

echo "0 $mem_alarm ${evicted:-0} $acl_sig" > "$STATE"
