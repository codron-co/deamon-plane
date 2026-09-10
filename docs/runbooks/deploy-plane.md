# Runbook: Deploy Plane (Coolify Compose)

SoT: [docs/modules/deployment.md](../modules/deployment.md) · compose contract: repo-root `docker-compose.coolify.yml` + `Dockerfile` + `docker/` (do not replace with Nixpacks).

Plane is **internal ops**. Production host: **`https://plane.codron.co`** (compose service `app`, health `/up`). Restrict the domain before importing the customer fleet.

## Coolify UI checklist (copy-paste)

1. Coolify → **New Resource** → Git `codron-co/deamon-plane` (not `codron-co/deamon`).
2. **Build pack:** Docker Compose. **Compose file:** `docker-compose.coolify.yml` (not `docker-compose.yml`).
3. Branch: `main` (or `alpha`/`beta` for a staging Plane).
4. Domain on compose service **`app`**: **`https://plane.codron.co`**, proxy port **8080**, health **`/up`**.
5. Environment — copy [`.env.production.example`](../../.env.production.example):
   - **Required:** `APP_KEY` (`php artisan key:generate --show` once; store only in Coolify).
   - URL: Coolify `SERVICE_URL_APP` / `SERVICE_FQDN_APP`. Do not duplicate `APP_URL` unless overriding.
   - Do **not** set `DB_*`, `REDIS_*`, or `APP_DEBUG=true`. Compose forces `APP_ENV=production`, `APP_DEBUG=false`, own MySQL+Redis (`plane_*` volumes).
   - After first login: `COOLIFY_BASE_URL` (token is better in the Coolify menu, encrypted). Optional `COOLIFY_WEBHOOK_SECRET`, `GITHUB_*`, `OPS_IP_ALLOWLIST`.
6. Persistent volumes come from compose (`plane_storage`, `plane_mysql`, `plane_redis`). Do not bind-mount `/root`. Do not `DELETE` the app (`delete_volumes` defaults true).
7. Queue worker + scheduler already run in the image (`supervisord`: php-fpm, nginx, `queue:work`, `schedule:work`).
8. First boot: entrypoint `artisan migrate`. Create the first `super_admin` **out of band** (do not set `OPS_SEED_PASSWORD` in production).
9. Restrict access: Coolify IP allowlist / VPN / SSO in front of the Plane domain, **and** optional `OPS_IP_ALLOWLIST` (comma-separated) in Plane. See [security.md](../security.md).
10. Login → Coolify → Test connection → Themes → Connect GitHub (Manifest) → Sync catalog. Settings no longer hosts GitHub paste.
11. Import fleet: `php artisan ops:import-coolify-apps` dry-run, then `--apply` — [import-coolify-apps.md](import-coolify-apps.md).
12. Inject per-site agent secrets — [agent-secret-inject.md](agent-secret-inject.md).

## Existing Coolify app (spike)

Coolify app uuid `d6ovbjzxgpao23faam3vrcve` is **Deamon Plane** (`codron-co/deamon-plane`). Domain on service `app`: **`https://plane.codron.co`**. Confirm name + repo before any PATCH; do not treat this as a customer site.

**Never mutate Susa** `crxguq6nodorlzy88wf9x305` or other customer apps from this runbook.

Prefer completing this checklist in the Coolify UI over an API deploy from a laptop.

## Staging smoke (after Plane is up)

- [ ] `/up` 200 on `https://plane.codron.co/up`
- [ ] Ops login
- [ ] Coolify test connection
- [ ] Import dry-run count looks right
- [ ] One staging CMS site: provision **or** imported + Check health (secret injected)
- [ ] Channel switch volumes persist
- [ ] Theme assign (opt-in) if CMS Task 11 is live
- [ ] Audit rows visible; no secrets in the row

Record the result in [progress-ledger.md](../plans/progress-ledger.md).

## Access

v1: few users, 2FA at the IdP if you have one, IP/VPN in front of `https://plane.codron.co`. Customer CMS admins do not use Plane.

## Out of scope

Mailcow, Nixpacks, shared DB with customers, embedding Plane inside a Deamon CMS container, live mutate of customer Coolify apps.
