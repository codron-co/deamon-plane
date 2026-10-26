#!/usr/bin/env bash
# Move one Deamon site from its Docker Compose Coolify app to a Docker Image app (zero-downtime
# rolling deploys). The new app runs the same image (127.0.0.1:5000/deamon-cms:<commit>), env,
# volumes (same volume names) and domains; Traefik retry + 1s dial timeout (proxy/deamon-retry.yaml)
# cover the moment the old container goes away. The old app is only stopped: delete it later
# from Coolify with "delete volumes" OFF, the new app mounts the same volumes.
#   to-dockerimage.sh <old-app-uuid> [--yes]      (without --yes: show the plan only)
# Needs /tmp/cool.php in the Plane container (raw Coolify API helper with Plane's credentials).
# Pilot: deamon-test 2026-10-26 (6cmmgh5ty9lzv6uiz5kfavue -> ccqqtbr0r0dk91wxyjvxojlc).
set -euo pipefail
OLD=${1:?usage: to-dockerimage.sh <old-app-uuid> [--yes]}; YES=${2:-}
DIR=/opt/deamon-image; LOG=$DIR/to-dockerimage.log; REG=127.0.0.1:5000/deamon-cms
DEST=31gww8sy8nazbukhozgzlbzw   # Coolify standalone docker "deamon" (network deamon-db)
SERVER=no48ksggg0k8sk4o4w08gks8
log() { echo "$(date '+%F %T') [$OLD] $*" | tee -a "$LOG"; }
die() { log "STOP: $*"; exit 1; }
q() { docker exec coolify-db psql -U coolify -d coolify -At -c "$1"; }
plane=$(docker ps --format '{{.Names}}' | grep -m1 '^app-d6ovbjzxgpao23faam3vrcve') || die "Plane container not found"
docker exec "$plane" test -f /tmp/cool.php || die "/tmp/cool.php missing in $plane"
tinker() { # <php file>: run it in Plane, print its output
  local f; f=$(basename "$1")
  docker cp "$1" "$plane:/tmp/$f" >/dev/null
  docker exec "$plane" php artisan tinker --execute "require '/tmp/$f';" 2>&1 || true
  docker exec "$plane" rm -f "/tmp/$f"
}

