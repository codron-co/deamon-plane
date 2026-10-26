#!/usr/bin/env bash
# One-off: wait for the builder deployment, then release the channel.
DEP=$1; BRANCH=$2
for i in $(seq 1 120); do
  s=$(docker exec coolify-db psql -U coolify -d coolify -At -c "SELECT status FROM application_deployment_queues WHERE deployment_uuid = '$DEP'")
  case "$s" in finished) break;; failed|cancelled*) echo "$(date "+%F %T") [$BRANCH] builder $DEP $s" >> /opt/deamon-image/release.log; /usr/local/bin/wa-alert.sh "Deamon üretici ($BRANCH) build başarısız: $s" >/dev/null 2>&1; exit 1;; esac
  sleep 15
done
sleep 70   # tag-images.sh runs every minute
exec /opt/deamon-image/release.sh "$BRANCH"
