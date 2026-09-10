# Runbook: Provision site

Internal ops only. Do not paste API tokens, `APP_KEY`, or agent secrets into tickets or this file.

## Preconditions

1. Coolify connection is saved in Plane **Coolify** menu (base URL + API token). Tests and CI use `Http::fake` — do not point a laptop at production Coolify to “try” provision.
2. Default **project** UUID is set (Coolify menu or `COOLIFY_DEFAULT_PROJECT_UUID`).
3. Target **server** UUID is on the draft site or in the Coolify menu (`COOLIFY_DEFAULT_SERVER_UUID`).
4. Cloudflare account is saved. Nested hosts under an existing zone (`test.deamon.codron.co` on `codron.co`) only write DNS. Unbound customer domains create a **Free** full zone on the site’s selected account (or the default) and store Cloudflare nameservers. Set those NS at the registrar — Plane cannot change registrar NS. Preferred path: site detail → pick account → **Add to Cloudflare (Free)** (AJAX, no reload) → copy NS → then Provision.
5. Channel is one of `main` | `beta` | `alpha`.

## Create + provision

1. Plane → **Sites → New site**. Fill slug, name, primary domain, Cloudflare account, channel, optional server UUID. Save. Record stays **draft**. No Coolify or zone-create call yet.
2. Open the site → Infrastructure → **Add to Cloudflare (Free)** if the hostname is not already on a covering zone. Copy the nameservers and set them at the registrar. This step is AJAX (`POST /sites/{site}/cloudflare/zone`, `Accept: application/json`) and does not start Coolify.
3. Open the site → **Provision** (operator or super_admin). Viewers cannot provision. Confirm is not required (unlike destroy / channel downgrade).
4. Plane generates `APP_KEY` and a per-site agent secret, stores both **encrypted**, sets status **provisioning**, writes `site.provision_started`.
5. Job creates a Coolify application:
   - Git repository from site / `DEAMON_GIT_REPOSITORY` (customer CMS repo, not this Plane repo)
   - Branch = site channel
   - Coolify environment **name** defaults to `main` (1:1 with the main channel). Leftover connection name `production` / `prod` is rewritten to `main`. Laravel `APP_ENV` stays `production` / `staging` / `local`.
   - Build pack **Docker Compose** (`build_pack=dockercompose`)
   - Compose file `/docker-compose.coolify.yml` (Coolify `docker_compose_location` requires a leading slash)
   - Stack is **app + isolated MySQL + isolated Redis** (no shared DB/Redis)
   - Operator domain is sent on create as `docker_compose_domains` on service **`app`** only (`https://{operator-host}`). **Do not send `fqdn`** (Coolify: `This field is not allowed.`). Coolify may still generate `{uuid}.demo.codron.co` / `{uuid}.random.codron.co`; that must not become Plane’s primary.
6. Env written as shared Coolify variables (compose interpolation, all services): `APP_KEY`, `DEAMON_SITE_NAME`, and if empty `DB_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `DEAMON_DEFAULT_ADMIN_PASSWORD` (CMS compose MySQL will not start without DB passwords; first migrate will not seed `support@codron.co` without the admin password). Coolify injects `SERVICE_URL_APP` / `SERVICE_FQDN_APP`. Do not add mailbox secrets here. Retry must not rotate a DB/admin password that already has a value.
7. Domain is bound again via `setDomains`: compose service **`app`** only (`force_domain_override` stays false). Do not send `fqdn`. Do not clear generate FQDNs on already-live apps without operator OK. Retry provision if the Coolify app uuid already exists — do **not** recreate.
8. Deploy is triggered. A `deployments` row (`trigger=create`) is stored. Plane prefers a Coolify webhook (`POST /webhooks/coolify` — HMAC or query `token`) and falls back to polling every 15s until `finished` or `failed`.
9. Success: `coolify_app_uuid` set, status **active**, audit `site.provision_succeeded`. Provision generates an agent secret (encrypted). Use **Check health** on the site to poll CMS `/internal/control/v1/health` — it does not gate this step.
10. Failure: status **error**, audit `site.provision_failed`. Retry **Provision** on the same row (reuses the Coolify app uuid when one already exists; does not delete volumes).

## After provision

1. Point DNS A/CNAME at Coolify.
2. Wait for TLS / Traefik. Confirm `/up` in the browser when the instance is up (manual).
3. Default CMS admin is the Deamon migration user; customer must change the password.
4. Theme assign is Faz B. ZIP upload is not available in Plane.
5. Channel switch: [channel-switch.md](channel-switch.md) — PATCH `git_branch` + deploy on the same app. Do not delete/recreate the Coolify app (volumes are app-uuid scoped).

## Failure notes

| Symptom | What to do |
|---------|------------|
| Flash: Coolify is not configured | Coolify menu → token + base URL |
| Flash: project/server UUIDs required | Set server on the site or defaults in Coolify menu |
| Status `error`, no `coolify_app_uuid` | Create failed; fix Coolify/git access, retry Provision |
| Status `error`, uuid present | Env/domain/deploy/poll failed; retry reuses the app; do **not** DELETE the Coolify app (`delete_volumes` defaults true) |
| Domain 409 | Hostname already bound; resolve in Coolify. Super Admin force-override is not in this task |
| Private git create fails | Coolify menu needs GitHub App uuid or deploy key (not `POST /applications/dockercompose`) |

Secrets must never appear in Plane logs, audit `before`/`after`, or flash messages. Rotation is a later hardening runbook.
