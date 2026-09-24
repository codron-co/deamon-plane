# Deployment — Deamon Plane on Coolify

Plane is a **separate Coolify application** from customer Deamon CMS sites (ADR-1).  
Same install shape: **Docker Compose build pack** + repo compose file. Not Nixpacks. Not Dockerfile-only.

## Two Coolify apps (do not mix)

| App | Git repo | Compose file | Stack |
|-----|----------|--------------|--------|
| **Plane** (this product) | `codron-co/deamon-plane` | `docker-compose.coolify.yml` | `app` + **own** MySQL + **own** Redis (`plane_*` volumes) |
| **Customer site** | `codron-co/deamon` | `docker-compose.coolify.yml` | `app` + **own** MySQL + **own** Redis (`deamon_*` volumes) |

Plane never shares MySQL/Redis with a customer site. Customer sites never share with each other.

## Coolify UI — create Plane

1. **New resource** → Git (`codron-co/deamon-plane`).
2. **Build pack:** Docker Compose.
3. **Compose file:** `docker-compose.coolify.yml` (do not point at `docker-compose.yml`).
4. **Branch:** `main` (or `alpha`/`beta` for staging Plane).
5. **Port:** app service **8080** (compose `expose`; Coolify proxy).
6. **Health check:** HTTP `/up` on 8080.
7. **Domain** on compose service **`app`** (same as CMS: `docker_compose_domains` name `app`).
8. **Environment** — copy `.env.production.example`:
   - Required: `APP_KEY` (`php artisan key:generate --show` once; store in Coolify).
   - URL: Coolify `SERVICE_URL_APP` / `SERVICE_FQDN_APP` (Dalga 1 maps `APP_URL` ← `SERVICE_URL_APP`). Do not duplicate `APP_URL` unless overriding.
   - Timezone: compose sets `APP_TIMEZONE=Europe/Istanbul`, `DB_TIMEZONE=+03:00`, `TZ=Europe/Istanbul` on app/MySQL/Redis. MySQL `--default-time-zone=+03:00`. TIMESTAMP instants stay UTC internally and display +3; do not bulk-add 3 hours to TIMESTAMP columns.
   - Do **not** set `DB_*` / `REDIS_*` / `APP_DEBUG` in Coolify — compose `environment:` owns them.
   - After bootstrap: `COOLIFY_BASE_URL` (and optional `COOLIFY_API_TOKEN` fallback). Ops **Coolify** menu stores the fleet token and webhook signing secret encrypted on `coolify_connections` (legacy `coolify_settings` fallback; HMAC or query `token`). `COOLIFY_WEBHOOK_SECRET` is the env fallback. See [coolify-webhooks.md](coolify-webhooks.md). GitHub secrets stay in Settings. CMS agent secret is **per customer site** (`CONTROL_PLANE_AGENT_SECRET`) — not a Plane env. See [agent-client.md](agent-client.md).
9. **Persistent volumes** come from compose (`plane_storage`, `plane_mysql`, `plane_redis`). Do not bind-mount `/root`. Channel/redeploy must not delete the application (Coolify `DELETE` defaults `delete_volumes=true`).
10. **Shared-host resource caps:** `docker-compose.coolify.yml` (and local `docker-compose.yml`) set `mem_limit` / `cpus` / `pids_limit` on `app` (768m / 1.5 / 256), `mysql` (768m / 1.0 / 256), and `redis` (128m / 0.25 / 64). MySQL also caps InnoDB (`innodb-buffer-pool-size=256M` and the same family flags as CMS coolify compose) while keeping `--default-time-zone=+03:00`. Coolify UI limits do not apply to compose child services — rely on the compose file. After changing limits, Redeploy (or `docker update`) so live containers pick them up.

## First deploy gate

`Dockerfile` `COPY composer.json` — Laravel 12 ops scaffold is in this repo (Task 0). Coolify **Deploy** can succeed on a branch that includes `composer.json` / `composer.lock`.

Local check:

```bash
docker compose -f docker-compose.coolify.yml --env-file .env up --build -d
# or local ports file:
docker compose --env-file .env up --build -d
curl -fsS http://localhost:8088/up
```

Sign-in: `/login`. Seed a local super_admin with `php artisan db:seed` using `OPS_SEED_*` from `.env.example` (never a production password in git).

## Provisioning customer sites (Plane jobs — Dalga 2)

API create must use **git + `build_pack=dockercompose`** + `docker_compose_location=/docker-compose.coolify.yml` (leading slash required) against **`codron-co/deamon`**, not this repo. Deprecated `POST /applications/dockercompose` (raw YAML, no git) is forbidden.

Customer Coolify env comes **only** from the CMS repo's `.env.production.example` on the site's git branch (Settings → Coolify env defaults is a read-only mirror, refreshed by the CMS push webhook / hourly / on demand). Today that is `APP_KEY`, `DEAMON_SITE_NAME`, `DEAMON_CHANNEL`, `APP_ENV`, `CONTROL_PLANE_AGENT_SECRET`, `CONTROL_PLANE_HOST_ALLOWLIST`, `DB_PASSWORD`, `MYSQL_ROOT_PASSWORD` (+ Coolify `SERVICE_*`, listed as skip). Target per [ADR-12](../decisions/adr-12-site-config-from-plane.md): 6 bootstrap keys (`APP_KEY`, `DB_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `CONTROL_PLANE_AGENT_SECRET`, `CONTROL_PLANE_HOST_ALLOWLIST`, `DEAMON_CHANNEL`); `APP_ENV` (always `production`) and `DEAMON_SITE_NAME` (pushed via `/site/identity`) leave the catalog. Compose interpolates the DB passwords into MySQL and the app; empty DB values make MySQL exit (`password option is not specified`). No `DEAMON_DEFAULT_ADMIN_PASSWORD` (CMS 1.2.18+ seeds the first admin passwordless, invite over the agent — [site-admins.md](site-admins.md)) and no `DEAMON_PLATFORM_MAIL_*` (platform SMTP is pushed with `/platform-mail/configure`). Pack-migrate still must **not** copy `DB_*` from a Dockerfile app. **Do not add customer env keys.** A new per-site setting ships as a signed agent endpoint + CMS default + health field ([ADR-12](../decisions/adr-12-site-config-from-plane.md)); only a value needed before the DB may become a catalog line, edited in the CMS file (never in Plane). Env prune never deletes the 6 bootstrap keys. See CMS `docs/modules/deployment.md`.

## CI-gated deploy

Pushes to `main` / `beta` / `alpha` run the GitHub Actions workflow `CI` (lint, sqlite + MySQL tests, node, image build + compose boot). Its `deploy` job calls Coolify `POST /api/v1/deploy?uuid=<plane app>` only when every other job is green. Target: **Coolify auto-deploy off** for the Plane app, so a red commit never ships. Setup (GitHub Environments + secrets, the Coolify switch, branch protection) and the red-CI procedure: [ci-cd-plane.md](../runbooks/ci-cd-plane.md). Local gate: `composer check`.

## Access

v1 internal ops: restrict Plane domain (VPN / Coolify IP allowlist / optional SSO) **and** optional `OPS_IP_ALLOWLIST` (comma-separated) in Plane. Checklist: [deploy-plane.md](../runbooks/deploy-plane.md). Customer CMS admins do not use Plane.

## Out of scope

Mailcow, Nixpacks, shared DB, embedding Plane inside a Deamon CMS container.
