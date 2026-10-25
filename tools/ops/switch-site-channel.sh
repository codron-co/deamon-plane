#!/usr/bin/env bash
# Switch a Deamon site's release channel without a build, when the target branch points at
# the same code the site already runs (e.g. main = beta = alpha after a branch unify).
# Run on the coolify VPS as root:   switch-site-channel.sh <app-uuid> <main|beta|alpha>
#
# Does what Plane's ChannelSwitcher does except the deploy: Coolify branch + HEAD, Coolify
# env APP_ENV/DEAMON_CHANNEL, Plane site.channel (audit row "site.channel_switched"). Then
# writes the same two values into Coolify's generated .env and recreates only the app
# container from its current image (~12 s). The next normal deploy builds from the new branch.
set -euo pipefail
UUID=${1:?app uuid}; TARGET=${2:?channel}
case "$TARGET" in main|beta|alpha) ;; *) echo "bad channel $TARGET"; exit 2;; esac
LOG=/opt/deamon-db/channel.log
APPDIR=/data/coolify/applications/$UUID
log() { echo "$(date '+%F %T') [$UUID] $*" | tee -a "$LOG"; }
die() { log "ABORT: $*"; exit 1; }

plane=$(docker ps --format '{{.Names}}' | grep '^app-d6ovbjzxgpao23faam3vrcve' | head -1)
[ -n "$plane" ] || die "Plane container not found"
[ -f "$APPDIR/.env" ] && [ -f "$APPDIR/docker-compose.yaml" ] || die "no Coolify compose/.env in $APPDIR"
app=$(docker ps --format '{{.Names}}' | grep "^app-$UUID" | head -1)
[ -n "$app" ] || die "app container not running"

out=$(docker exec "$plane" php artisan tinker --execute "
\$s = App\Models\Site::where('coolify_app_uuid', '$UUID')->first();
if (! \$s) { echo 'RESULT=no_site'; } else {
\$t = App\Enums\Channel::from('$TARGET');
\$c = App\Services\Coolify\CoolifyApplicationService::forSite(\$s);
\$c->patchApplication('$UUID', ['git_branch' => \$t->value, 'git_commit_sha' => App\Services\Coolify\Dto\CoolifyApplication::HEAD_REF]);
\$env = App\Services\Sites\ChannelEnvironmentMap::appEnv(\$t);
\$c->updateEnvs('$UUID', ['APP_ENV' => \$env, 'DEAMON_CHANNEL' => \$t->value]);
\$before = \$s->channel instanceof App\Enums\Channel ? \$s->channel->value : (string) \$s->channel;
\$s->forceFill(['channel' => \$t, 'desired_channel' => null, 'channel_needs_review' => false])->save();
\$s->auditLogs()->create(['actor_user_id' => null, 'action' => 'site.channel_switched', 'before' => ['channel' => \$before], 'after' => ['channel' => \$t->value, 'method' => 'no-build (same commit), tools/ops/switch-site-channel.sh']]);
echo 'RESULT=ok FROM='.\$before.' APP_ENV='.\$env;
}
" 2>&1 | grep -o 'RESULT=.*' | tail -1)
log "plane: ${out:-no output}"
case "$out" in RESULT=ok*) ;; *) die "Plane/Coolify update failed: $out";; esac
app_env=$(sed -n 's/.*APP_ENV=//p' <<< "$out")

set_env() { # KEY VALUE (Coolify's .env has no trailing newline)
  if grep -q "^$1=" "$APPDIR/.env"; then sed -i "s|^$1=.*|$1=\"$2\"|" "$APPDIR/.env"
  else [ -z "$(tail -c1 "$APPDIR/.env")" ] || echo >> "$APPDIR/.env"; echo "$1=\"$2\"" >> "$APPDIR/.env"; fi
}
set_env DEAMON_CHANNEL "$TARGET"
set_env APP_ENV "$app_env"
grep -q "^DEAMON_CHANNEL=\"$TARGET\"$" "$APPDIR/.env" || die "DEAMON_CHANNEL not written to .env (Plane/Coolify already switched; recreate by hand)"

(cd "$APPDIR" && docker compose -p "$UUID" -f docker-compose.yaml up -d --no-build --no-deps --force-recreate app) >> "$LOG" 2>&1 || log "compose recreate reported an error"
for i in $(seq 1 60); do
  sleep 3
  c=$(docker ps --format '{{.Names}}' | grep "^app-$UUID" | head -1) || true
  [ -n "$c" ] && [ "$(docker exec "$c" printenv DEAMON_CHANNEL 2>/dev/null)" = "$TARGET" ] || continue
  [ "$(docker exec "$c" curl -s -o /dev/null -m 5 -w '%{http_code}' http://127.0.0.1:8080/up 2>/dev/null)" = 200 ] && { log "DONE: $c on $TARGET (DB_HOST=$(docker exec "$c" printenv DB_HOST))"; exit 0; }
done
die "app did not answer /up on $TARGET within 3 min (check: docker logs $(docker ps -a --format '{{.Names}}' | grep "^app-$UUID" | head -1))"
