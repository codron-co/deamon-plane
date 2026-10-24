#!/usr/bin/env bash
# Per-site uptime watch for Deamon CMS sites, run off-host on main.codron (cron, every 2 min).
# A site that fails two runs in a row gets a WhatsApp alert with the reason; recovery is
# reported with the outage length; a site that stays down is re-announced every 6 hours.
#
# Site list: Plane's watch list (WATCH_LIST_URL + WATCH_LIST_TOKEN in /root/.deamon-watch.env),
# cached in $DIR/sites.txt so the watch keeps working while Plane itself is down.
# Domains listed in $DIR/ignore.txt are skipped. Log: $DIR/watch.log
# Install: tools/ops/README.md
set -u
DIR=/root/deamon-watch; STATE=$DIR/state; LOG=$DIR/watch.log
mkdir -p "$STATE"
exec 9>"$DIR/.lock"; flock -n 9 || exit 0
[ -f /root/.deamon-watch.env ] && . /root/.deamon-watch.env
FAILS_TO_ALERT=2; REMIND_SECS=21600; now=$(date +%s)
log() { echo "$(date '+%F %T') $*" >> "$LOG"; }
DOMAIN_RE='^[a-z0-9.-]+\.[a-z]{2,}$'

if [ -n "${WATCH_LIST_URL:-}" ]; then
  tmp=$(mktemp)
  if curl -sf -m 20 -H "Authorization: Bearer ${WATCH_LIST_TOKEN:-}" "$WATCH_LIST_URL" -o "$tmp" \
     && grep -Eq "$DOMAIN_RE" "$tmp"; then
    grep -E "$DOMAIN_RE" "$tmp" | sort -u > "$DIR/sites.txt"
  else
    log "watch list fetch failed, using cache"
  fi
  rm -f "$tmp"
fi
[ -s "$DIR/sites.txt" ] || exit 0

check() {
  local h=$1 out code rc ip
  ip=$(getent ahostsv4 "$h" | awk 'NR==1{print $1}')
  if [ -z "$ip" ]; then echo "$h DOWN DNS kaydı yok"; return; fi
  for try in 1 2; do
    out=$(curl -s -o /dev/null -m 20 -w '%{http_code} %{time_total}' "https://$h/" 2>/dev/null); rc=$?
    code=${out%% *}
    case "$code" in 2*|3*) echo "$h UP ${out##* }"; return;; esac
    [ "$try" = 1 ] && sleep 5
  done
  case "$rc" in
    0) echo "$h DOWN HTTP $code";;
    28) echo "$h DOWN 20 sn içinde cevap vermedi";;
    7) echo "$h DOWN bağlantı reddedildi ($ip)";;
    35|51|58|60) echo "$h DOWN SSL sertifikası geçersiz (DNS ya da Let's Encrypt sorunu)";;
    *) echo "$h DOWN bağlantı hatası (curl $rc)";;
  esac
}
export -f check

results=$(grep -vxF -f <(cat "$DIR/ignore.txt" 2>/dev/null; echo '#') "$DIR/sites.txt" \
  | xargs -P 10 -I{} bash -c 'check "$@"' _ {})

alerts=""; recovered=""; reminders=""; ndown=0
while read -r h st reason; do
  [ -n "$h" ] || continue
  f=$STATE/$h; fails=0; since=$now; last=0
  [ -f "$f" ] && read -r fails since last < "$f"
  if [ "$st" = UP ]; then
    if [ "$last" != 0 ]; then
      mins=$(( (now - since) / 60 ))
      recovered="$recovered\n🟢 $h açıldı ($mins dk kapalıydı)"; log "UP $h after ${mins}m"
    fi
    rm -f "$f"; continue
  fi
  ndown=$((ndown + 1))
  [ "$fails" = 0 ] && since=$now
  fails=$((fails + 1))
  if [ "$fails" -ge "$FAILS_TO_ALERT" ] && [ "$last" = 0 ]; then
    alerts="$alerts\n🔴 $h: $reason"; last=$now; log "DOWN $h: $reason"
  elif [ "$last" != 0 ] && [ $((now - last)) -ge "$REMIND_SECS" ]; then
    hrs=$(( (now - since) / 3600 ))
    reminders="$reminders\n⏳ $h hâlâ kapalı (${hrs} saattir): $reason"; last=$now
  fi
  echo "$fails $since $last" > "$f"
done <<< "$results"

# Domains that left the list (deleted sites) must not keep state forever.
for f in "$STATE"/*; do
  [ -e "$f" ] || continue
  grep -qxF "$(basename "$f")" "$DIR/sites.txt" || rm -f "$f"
done

msg=""
[ -n "$alerts" ] && msg="Deamon site kapalı:$alerts"
[ -n "$reminders" ] && msg="$msg${msg:+\n}$reminders"
[ -n "$recovered" ] && msg="$msg${msg:+\n}$recovered"
if [ -n "$msg" ]; then
  [ "$ndown" -ge 5 ] && msg="$msg\n\n$ndown site birden kapalı: sunucu ya da Hostinger CPU kısıtlaması olabilir https://hpanel.hostinger.com/vps/1814742/overview"
  /usr/local/bin/wa-alert.sh "$(printf '%b' "$msg")" >> "$LOG" 2>&1
fi
