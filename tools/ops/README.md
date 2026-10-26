# Ops tools (server-side)

Scripts that run on the servers, kept here so the live copies have a source.

| File | Runs on | What |
|---|---|---|
| `deamon-watch.sh` | main.codron (`/usr/local/bin`, cron `*/2`) | Per-site uptime watch, WhatsApp alert |
| `deamon-db/` | coolify VPS (`/opt/deamon-db`) | Shared MySQL for CMS sites + nightly backup |
| `move-site-db.sh` | coolify VPS | Move one site from its own MySQL to the shared one |
| `deamon-redis/` | coolify VPS (`/opt/deamon-redis`) | Shared Redis for CMS sites, watch (cron `*/5`), nightly RDB (03:40) |

## deamon-redis (shared Redis) — plan: `docs/plans/2026-10-25-shared-redis.md`

- Container/service/alias `deamon-redis` (never `redis`), `redis:7.4.11-alpine`, network
  `deamon-db`, 0.5 CPU / 1.5 GB, `unless-stopped`. `maxmemory 1gb`, `volatile-lru`, AOF
  everysec, `databases 1024`.
- Auth only: `default` user off; `plane_admin` in `data/users.acl`; password in `.env` (600).
  Site users (`site_<slug>`, key pattern = prefix, no FLUSHDB/FLUSHALL/KEYS) are created by Plane.
- Installed 2026-10-25. Checks: `nslookup redis` on deamon-db must fail, an unauthenticated
  `redis-cli ping` must answer NOAUTH, a key survives `docker restart deamon-redis`.
- `watch.sh`: WhatsApp when it stops answering (2 checks), memory ≥ 70% of maxmemory, or
  `evicted_keys` grows. `backup.sh`: BGSAVE → `backups/dump-<date>.rdb.gz`, 7 days.

## deamon-watch (off-host uptime)

Every 2 minutes from main.codron, so it still works when the coolify VPS (and Plane on
it) is throttled. A site that fails two runs in a row (≈4 min) gets one WhatsApp message
with the reason (no DNS, timeout, refused, bad certificate, HTTP code); recovery is
reported with the outage length; a site still down is re-announced every 6 hours. Five or
more sites down at once adds the Hostinger CPU-throttle hint.

```bash
scp tools/ops/deamon-watch.sh root@31.97.79.49:/usr/local/bin/ && ssh root@31.97.79.49 chmod 755 /usr/local/bin/deamon-watch.sh
# /root/.deamon-watch.env (600):
#   WATCH_LIST_URL=https://<plane host>/internal/ops/v1/watch-list
#   WATCH_LIST_TOKEN=<same value as Plane's PLANE_WATCH_TOKEN>
# cron: */2 * * * * /usr/local/bin/deamon-watch.sh >/dev/null 2>&1
```

- Site list: Plane's active sites, cached in `/root/deamon-watch/sites.txt`; without the
  env file (or while Plane is down) the cache is used.
- Skip a domain: add it to `/root/deamon-watch/ignore.txt`.
- Log: `/root/deamon-watch/watch.log`; per-site state: `/root/deamon-watch/state/`.
- Delivery goes through `/usr/local/bin/wa-alert.sh` (Evolution on the coolify VPS): if
  that host is completely down the message cannot be sent; the log still records it.

## deamon-db (shared MySQL)

One MySQL 8.0 for all CMS sites instead of one per site stack. Each site keeps its own
schema (`site_<slug>`) and user, with its existing `DB_PASSWORD`. It is deliberately not a
Coolify resource: Coolify recreates all containers of a stack on every deploy.

- Container `deamon-db-mysql`, network `deamon-db` (external), no published port.
- Its only name on `deamon-db` must be `deamon-db-mysql`, never `mysql`: site apps are on
  this network and on their own stack network, where `mysql` is their own database. The
  compose service was first named `mysql`; that alias sent unmoved sites to this server and
  5 sites failed to boot after the 1.2.80 rollout (2026-10-25). Fixed live with
  `docker network disconnect` + `connect --alias deamon-db-mysql`, then the service was
  renamed. The running container was not recreated; the next planned restart of this
  stack needs `docker compose down && docker compose up -d` (the old service name is gone).
  Check: `docker run --rm --network deamon-db busybox nslookup mysql` must fail.
