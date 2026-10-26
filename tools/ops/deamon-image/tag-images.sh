#!/usr/bin/env bash
# Retag images built by the deamon-image-<branch> Coolify apps as deamon-cms:<commit> and
# prune old ones. Cron: every minute. Builders: /opt/deamon-image/builders ("<branch> <uuid>").
# Keeps the newest KEEP commits plus anything a container (running or stopped) uses.
set -u
DIR=/opt/deamon-image; KEEP=${KEEP:-8}; LOG=$DIR/tag.log
exec 9> "$DIR/.tag.lock"; flock -n 9 || exit 0
log() { echo "$(date '+%F %T') $*" >> "$LOG"; }

while read -r branch uuid; do
  [ -n "${uuid:-}" ] || continue
  docker images --format '{{.Repository}}:{{.Tag}}' "${uuid}_image" | while read -r img; do
    tag=${img##*:}
    [[ "$tag" =~ ^[0-9a-f]{40}$ ]] || continue
    docker image inspect "deamon-cms:$tag" >/dev/null 2>&1 && continue
    docker tag "$img" "deamon-cms:$tag" && log "tagged deamon-cms:$tag ($branch, from $img)"
  done
done < "$DIR/builders"

# Docker Image (zero-downtime) sites pull from the local registry (127.0.0.1:5000, deamon-registry):
# push every deamon-cms:<commit> there once. The local REG tag marks "pushed".
REG=127.0.0.1:5000/deamon-cms
docker images --format '{{.Tag}}' deamon-cms | while read -r tag; do
  [[ "$tag" =~ ^[0-9a-f]{40}$ ]] || continue
  docker image inspect "$REG:$tag" >/dev/null 2>&1 && continue
  if docker tag "deamon-cms:$tag" "$REG:$tag" && docker push -q "$REG:$tag" >/dev/null 2>&1; then log "pushed $REG:$tag"
  else docker rmi "$REG:$tag" >/dev/null 2>&1; log "push failed $REG:$tag"; fi
done

# Safety net: a Deamon site must never auto-deploy (a push would start an image that is not
# built yet) and needs SOURCE_COMMIT for its image tag. Covers sites Plane creates later.
changed=$(docker exec coolify-db psql -U coolify -d coolify -At -c "UPDATE application_settings s SET is_auto_deploy_enabled = false, include_source_commit_in_build = true FROM applications a WHERE s.application_id = a.id AND a.git_repository ILIKE '%codron-co/deamon%' AND a.git_repository NOT ILIKE '%plane%' AND a.name NOT LIKE 'deamon-image-%' AND (s.is_auto_deploy_enabled OR NOT s.include_source_commit_in_build) RETURNING a.name" 2>/dev/null | grep -v '^UPDATE' | paste -sd' ')
[ -n "$changed" ] && log "enforced auto-deploy off + source commit on: $changed"

# Safety net for Docker Image sites: their labels carry the Traefik retry + 1 s dial timeout
# (proxy/deamon-retry.yaml), so Coolify must not regenerate them (read-only labels off) and a
# label set without them (a new site, a Coolify UI save) gets them back. Applies on next deploy.
q() { docker exec coolify-db psql -U coolify -d coolify -At -c "$1" 2>/dev/null; }
ro=$(q "UPDATE application_settings s SET is_container_label_readonly_enabled = false FROM applications a WHERE s.application_id = a.id AND a.build_pack = 'dockerimage' AND a.docker_registry_image_name LIKE '%deamon-cms' AND a.deleted_at IS NULL AND s.is_container_label_readonly_enabled RETURNING a.name" | grep -v '^UPDATE' | paste -sd' ')
[ -n "$ro" ] && log "enforced read-only labels off: $ro"
q "SELECT id || ' ' || uuid FROM applications WHERE build_pack = 'dockerimage' AND docker_registry_image_name LIKE '%deamon-cms' AND deleted_at IS NULL" | while read -r id u; do
  raw=$(q "SELECT custom_labels FROM applications WHERE id = $id"); [ -n "$raw" ] || continue
  # Coolify stores plain text on API create and base64 after a UI/PATCH save.
  if grep -q '^traefik\.' <<< "$raw"; then plain=$raw; else plain=$(base64 -d <<< "$raw" 2>/dev/null) || continue; fi
  grep -q 'deamon-retry@file' <<< "$plain" && grep -q 'deamon-fast@file' <<< "$plain" && continue
  fixed=$(printf '%s\n' "$plain" | grep -v 'serverstransport=deamon-fast@file' \
    | sed -E "s#^(traefik\.http\.routers\.https-[0-9]+-$u\.middlewares)=gzip-sse\$#\1=deamon-retry@file,gzip-sse#" \
    | sed -E "s#^(traefik\.http\.services\.(https?-[0-9]+-$u)\.loadbalancer\.server\.port=[0-9]+)\$#\1\ntraefik.http.services.\2.loadbalancer.serverstransport=deamon-fast@file#")
  grep -q 'deamon-retry@file' <<< "$fixed" && grep -q 'deamon-fast@file' <<< "$fixed" || { log "labels: could not patch $u"; continue; }
  q "UPDATE applications SET custom_labels = '$(base64 -w0 <<< "$fixed")' WHERE id = $id" >/dev/null && log "labels: retry + fast dial added to $u"
done

used=$(docker ps -a --format '{{.Image}}' | sort -u)
prune() { # repository: drop all but the newest KEEP tags no container uses
  docker images --format '{{.CreatedAt}}|{{.Repository}}:{{.Tag}}' "$1" | sort -r | tail -n +$((KEEP + 1)) | cut -d'|' -f2 |
    while read -r img; do echo "$used" | grep -qxF "$img" || { docker rmi "$img" >/dev/null 2>&1 && log "pruned $img"; }; done
}
prune deamon-cms
prune "$REG"
# Registry side: drop the commits no longer tagged locally (blobs go in the weekly
# garbage-collect cron: docker exec deamon-registry registry garbage-collect ...).
ACCEPT='application/vnd.docker.distribution.manifest.v2+json,application/vnd.oci.image.index.v1+json,application/vnd.oci.image.manifest.v1+json'
curl -sf http://127.0.0.1:5000/v2/deamon-cms/tags/list | grep -oE '[0-9a-f]{40}' | while read -r tag; do
  docker image inspect "$REG:$tag" >/dev/null 2>&1 && continue
  digest=$(curl -sfI -H "Accept: $ACCEPT" "http://127.0.0.1:5000/v2/deamon-cms/manifests/$tag" | tr -d '\r' | awk -F': ' 'tolower($1) == "docker-content-digest" {print $2}')
  [ -n "$digest" ] && curl -sf -X DELETE "http://127.0.0.1:5000/v2/deamon-cms/manifests/$digest" >/dev/null && log "registry: deleted $tag"
done
while read -r branch uuid; do [ -n "${uuid:-}" ] && prune "${uuid}_image"; done < "$DIR/builders"
