#!/usr/bin/env bash
# Run to-dockerimage.sh for a list of compose app uuids, one site at a time, each under the release
# lock (a release must not read Plane's site list while a site's app uuid changes). Waits for CPU below
# MAX_CPU before each site; stops at the first failure and sends a WhatsApp alert.
#   to-dockerimage-fleet.sh <uuid-list-file>      (one "<uuid> [name...]" per line, # comments)
# Run detached: setsid nohup to-dockerimage-fleet.sh list > /dev/null 2>&1 &   Log: to-dockerimage.log
set -uo pipefail
LIST=${1:?usage: to-dockerimage-fleet.sh <uuid-list-file>}
DIR=/opt/deamon-image; LOG=$DIR/to-dockerimage.log; MAX_CPU=${MAX_CPU:-70}
log() { echo "$(date '+%F %T') [fleet] $*" | tee -a "$LOG"; }
alert() { log "ALERT: $*"; /usr/local/bin/wa-alert.sh "Deamon dockerimage geçişi: $*" >/dev/null 2>&1 || true; }
cpu_busy() { local a b; a=($(awk '/^cpu /{print $2+$3+$4+$6+$7+$8+$9, $5}' /proc/stat)); sleep 5
  b=($(awk '/^cpu /{print $2+$3+$4+$6+$7+$8+$9, $5}' /proc/stat)); echo $(( 100 * (b[0]-a[0]) / ((b[0]-a[0]) + (b[1]-a[1]) + 1) )); }

uuids=$(grep -vE '^\s*(#|$)' "$LIST" | awk '{print $1}'); n=$(wc -w <<< "$uuids"); i=0
log "=== start: $n sites"
for u in $uuids; do
  i=$((i + 1))
  for t in $(seq 1 60); do [ "$(cpu_busy)" -lt "$MAX_CPU" ] && break; sleep 20; done
  # The release lock is taken per site: a release can run between two sites, never during one.
  if ! ( flock -w 3600 9 || exit 1; "$DIR/to-dockerimage.sh" "$u" --yes ) 9> "$DIR/.release.lock" < /dev/null >> "$DIR/to-dockerimage.out" 2>&1; then
    alert "$u ($i/$n) durdu; ayrıntı $LOG. Önceki $((i - 1)) site taşındı."; exit 1
  fi
  log "$i/$n ok $u"
done
log "=== done: $n sites"
/usr/local/bin/wa-alert.sh "Deamon dockerimage geçişi bitti: $n site kesintisiz deploy'a geçti." >/dev/null 2>&1 || true
