# Plane database backup and restore

Internal ops only. Do not paste `APP_KEY`, database passwords or dump contents into tickets or this file.

Plane's own MySQL holds the fleet registry and every secret Plane uses: Coolify, Cloudflare, GitHub and Hostinger credentials and each site's agent secret, all encrypted with Plane's `APP_KEY`. Losing the database without a copy means re-entering every connection and re-injecting every site's agent secret by hand.

## What runs

- `php artisan ops:backup-db` runs every night at 03:30 (Plane timezone) from the scheduler (`routes/console.php`, name `ops-backup-db`).
- It runs `mysqldump --single-transaction` against the default connection. The password goes through `MYSQL_PWD`, never on the command line. The dump is gzipped to `storage/app/backups/plane-db-YYYYmmdd-HHiiss.sql.gz`.
- `storage/app/backups` is its own Docker volume (`plane_backups` in `docker-compose.coolify.yml`), so a new app container keeps the dumps.
- Dumps older than 14 days are deleted; the newest three are always kept.
- Success logs `ops.backup_db_created`, failure logs `ops.backup_db_failed` (stderr, visible in Coolify logs). A failed run exits non-zero.

The volume lives on the same host as the database. It protects against a broken container, a bad migration or a dropped table, **not** against losing the host. Copying dumps off the host is a separate decision.

## Check that backups exist

From the Plane app container (Coolify → Plane → Terminal, or `docker exec`):

```bash
ls -lh storage/app/backups/
php artisan ops:backup-db   # take one now, e.g. before a risky deploy
```

## APP_KEY: the dump alone is not enough

Encrypted columns can only be read with the `APP_KEY` that wrote them. Keep a second copy of Plane's `APP_KEY` outside Coolify (password manager). A dump restored under a different key loads, but every token and agent secret in it fails to decrypt.

Rotating `APP_KEY`: set the new key as `APP_KEY` and the old one in `APP_PREVIOUS_KEYS` (comma separated), redeploy, confirm Coolify/Cloudflare/GitHub connection tests pass, then re-save the secrets or keep the previous key until they are re-encrypted. See [token-rotation.md](token-rotation.md).

## Restore

1. Stop writes: in Coolify, stop the Plane app service (leave `mysql` running).
2. Pick the dump in the `plane_backups` volume. Copy it out first if you are unsure, so the restore cannot remove it.
3. Load it into the `mysql` service (credentials are the ones in the compose file / Coolify env; do not type them into shell history on a shared host):

   ```bash
   gunzip -c plane-db-YYYYmmdd-HHiiss.sql.gz | docker exec -i <plane-mysql-container> sh -c 'exec mysql -uplane -p"$MYSQL_PASSWORD" plane'
   ```

4. Start the Plane app. The entrypoint runs pending migrations, so a dump from an older release is brought forward.
5. Check: log in, open Fleet, run **Test connection** on each Coolify and Cloudflare account, open one site's detail page (agent health must be green).
6. Anything done in Plane after the dump time is gone. Deployments and site state catch up from Coolify and agent health on their own; operator changes (new sites, settings, bindings) must be redone; the audit log is restored to the dump time as well.

## Restore test

Once per quarter, restore the newest dump into a scratch MySQL (local Docker is fine) and run step 5 against it with the production `APP_KEY` in a local `.env`. Delete the scratch database afterwards.
