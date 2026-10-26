#!/usr/bin/env bash
# Wait until the channel builder finished <commit-prefix>, then release the channel.
BRANCH=$1; PREFIX=$2
builder=$(awk -v b="$BRANCH" "\$1 == b {print \$2}" /opt/deamon-image/builders)
for i in $(seq 1 120); do
  s=$(docker exec coolify-db psql -U coolify -d coolify -At -c "SELECT q.status FROM application_deployment_queues q JOIN applications a ON a.id::text = q.application_id::text WHERE a.uuid = '$builder' AND q.commit LIKE '$PREFIX%' ORDER BY q.id DESC LIMIT 1")
  case "$s" in finished) break;; failed|cancelled*) /usr/local/bin/wa-alert.sh "Deamon üretici ($BRANCH) $PREFIX build: $s" >/dev/null 2>&1; exit 1;; esac
  sleep 15
done
[ "$s" = finished ] || exit 1
sleep 70
exec /opt/deamon-image/release.sh "$BRANCH"
