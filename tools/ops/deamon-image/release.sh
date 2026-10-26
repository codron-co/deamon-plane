#!/usr/bin/env bash
# Deploy the sites of a channel to the newest commit its builder finished (build once, run many).
#   release.sh <alpha|beta> [site-list-file]   (default: every active Plane site on that channel)
# Per site: CPU below MAX_CPU, pin the Coolify app to the commit (so a later push can never make
# it start an image that does not exist), deploy, then require healthy + /up 200 + the right image.
# Stops at the first site that does not come back and sends a WhatsApp alert. Log: release.log
set -uo pipefail
BRANCH=${1:?usage: release.sh <alpha|beta> [site-list-file]}; LIST=${2:-}
DIR=/opt/deamon-image; LOG=$DIR/release.log; MAX_CPU=${MAX_CPU:-80}
exec 9> "$DIR/.release.lock"; flock -w 600 9 || { echo "another release (or a dockerimage move) is running"; exit 1; }
log() { echo "$(date '+%F %T') [$BRANCH] $*" | tee -a "$LOG"; }
alert() { log "ALERT: $*"; /usr/local/bin/wa-alert.sh "Deamon yayın ($BRANCH): $*" >/dev/null 2>&1 || true; }
q() { docker exec coolify-db psql -U coolify -d coolify -At -c "$1" 2>/dev/null; }
plane=$(docker ps --format '{{.Names}}' | grep '^app-d6ovbjzxgpao23faam3vrcve' | head -1)
tinker() { docker exec "$plane" php artisan tinker --execute "$1" 2>&1 | tail -1; }

builder=$(awk -v b="$BRANCH" '$1 == b {print $2}' "$DIR/builders")
[ -n "$builder" ] || { log "no builder for $BRANCH"; exit 1; }
sha=$(q "SELECT q.commit FROM application_deployment_queues q JOIN applications a ON a.id::text = q.application_id::text WHERE a.uuid = '$builder' AND q.status = 'finished' ORDER BY q.id DESC LIMIT 1")
[[ "$sha" =~ ^[0-9a-f]{40}$ ]] || { log "builder has no finished build ($sha)"; exit 1; }
"$DIR/tag-images.sh"
docker image inspect "deamon-cms:$sha" >/dev/null 2>&1 || { alert "deamon-cms:${sha:0:8} yok; yayın başlamadı"; exit 1; }
# Docker Image sites (zero-downtime rolling update) pull 127.0.0.1:5000/deamon-cms:<sha>.
REG=127.0.0.1:5000/deamon-cms
curl -sf -o /dev/null -H 'Accept: application/vnd.docker.distribution.manifest.v2+json,application/vnd.oci.image.index.v1+json,application/vnd.oci.image.manifest.v1+json' "http://127.0.0.1:5000/v2/deamon-cms/manifests/$sha"   || { alert "$REG:${sha:0:8} registry'de yok; yayın başlamadı"; exit 1; }

if [ -n "$LIST" ]; then uuids=$(grep -vE '^\s*(#|$)' "$LIST" | awk '{print $1}')
else uuids=$(tinker "echo App\Models\Site::where('channel', '$BRANCH')->where('status', 'active')->whereNotNull('coolify_app_uuid')->orderBy('slug')->pluck('coolify_app_uuid')->implode(' ');"); fi
n=$(wc -w <<< "$uuids"); log "=== start: ${sha:0:8} to $n sites"

cpu_busy() { local a b; a=($(awk '/^cpu /{print $2+$3+$4+$6+$7+$8+$9, $5}' /proc/stat)); sleep 5
  b=($(awk '/^cpu /{print $2+$3+$4+$6+$7+$8+$9, $5}' /proc/stat)); echo $(( 100 * (b[0]-a[0]) / ((b[0]-a[0]) + (b[1]-a[1]) + 1) )); }
PARALLEL=${PARALLEL:-3}
RES=$(mktemp -d); trap 'rm -rf "$RES"' EXIT

