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

Customer Coolify env: `APP_KEY`, `DEAMON_SITE_NAME`, `DB_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `DEAMON_DEFAULT_ADMIN_PASSWORD` (+ Coolify `SERVICE_*`). Compose interpolates the DB passwords into MySQL and the app. Empty DB values make MySQL exit (`password option is not specified`). Empty admin password fails first migrate (`DefaultAdminSeeder`). Pack-migrate still must **not** copy `DB_*` from a Dockerfile app. See CMS `docs/modules/deployment.md`.

## Access

v1 internal ops: restrict Plane domain (VPN / Coolify IP allowlist / optional SSO) **and** optional `OPS_IP_ALLOWLIST` (comma-separated) in Plane. Checklist: [deploy-plane.md](../runbooks/deploy-plane.md). Customer CMS admins do not use Plane.

## Out of scope

Mailcow, Nixpacks, shared DB, embedding Plane inside a Deamon CMS container.
