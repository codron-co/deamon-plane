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