deploy_site() { # <uuid>: deploy one site to $sha and verify; result file $RES/<uuid>.ok|.fail
  local u=$1 name c s img h up out since try i
  name=$(q "SELECT name FROM applications WHERE uuid = '$u'")
  local want pin
  if [ "$(q "SELECT build_pack FROM applications WHERE uuid = '$u'")" = dockerimage ]; then
    want="$REG:$sha"; pin="'docker_registry_image_tag' => '$sha'"
  else want="deamon-cms:$sha"; pin="'git_commit_sha' => '$sha'"; fi
  # Coolify leaves SOURCE_COMMIT empty unless this is on; the compose image tag needs it.
  # rememberPin mirrors the release pin onto the Plane site row (sites list, CI rollouts).
  q "UPDATE application_settings SET include_source_commit_in_build = true WHERE application_id = (SELECT id FROM applications WHERE uuid = '$u')" >/dev/null
  since=$(date -u '+%F %T')
  # Plane may be restarting (its own deploy); find the container again and retry.
  for try in 1 2 3; do
    plane=$(docker ps --format '{{.Names}}' | grep '^app-d6ovbjzxgpao23faam3vrcve' | head -1)
    out=$(tinker "\$s = App\Models\Site::where('coolify_app_uuid', '$u')->firstOrFail(); \$c = App\Services\Coolify\CoolifyApplicationService::forSite(\$s); \$c->patchApplication('$u', [$pin, 'is_auto_deploy_enabled' => false]); App\Services\Sites\CoolifyDeploySettings::rememberPin(\$s, '$sha'); echo json_encode(\$c->deploy('$u'));")
    grep -q 'deployment queued' <<< "$out" && break
    log "deploy call for $name did not queue (try $try): ${out:0:160}"; sleep 30
  done
  s=""; for i in $(seq 1 90); do
    s=$(q "SELECT q.status FROM application_deployment_queues q JOIN applications a ON a.id::text = q.application_id::text WHERE a.uuid = '$u' AND q.created_at >= '$since'::timestamp - interval '10 seconds' ORDER BY q.id DESC LIMIT 1")
    case "$s" in finished|failed|cancelled*) break;; esac; sleep 5; done
  sleep 15
  c=$(docker ps --format '{{.Names}}' | grep -E "^(app-)?$u-" | head -1)
  img=$(docker inspect "$c" --format '{{.Config.Image}}' 2>/dev/null); h=$(docker inspect "$c" --format '{{.State.Health.Status}}' 2>/dev/null)
  up=$(docker exec "$c" curl -s -o /dev/null -m 10 -w '%{http_code}' http://127.0.0.1:8080/up 2>/dev/null)
  for i in $(seq 1 12); do [ "$h" = healthy ] && [ "$up" = 200 ] && break; sleep 10
    h=$(docker inspect "$c" --format '{{.State.Health.Status}}' 2>/dev/null); up=$(docker exec "$c" curl -s -o /dev/null -m 10 -w '%{http_code}' http://127.0.0.1:8080/up 2>/dev/null); done
  if [ "$s" = finished ] && [ "$img" = "$want" ] && [ "$h" = healthy ] && [ "$up" = 200 ]; then
    touch "$RES/$u.ok"; log "ok $name ($u)"
  else
    echo "$name (deploy=$s image=$img health=$h up=$up)" > "$RES/$u.fail"; log "FAIL $name ($u): deploy=$s image=$img health=$h up=$up"
  fi
}

# Up to PARALLEL sites at once (Coolify runs 3 deployments on this host). After the first
# failure no new site starts; the running ones finish, then the run stops with an alert.
for u in $uuids; do
  ls "$RES"/*.fail >/dev/null 2>&1 && break
  c=$(docker ps --format '{{.Names}}' | grep -E "^(app-)?$u-" | head -1)
  if [ -n "$c" ] && [[ "$(docker inspect "$c" --format '{{.Config.Image}}' 2>/dev/null)" =~ ^(127\.0\.0\.1:5000/)?deamon-cms:$sha$ ]]      && [ "$(docker inspect "$c" --format '{{.State.Health.Status}}' 2>/dev/null)" = healthy ]; then
    touch "$RES/$u.skip"; log "skip $(q "SELECT name FROM applications WHERE uuid = '$u'") ($u): already on ${sha:0:8}"; continue
  fi
  while [ "$(jobs -rp | wc -l)" -ge "$PARALLEL" ]; do wait -n; done
  for i in $(seq 1 120); do
    [ "$(cpu_busy)" -lt "$MAX_CPU" ] && [ "$(q "SELECT COUNT(*) FROM application_deployment_queues WHERE status IN ('queued','in_progress')")" -lt "$PARALLEL" ] && break; sleep 20
  done
  deploy_site "$u" &
  sleep 3
done
wait
done_n=$(( $(ls "$RES"/*.ok 2>/dev/null | wc -l) + $(ls "$RES"/*.skip 2>/dev/null | wc -l) ))
if ls "$RES"/*.fail >/dev/null 2>&1; then
  alert "$(cat "$RES"/*.fail | paste -sd';') sorunlu; $done_n/$n site yayında, sıra durdu"; exit 1
fi
log "=== done: $done_n/$n sites on deamon-cms:${sha:0:8}"
/usr/local/bin/wa-alert.sh "Deamon yayın ($BRANCH) bitti: $done_n site ${sha:0:8} sürümünde." >/dev/null 2>&1 || true
