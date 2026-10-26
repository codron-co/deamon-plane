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

used=$(docker ps -a --format '{{.Image}}' | sort -u)
prune() { # repository: drop all but the newest KEEP tags no container uses
  docker images --format '{{.CreatedAt}}|{{.Repository}}:{{.Tag}}' "$1" | sort -r | tail -n +$((KEEP + 1)) | cut -d'|' -f2 |
    while read -r img; do echo "$used" | grep -qxF "$img" || { docker rmi "$img" >/dev/null 2>&1 && log "pruned $img"; }; done
}
prune deamon-cms
while read -r branch uuid; do [ -n "${uuid:-}" ] && prune "${uuid}_image"; done < "$DIR/builders"
