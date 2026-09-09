# Runbook: Provision site

Internal ops only. Do not paste API tokens, `APP_KEY`, or agent secrets into tickets or this file.

## Preconditions

1. Coolify connection is saved in Plane **Settings** (base URL + API token). Tests and CI use `Http::fake` — do not point a laptop at production Coolify to “try” provision.
2. Default **project** UUID is set (Settings or `COOLIFY_DEFAULT_PROJECT_UUID`).
3. Target **server** UUID is on the draft site or in Settings (`COOLIFY_DEFAULT_SERVER_UUID`).
4. DNS for the customer hostname is ready to aim at Coolify / Traefik (can be done right after provision).
5. Channel is one of `main` | `beta` | `alpha`.

## Create + provision

1. Plane → **Sites → New site**. Fill slug, name, primary domain, channel, optional server UUID. Save. Record stays **draft**. No Coolify call yet.
2. Open the site → **Provision** (operator or super_admin). Viewers cannot provision. Confirm is not required (unlike destroy / channel downgrade).
3. Plane generates `APP_KEY` and a per-site agent secret, stores both **encrypted**, sets status **provisioning**, writes `site.provision_started`.
4. Job creates a Coolify application:
   - Git repository from site / `DEAMON_GIT_REPOSITORY` (customer CMS repo, not this Plane repo)
   - Branch = site channel
   - Build pack **Docker Compose** (`build_pack=dockercompose`)
   - Compose file `docker-compose.coolify.yml`
   - Stack is **app + isolated MySQL + isolated Redis** (no shared DB/Redis)
5. Env written to the **app** service only: `APP_KEY`, `DEAMON_SITE_NAME`. Coolify injects `SERVICE_URL_APP` / `SERVICE_FQDN_APP`. Do not add mailbox or extra secrets here.
6. Domain is bound on compose service **`app`** via `setDomains` (`force_domain_override` stays false).
7. Deploy is triggered. A `deployments` row (`trigger=create`) is stored. Plane prefers a signed Coolify webhook (`POST /webhooks/coolify`) and falls back to polling every 15s until `finished` or `failed`.
8. Success: `coolify_app_uuid` set, status **active**, audit `site.provision_succeeded`. Provision generates an agent secret (encrypted). Use **Check health** on the site to poll CMS `/internal/control/v1/health` — it does not gate this step.
9. Failure: status **error**, audit `site.provision_failed`. Retry **Provision** on the same row (reuses the Coolify app uuid when one already exists; does not delete volumes).

## After provision

1. Point DNS A/CNAME at Coolify.
2. Wait for TLS / Traefik. Confirm `/up` in the browser when the instance is up (manual).
3. Default CMS admin is the Deamon migration user; customer must change the password.
4. Theme assign is Faz B. ZIP upload is not available in Plane.
5. Channel switch: [channel-switch.md](channel-switch.md) — PATCH `git_branch` + deploy on the same app. Do not delete/recreate the Coolify app (volumes are app-uuid scoped).

## Failure notes

| Symptom | What to do |
|---------|------------|
| Flash: Coolify is not configured | Settings → token + base URL |
| Flash: project/server UUIDs required | Set server on the site or defaults in Settings |
| Status `error`, no `coolify_app_uuid` | Create failed; fix Coolify/git access, retry Provision |
| Status `error`, uuid present | Env/domain/deploy/poll failed; retry reuses the app; do **not** DELETE the Coolify app (`delete_volumes` defaults true) |
| Domain 409 | Hostname already bound; resolve in Coolify. Super Admin force-override is not in this task |
| Private git create fails | Settings needs Coolify GitHub App uuid or deploy key (not `POST /applications/dockercompose`) |

Secrets must never appear in Plane logs, audit `before`/`after`, or flash messages. Rotation is a later hardening runbook.
