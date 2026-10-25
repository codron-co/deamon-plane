# Database servers (shared MySQL)

Plane manages a MySQL server shared by CMS sites, instead of one MySQL container per site
stack. Each site keeps its own schema and user; nothing about the CMS changes except where
`DB_HOST` points. Server setup, backups and the move script: [`tools/ops/README.md`](../../tools/ops/README.md).

## Model

- `database_servers`: name, host, port, `admin_username`, encrypted `admin_password`
  (hidden from arrays/JSON), `is_default`, notes, `last_probe_payload` (version, uptime,
  connections, per-schema size and table count — no secrets).
- `sites.database_server_id` (nullable FK, `restrictOnDelete`), `db_database`, `db_username`.
  Null = the `mysql` service in the site's own compose stack.
- Names come from the slug (`SiteDatabasePlacement::namesFor`): `site_<slug>`, user cut to
  MySQL's 32 characters with a short hash when needed.
- The password is the site's existing `DB_PASSWORD`. Moving a site never generates or
  rotates a secret; `SiteDatabaseMover` refuses to prepare or switch when Plane's copy
  differs from Coolify's live value.

## Env

The CMS compose reads `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` from env with
defaults `mysql` / `3306` / `deamon` / `deamon` (its own MySQL). These are not catalog rows.
For a site on a shared server, `CoolifyAppEnvSync::sync()` writes all four on every sync, and
they are in `BOOTSTRAP_KEYS`, so prune never removes them; a deleted or edited value cannot
send a moved site back to its stale local database.

## Moving a site

`php artisan ops:site-db <action> <site>` (slug, id, primary domain or Coolify app uuid):

| Action | Effect |
|---|---|
| `status` | Placement and the DB_* env it implies, plus the target names (`KEY=value` lines) |
| `prepare` | Create/align schema + user on the server (default, or `--server=`); site keeps running |
| `switch` | Record the placement, write DB_* to Coolify, restart (`--no-restart` to skip; the move script always skips it, because a Coolify restart rebuilds compose images) |
| `local` | Back to the site's own MySQL (env only; its volume was never touched) |

`tools/ops/move-site-db.sh` wraps these with maintenance mode, dump/import, row-count
verification and automatic rollback. Every prepare/switch is an audit row on the site.

## Ops UI

- `/databases` — servers, site count, total size, last read. Add/edit/delete: Super Admin
  only (the admin login can read every site schema). Refresh: operators.
- `/databases/{server}` — facts and every schema with its site (or "not linked").
  A server holding site schemas cannot be deleted.
- Site detail → Infrastructure → **Database** card: local or shared, link to the site page.
- `/sites/{site}/database` (operators) — tables (estimated rows, size) and an SQL console:
  - one statement, run as the **site's own user** (no other schema is reachable);
  - reads in `START TRANSACTION READ ONLY` with `MAX_EXECUTION_TIME` 15 s, first 500 rows;
  - writes need Super Admin, the "allow writes" box and the schema name typed back;
  - multi-statements are off at the PDO level; `SqlStatementGuard` only picks the
    permission, the READ ONLY transaction is what stops a disguised write;
  - every statement (normalized, 2 000 chars) is an audit row: `site.database_query` /
    `site.database_write`, with row or affected count, or the error.
- Error messages from MySQL pass through `SecretRedactor` with the passwords in play.

## Watch list

`GET /internal/ops/v1/watch-list` returns active sites' primary domains, one per line, for
the off-host uptime watch (`tools/ops/deamon-watch.sh`). Bearer token `PLANE_WATCH_TOKEN`
(`ops.watch.token`); empty token = 404. Throttled 30/min, outside the IP-restricted web group.

## Redis (shared Redis)

Same idea for Redis: one `deamon-redis` server instead of a `redis:7-alpine` container per
site stack (plan: `docs/plans/2026-10-25-shared-redis.md`). The host name is always
`deamon-redis`, never `redis`: inside a site stack `redis` is its own service.

### Model

- `redis_servers`: name, host, port (6379), `admin_username` (`plane_admin`), encrypted
  `admin_password` (hidden), `is_default`, notes, `last_probe_payload` (version, uptime,
  clients, `used_memory`, `maxmemory`, `evicted_keys`, keys/expires per DB — no secrets).
- `sites.redis_server_id` (nullable FK, `restrictOnDelete`), `redis_db` (unique per server),
  `redis_username`, encrypted `redis_password_encrypted` (hidden), `redis_shared` (bool).
- `Site::usesSharedRedis()` is true only when `redis_shared` and server, DB and user are
  set. Moving back to local clears `redis_shared` only: the DB pair and user stay reserved
  on the server, so a second move reuses them and the pair is never given to another site.