- Limits: 1.5 CPU, 6 GB (2 GB was reached with 34 sites, 4 GB on 2026-10-26), buffer pool 1 GB, `max_connections` 500, `restart: unless-stopped`.
- `table_definition_cache` 10000 (also `SET PERSIST` on the live server). Each site has ~205
  tables; with 35 sites (~7,200 tables) the default 2000 kept evicting definitions and a site
  booting on the shared server failed its theme sync with `1615 Prepared statement needs to be
  re-prepared` (deamon-zorlu, 2026-10-26). Keep it above the total table count
  (`SELECT COUNT(*) FROM information_schema.tables WHERE table_schema LIKE 'site\_%'`).
- Secrets: `/opt/deamon-db/.env` (600) — `MYSQL_ROOT_PASSWORD`, `PLANE_ADMIN_PASSWORD`.
  `plane_admin` is created on first init (`init/01-plane-admin.sh`).
- Backup: `backup.sh`, cron `15 3 * * *`, one gzip per schema in `backups/<date>/`, 7 days,
  `nice`/`ionice`; a failed schema sends a WhatsApp alert. Log: `backup.log`.

First install (done 2026-10-24):

```bash
mkdir -p /opt/deamon-db/{init,data,backups}   # copy docker-compose.yml, backup.sh, init/
umask 077; printf 'MYSQL_ROOT_PASSWORD=%s\nPLANE_ADMIN_PASSWORD=%s\n' "$(openssl rand -hex 24)" "$(openssl rand -hex 24)" > /opt/deamon-db/.env
docker network create deamon-db
cd /opt/deamon-db && docker compose up -d
# register in Plane (password on STDIN, never on the command line):
. /opt/deamon-db/.env; printf '%s' "$PLANE_ADMIN_PASSWORD" | docker exec -i <plane-app> php artisan ops:db-server "Deamon MySQL" deamon-db-mysql --user=plane_admin --default
```

Plane needs the CMS and Plane composes that join `deamon-db` (both repos, 2026-10-24) to
be deployed before it or any site can reach the server.

## Moving a site

Prerequisites: the site runs a CMS build with the `deamon-db` compose (its app container
is on the `deamon-db` network) and Plane is deployed with `ops:site-db`.

```bash
/opt/deamon-db/move-site-db.sh <slug|domain|app-uuid>        # asks before the maintenance window
/opt/deamon-db/move-site-db.sh <slug> --yes                   # no prompt
```

Steps: prepare schema + user → maintenance on → dump → import into the empty schema →
exact row counts compared per table (one query per side) → `ops:site-db switch --no-restart`
(Plane + Coolify env) → the same DB_* written into Coolify's generated
`/data/coolify/applications/<uuid>/.env` → only the `app` container recreated from its
existing image (`docker compose -p <uuid> up -d --no-build --no-deps --force-recreate app`)
→ it must show `DB_HOST=deamon-db-mysql` and answer `/up`. If it does not, Plane and the
`.env` go back to local and the app is recreated on its own MySQL, which still holds the
same data. Log: `/opt/deamon-db/move.log`.

Do not use a Coolify "restart" for this: on compose apps it rebuilds the image (≈9 min with
the site in maintenance, plus one Docker build per site). deamon-test, 2026-10-24: 10 MB,
201 tables, 18 s in maintenance with the recreate; rollback path 8 s.

By hand: `php artisan ops:site-db status|prepare|switch|local <site>` in the Plane container.

After all sites are moved and have run a few days, the `mysql` service can be removed from
the CMS compose (one release) and the old `deamon_mysql` volumes deleted.