[ "$(q "SELECT build_pack FROM applications WHERE uuid = '$OLD'")" = dockercompose ] || die "not a compose app"
name=$(q "SELECT name FROM applications WHERE uuid = '$OLD'")
row=$(q "SELECT p.uuid, e.uuid FROM applications a JOIN environments e ON e.id = a.environment_id JOIN projects p ON p.id = e.project_id WHERE a.uuid = '$OLD'")
project=${row%%|*}; env_uuid=${row##*|}
domains=$(q "SELECT docker_compose_domains::json->'app'->>'domain' FROM applications WHERE uuid = '$OLD'" | sed -E 's#/+(,|$)#\1#g')
[ -n "$domains" ] || die "no domains"
c=$(docker ps --format '{{.Names}}' | grep -m1 "^app-$OLD-") || die "no running app container"
img=$(docker inspect "$c" --format '{{.Config.Image}}'); sha=${img##*:}
[[ "$img" =~ ^deamon-cms:[0-9a-f]{40}$ ]] || die "container image is $img, expected deamon-cms:<commit>"
[ "$(docker inspect "$c" --format '{{.State.Health.Status}}')" = healthy ] || die "$c is not healthy"
"$DIR/tag-images.sh"
curl -sf -o /dev/null -H 'Accept: application/vnd.oci.image.index.v1+json,application/vnd.docker.distribution.manifest.v2+json' \
  "http://127.0.0.1:5000/v2/deamon-cms/manifests/$sha" || die "$REG:${sha:0:8} not in registry"
echo "site:    $name ($OLD), container $c"
echo "image:   $REG:$sha"
echo "domains: $domains"
echo "volumes: $(docker inspect "$c" --format '{{range .Mounts}}{{.Name}}:{{.Destination}} {{end}}')"
[ "$YES" = --yes ] || { echo "(plan only; add --yes to run)"; exit 0; }
log "start: $name -> dockerimage ${sha:0:8}"

T=$(mktemp -d); trap 'rm -rf "$T"' EXIT; umask 077
# Env values never leave the host: container env -> file in Plane -> Coolify API.
docker inspect "$c" --format '{{range .Config.Env}}{{println .}}{{end}}' > "$T/env"
docker cp "$T/env" "$plane:/tmp/dtimg-env-$OLD" >/dev/null; rm -f "$T/env"
cpus=$(docker inspect "$c" --format '{{.HostConfig.NanoCpus}}'); cpus=$(awk -v n="$cpus" 'BEGIN { printf "%g", (n > 0 ? n / 1e9 : 2) }')
mem=$(docker inspect "$c" --format '{{.HostConfig.Memory}}'); mem=$(( mem > 0 ? mem / 1048576 : 1024 ))m

cat > "$T/dtimg-create-$OLD.php" <<PHP
<?php
require '/tmp/cool.php';
\$env = [];
foreach (file('/tmp/dtimg-env-$OLD', FILE_IGNORE_NEW_LINES) as \$line) {
    if (! str_contains(\$line, '=')) continue;
    [\$k, \$v] = explode('=', \$line, 2);
    if (\$k === 'SERVICE_URL_APP') \$env['APP_URL'] = \$v;
    if (\$k === 'SERVICE_FQDN_APP') \$env['DEAMON_SITE_HOST'] = \$v;
    if (preg_match('/^(PATH|PHPIZE_DEPS|PHP_[A-Z0-9_]+|GPG_KEYS|COOLIFY_[A-Z_]+|SERVICE_[A-Z_]+|SOURCE_COMMIT|MYSQL_ROOT_PASSWORD|HOSTNAME|HOME)\$/', \$k)) continue;
    \$env[\$k] = \$v;
}
@unlink('/tmp/dtimg-env-$OLD');
\$r = cool('POST', 'api/v1/applications/dockerimage', [
    'project_uuid' => '$project', 'environment_uuid' => '$env_uuid', 'server_uuid' => '$SERVER', 'destination_uuid' => '$DEST',
    'name' => '$name', 'description' => 'Deamon site (docker image, zero-downtime); was compose app $OLD',
    'docker_registry_image_name' => '$REG', 'docker_registry_image_tag' => '$sha', 'ports_exposes' => '8080',
    'domains' => '$domains', 'force_domain_override' => true, 'limits_cpus' => '$cpus', 'limits_memory' => '$mem', 'custom_docker_run_options' => '--pids-limit 256',
    'health_check_enabled' => true, 'health_check_path' => '/up', 'health_check_port' => '8080', 'health_check_interval' => 5,
    'health_check_timeout' => 5, 'health_check_retries' => 36, 'health_check_start_period' => 5,
    'is_auto_deploy_enabled' => false, 'autogenerate_domain' => false, 'instant_deploy' => false,
]);
\$uuid = \$r['json']['uuid'] ?? null;
if (! \$uuid) { echo 'FAIL create ', \$r['status'], ' ', json_encode(\$r['json']), PHP_EOL; return; }
\$data = [];
foreach (\$env as \$k => \$v) \$data[] = ['key' => \$k, 'value' => \$v, 'is_literal' => true];
\$ok = cool('PATCH', "api/v1/applications/\$uuid/envs/bulk", ['data' => \$data])['status'] < 300;
foreach (['deamon-storage' => '/var/www/html/storage/app/public', 'deamon-private' => '/var/www/html/storage/app/private', 'deamon-themes' => '/var/www/html/themes'] as \$n => \$m) {
    \$ok = cool('POST', "api/v1/applications/\$uuid/storages", ['type' => 'persistent', 'name' => \$n, 'mount_path' => \$m])['status'] < 300 && \$ok;
}
echo (\$ok ? 'NEWAPP ' : 'PARTIAL '), \$uuid, ' envs=', count(\$data), PHP_EOL;
PHP
# A run that stopped after creating the app (envs + volumes done) is picked up again.
new=$(q "SELECT a.uuid FROM applications a WHERE a.description LIKE '%was compose app $OLD' AND a.deleted_at IS NULL AND (SELECT count(*) FROM local_persistent_volumes v WHERE v.resource_id = a.id AND v.resource_type = 'App\\Models\\Application') = 3 LIMIT 1")
if [ -n "$new" ]; then
  docker exec "$plane" rm -f "/tmp/dtimg-env-$OLD"; log "reusing $new from an earlier run"
else
  out=$(tinker "$T/dtimg-create-$OLD.php"); docker exec "$plane" rm -f "/tmp/dtimg-env-$OLD"
  new=$(grep -oE 'NEWAPP [a-z0-9]{24}' <<< "$out" | cut -d' ' -f2 || true)
  [ -n "$new" ] || die "create failed: $(tail -c 300 <<< "$out")"
  log "created $new ($(grep -oE 'envs=[0-9]+' <<< "$out"))"
fi
id=$(q "SELECT id FROM applications WHERE uuid = '$new'")

# Same volumes as the compose app, image HEALTHCHECK, Traefik retry + 1s dial on its routers/services.
q "UPDATE local_persistent_volumes SET name = replace(name, '$new-', '${OLD}_') WHERE resource_id = $id AND resource_type = 'App\\Models\\Application'" >/dev/null
q "UPDATE applications SET custom_healthcheck_found = true WHERE id = $id" >/dev/null
q "UPDATE application_settings SET is_container_label_readonly_enabled = false WHERE application_id = $id" >/dev/null
# Coolify stores labels as plain text on API create and base64 after a UI/PATCH save.
raw=$(q "SELECT custom_labels FROM applications WHERE id = $id")
if grep -q '^traefik\.' <<< "$raw"; then plain=$raw; else plain=$(base64 -d <<< "$raw"); fi
labels=$(printf '%s\n' "$plain" | grep -v 'serverstransport=deamon-fast@file' \
  | sed -E "s#^(traefik\.http\.routers\.https-[0-9]+-$new\.middlewares)=gzip-sse\$#\1=deamon-retry@file,gzip-sse#" \
  | sed -E "s#^(traefik\.http\.services\.(https?-[0-9]+-$new)\.loadbalancer\.server\.port=8080)\$#\1\ntraefik.http.services.\2.loadbalancer.serverstransport=deamon-fast@file#")
grep -q 'deamon-retry@file' <<< "$labels" && grep -q 'deamon-fast@file' <<< "$labels" || die "labels not patched ($new created, not deployed)"
q "UPDATE applications SET custom_labels = '$(base64 -w0 <<< "$labels")' WHERE id = $id" >/dev/null
for v in deamon-storage deamon-private deamon-themes; do
  [ "$(q "SELECT count(*) FROM local_persistent_volumes WHERE resource_id = $id AND name = '${OLD}_$v'")" = 1 ] \
    || die "volume ${OLD}_$v not mapped ($new created, not deployed)"
done

# Deploy next to the old app (both serve the domains for a moment), verify, then stop the old app.
cat > "$T/dtimg-go-$OLD.php" <<PHP
<?php
require '/tmp/cool.php';
\$r = cool('POST', 'api/v1/deploy', ['uuid' => '$new']);
echo 'DEPLOY ', \$r['json']['deployments'][0]['deployment_uuid'] ?? json_encode(\$r['json']), PHP_EOL;
PHP
d=$(tinker "$T/dtimg-go-$OLD.php" | grep -oE 'DEPLOY [a-z0-9]{24}' | cut -d' ' -f2 || true)
[ -n "$d" ] || die "deploy of $new did not queue (old app untouched)"
s=""; for i in $(seq 1 90); do
  s=$(q "SELECT status FROM application_deployment_queues WHERE deployment_uuid = '$d'")
  case "$s" in finished|failed|cancelled*) break;; esac; sleep 5
done
[ "$s" = finished ] || die "deploy $d: $s (old app untouched)"
nc=$(docker ps --format '{{.Names}}' | grep -m1 "^$new-") || die "new container missing (old app untouched)"
for i in $(seq 1 24); do [ "$(docker inspect "$nc" --format '{{.State.Health.Status}}')" = healthy ] && break; sleep 5; done
up=$(docker exec "$nc" curl -s -o /dev/null -m 10 -w '%{http_code}' http://127.0.0.1:8080/up || true)
[ "$up" = 200 ] || die "new container /up=$up (old app still serving)"
host=${domains%%,*}; host=${host#https://}; host=${host#http://}
pub=$(curl -s -o /dev/null -m 15 --resolve "$host:443:127.0.0.1" -w '%{http_code}' "https://$host/up" || true)
log "new $nc healthy, /up 200, https://$host/up $pub"

cat > "$T/dtimg-stop-$OLD.php" <<PHP
<?php
require '/tmp/cool.php';
\$r = cool('POST', 'api/v1/applications/$OLD/stop');
echo 'STOP ', \$r['status'], PHP_EOL;
\$s = App\Models\Site::where('coolify_app_uuid', '$OLD')->first();
if (\$s) { \$s->coolify_app_uuid = '$new'; \$s->save(); echo 'PLANE ', \$s->slug, PHP_EOL; } else { echo 'PLANE none', PHP_EOL; }
PHP
out=$(tinker "$T/dtimg-stop-$OLD.php"); log "$(grep -E '^(STOP|PLANE)' <<< "$out" | paste -sd' ')"
for i in $(seq 1 30); do docker ps --format '{{.Names}}' | grep -q "^app-$OLD-" || break; sleep 3; done
pub=$(curl -s -o /dev/null -m 15 --resolve "$host:443:127.0.0.1" -w '%{http_code}' "https://$host/up" || true)
log "done: $name is now $new (dockerimage ${sha:0:8}); old app stopped; https://$host/up $pub"