- Per site, three independent fences (one misconfigured layer is covered by the others):
  - key prefix `site_<slug>:` (`SiteRedisPlacement::namesFor`, slug → `[a-z0-9_]`, user
    name at most 64 characters with a short hash when cut);
  - ACL user `site_<slug>` with a random 40-character password (generated by Plane on the
    first `prepare`, never rotated by a move);
  - DB pair `REDIS_DB=N`, `REDIS_CACHE_DB=N+1`, N even, 2 ≤ N ≤ 1022, lowest free per server
    (the server runs `databases 1024`; 0/1 are left alone): 511 sites per server.
    Soft-deleted sites keep their pair.

### Env

| Key | Local (today's CMS behaviour) | Shared |
|---|---|---|
| `REDIS_HOST` | `redis` | server host (`deamon-redis`) |
| `REDIS_PORT` | `6379` | server port |
| `REDIS_USERNAME` | empty | `site_<slug>` |
| `REDIS_PASSWORD` | empty | site password |
| `REDIS_DB` | `0` | N |
| `REDIS_CACHE_DB` | `1` | N+1 |
| `REDIS_PREFIX` | `deamon-database-` | `site_<slug>:` |

All seven are in `CoolifyAppEnvSync::BOOTSTRAP_KEYS` (never pruned, not hand-editable). For a
shared site `sync()` writes the ones that differ on every sync; a blank Plane password is never
written (it would lock the site out) and is logged as `coolify.env_redis_password_missing`.
Local sites are not touched by sync. Switching back to local writes the five non-empty
defaults and deletes `REDIS_USERNAME` / `REDIS_PASSWORD` (empty = the compose default).

### ACL rule

`RedisServerClient::aclSetUserArgs()` builds, and `ensureSiteUser()` runs as admin, then `ACL SAVE`:

```
ACL SETUSER site_<slug> on resetpass >PASSWORD resetkeys ~site_<slug>:* resetchannels &site_<slug>:* nocommands +@all -@dangerous -flushdb -flushall -keys
```

Every `reset*` comes before its grant, so re-running it replaces any older rule. `-@dangerous`
also removes `CONFIG`, `DEBUG`, `MONITOR`, `INFO`, `RESTORE`, `MIGRATE`, `SORT`, etc.

**Why FLUSHDB is denied.** Redis ACLs restrict keys, not DB numbers: a site user could
`SELECT` another DB, and `FLUSHDB`/`FLUSHALL` ignore key patterns, so one site's
`cache:clear` would wipe every site. On a shared server `php artisan cache:clear` therefore
fails for the site user. Its replacement is `ops:site-redis flush <site>`: as admin,
`SCAN MATCH site_<slug>:* COUNT 500` in DB N and N+1 and `UNLINK` in batches — only that
site's keys go.

### Commands

- `ops:redis-server {name} {host} {--port=6379} {--user=plane_admin} {--default}`: register
  or update a server; admin password on STDIN; probes after save.
- `ops:site-redis {status|prepare|switch|local|flush} {site} {--server=} {--no-restart} {--show-secret} {--keys}`
  (`KEY=value` lines, like `ops:site-db`):

| Action | Effect |
|---|---|
| `status` | `SITE`, `APP_UUID`, `SHARED`, the 7 env values the site should have, then `TARGET_SERVER/HOST/PORT/DB/CACHE_DB/USERNAME/PASSWORD`, `PREFIX`. Passwords print as `***` unless `--show-secret` (for the move script). `--keys` adds `KEYS_DB` / `KEYS_CACHE_DB` (SCAN count on the server) |
| `prepare` | Reserve the DB pair, store the user name, create/align the ACL user; the site keeps running on its own Redis (`site.redis_prepared` audit row) |
| `switch` | Requires `prepare`; mark shared, write REDIS_* to Coolify, restart (`--no-restart` to skip) (`site.redis_switched`, password masked) |
| `local` | Back to the stack's own Redis (env only; pair and user stay reserved) |
| `flush` | Delete the site's keys on the shared server (`DELETED=n`, `site.redis_flushed`) |

Redis errors pass through `RedisServerException` (`SecretRedactor` with the admin and site
passwords in play). Connections: phpredis, 5 s connect timeout, 10 s read timeout.

### Ops UI

- `/databases` → **Redis** section: servers with used/max memory, clients, `evicted_keys`,
  shared site count, last read; **Refresh** for operators (`ops.write`). Servers are added
  with `ops:redis-server` only.
- Site detail → **Database** card: a Redis line, local or shared with the DB pair.
