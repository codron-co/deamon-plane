#!/bin/bash
# Deamon off-site backup -> Hetzner Storage Box (u701755, Helsinki), nightly after the local dumps.
#   - shared MySQL dumps   /opt/deamon-db/backups     -> hz:deamon/mysql   (local copies kept 7 days)
#   - shared Redis dumps   /opt/deamon-redis/backups  -> hz:deamon/redis
#   - site files: every <app>_deamon-{storage,private,themes} volume -> hz:deamon/volumes/<volume>
#   - Coolify's own backups /data/coolify/backups      -> hz:deamon/coolify
# copy, never sync: a file deleted on the server stays in the box; the box's snapshots keep history.
# Dumps older than KEEP_DAYS are removed from the box. Secret: /root/.ssh/hetzner-sbox (port 23).
# Cron: 10 4 * * *  (after backup.sh 03:15 and the Redis dump 03:40, UTC). Log below; WhatsApp on failure.
set -u
LOG=/var/log/deamon-hetzner-yedek.log; KEEP_DAYS=${KEEP_DAYS:-30}
exec 9> /run/deamon-hetzner-yedek.lock; flock -n 9 || { echo "$(date '+%F %T') already running" >> "$LOG"; exit 0; }
export RCLONE_CONFIG_HZ_TYPE=sftp RCLONE_CONFIG_HZ_HOST=u701755.your-storagebox.de RCLONE_CONFIG_HZ_PORT=23 \
  RCLONE_CONFIG_HZ_USER=u701755 RCLONE_CONFIG_HZ_KEY_FILE=/root/.ssh/hetzner-sbox RCLONE_CONFIG_HZ_SHELL_TYPE=unix \
  RCLONE_CONFIG_HZ_MD5SUM_COMMAND=md5sum RCLONE_CONFIG_HZ_SHA1SUM_COMMAND=sha1sum \
  RCLONE_CONFIG_HZ_KNOWN_HOSTS_FILE=/root/.ssh/known_hosts
rc() { timeout 4h nice -n 19 ionice -c3 rclone "$@" --transfers 4 --checkers 8 --log-level NOTICE --stats 0 --log-file "$LOG"; }

echo "$(date '+%F %T') basladi" >> "$LOG"
kod=0; fail=""
rc copy /opt/deamon-db/backups hz:deamon/mysql || { kod=$?; fail="$fail mysql"; }
rc copy /opt/deamon-redis/backups hz:deamon/redis || { kod=$?; fail="$fail redis"; }
[ -d /data/coolify/backups ] && { rc copy /data/coolify/backups hz:deamon/coolify || { kod=$?; fail="$fail coolify"; }; }
for v in /var/lib/docker/volumes/*_deamon-storage /var/lib/docker/volumes/*_deamon-private /var/lib/docker/volumes/*_deamon-themes; do
  [ -d "$v/_data" ] || continue
  rc copy "$v/_data" "hz:deamon/volumes/$(basename "$v")" --exclude "framework/**" || { kod=$?; fail="$fail $(basename "$v")"; }
done
rc delete hz:deamon/mysql --min-age "${KEEP_DAYS}d" --rmdirs || true
rc delete hz:deamon/redis --min-age "${KEEP_DAYS}d" || true

echo "$(date '+%F %T') bitti kod=$kod${fail:+ hata:$fail}" >> "$LOG"
[ "$kod" = 0 ] || /usr/local/bin/wa-alert.sh "Deamon Hetzner yedeği hatalı:$fail (log $LOG)" >/dev/null 2>&1 || true
exit "$kod"
