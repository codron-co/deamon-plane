# Coolify HTTP adapter

Task 2 client for Deamon Plane. Spike lock: [Coolify spike notes](../plans/2026-08-13-coolify-spike-notes.md).

Ops inventory (N connections, allowlists, site dropdowns): this page + [ops-sites.md](ops-sites.md).

## Classes

| Class | Role |
|-------|------|
| `App\Services\Coolify\CoolifyClient` | Bearer HTTP to `{COOLIFY_BASE_URL}/api/v1` |
| `App\Services\Coolify\CoolifyApplicationService` | Thin wrappers + channel allowlist on create/updateBranch. `forConnection()` builds a per-connection client. |
| `App\Services\Coolify\CoolifyInventorySync` | `listServers` / `listProjects` / `listEnvironments` / `listGithubApps` / `listPrivateKeys` → allowlist tables, then fills existing sites |
| `App\Services\Coolify\CoolifySiteTargetSync` | After inventory: `GET /applications/{uuid}` per site with `coolify_app_uuid` on this connection (or null connection if this one is default) |
| `App\Services\Coolify\CoolifyProvisionPreflight` | Before `POST` create: server must appear in live `listServers`; Git source in live GitHub App or deploy-key list |
| `App\Services\Coolify\CoolifyApiException` | Status, `conflicts[]`, validation `errors` in the message, token redaction |
| `App\Services\Coolify\CoolifyUnsupportedOperationException` | Hybrid gap + `ManualChecklist` DTO |
| `App\Models\CoolifyConnection` | N rows; `api_token` / `webhook_secret` encrypted + hidden; one `is_default` |
| `App\Models\CoolifySetting` | Legacy singleton; webhook fallback + `resolvedWebhookSecrets()` (all connections + settings + env) |

## Pack migrate / auto-deploy / pin

Existing Coolify app is **PATCH** only. Plane never DELETE / `delete_volumes`.

| Action | HTTP | Notes |
|--------|------|--------|
| Dockerfile → compose | `PATCH /applications/{uuid}` `{ build_pack: dockercompose, docker_compose_location: /docker-compose.coolify.yml }` | Snapshot `APP_KEY` / `APP_URL` / `DEAMON_*` via `listEnvs` (never log values). Restore those keys with `POST`/`PATCH /applications/{uuid}/envs` `{ key, value, is_literal: true }` (Coolify 4.3 rejects `is_literally`, `available_in_services`, env `uuid`). Application envs apply to compose service **`app`**. Do **not** copy `DB_*` from a Dockerfile app. Provision itself **fills** empty `DB_PASSWORD` / `MYSQL_ROOT_PASSWORD` / `DEAMON_DEFAULT_ADMIN_PASSWORD` for compose MySQL and first admin seed. If Coolify says recreate is required, **abort**. Env restore 422 becomes a flash, not 500. |
| Auto-deploy | `PATCH` `{ is_auto_deploy_enabled }` | Coolify rejects `is_auto_deploy`. Single + selected + all-Dockerfile bulk. Confirm on on/off (including bulk). GET reads `settings.is_auto_deploy_enabled`, then `settings.is_auto_deploy`, then top-level aliases. Missing flag is **unknown**, not off. |
| Pin | `PATCH` `{ git_commit_sha, is_auto_deploy_enabled: false }` then `POST /deploy` | SHA or release tag. |
| Follow HEAD | `PATCH` `{ git_commit_sha: "", is_auto_deploy_enabled: true }` then `POST /deploy` | Clears pin. Does not require typing `HEAD`. |

Channel switch stays `ChannelSwitcher` (`PATCH git_branch` + deploy). `main`→beta/alpha confirm. Super Admin force unchanged.

Do not call Coolify from tests with a live token. Use `Http::fake`. Never log `api_token` or env `value`.

Provision consumes `CoolifyApplicationService` + preflight. Flow: [provision-site.md](../runbooks/provision-site.md).

Fleet import (Task 7) calls `listApps` only (`ops:import-coolify-apps`). It does not create apps, patch env, or deploy. Runbook: [import-coolify-apps.md](../runbooks/import-coolify-apps.md).

## Connections (ops menu)

Left nav **Coolify**. Super Admin / operator write; viewer read.

| Table | Role |
|-------|------|
| `coolify_connections` | name, base_url, encrypted token + webhook secret, `is_enabled`, `is_default`, selected defaults |
| `coolify_servers` | uuid, name, **ip** (Coolify `public_ip`/`public` if set, else `ip`), `is_active` — inactive cannot be chosen on site create. Sync fills IP; connection server table shows it. |
| `coolify_projects` | uuid, name, `is_active` |
| `coolify_environments` | **project_uuid** + uuid/name, `is_active`. Sync writes `project_uuid`. Default + site-create env `<select>` lists **only** that project’s rows (never a flat dump). Changing the project rebuilds the env list (`ops-coolify-form.js`). Option text is the Coolify **name**; uuid is `value` + `title`. |
| `coolify_git_sources` | `github_app` \| `deploy_key`, uuid, name, `is_active` |

Existing `coolify_settings` row is copied into the first connection on migrate (encrypted columns copied as-is). Settings does not host Coolify credentials.

**Default connection:** `is_default` — new sites pre-select it. After sync (and on the connection show page) a **single** active server / project / environment / git source is persisted as the matching default.

**Disconnect:** confirm modal. Deletes the Plane connection + allowlists. Does **not** DELETE Coolify apps. No SSH.

## Inventory API

| Method | HTTP | Notes |
|--------|------|--------|
| `listServers()` | `GET /servers` | Test connection + allowlist |
| `listProjects()` | `GET /projects` | Allowlist |
| `listEnvironments($projectUuid)` | `GET /projects/{uuid}/environments` | 404 → nested `environments` on `GET /projects/{uuid}` |
| `listGithubApps()` | `GET /github-apps` | `null` on 404/405 (hybrid). **Not** Plane’s theme-catalog GitHub App |
| `listPrivateKeys()` | `GET /security/keys` | Deploy keys |

If `GET /github-apps` is missing, UI shows a hybrid note: pick a deploy key from `/security/keys`, or Super Admin pastes a Coolify GitHub App UUID in the collapsed advanced field. Link: [Coolify GitHub Apps API](https://github.com/coollabsio/coolify/blob/v4.x/routes/api.php) (`GET /github-apps` exists on current v4.x; older 4.3 instances may 404).

**Open in Coolify:** `{base}/project/{project_uuid}/environment/{environment_uuid}/application/{app_uuid}`. Use the site’s `coolify_project_uuid` / `coolify_environment_uuid` (fallback: connection defaults). Never the environment **name** or git channel (`alpha` / `production`) — Coolify 404s those.

**Site fill (same POST Sync):** for each matching site, `GET /applications/{coolify_app_uuid}` and write project / environment / server / git source (GitHub App or deploy key) / `git_repository`. Then `GET /deployments/applications/{uuid}` upserts the last 25 Coolify deployments onto the site (match `coolify_deployment_uuid`; new rows `trigger=manual`). Historical failed rows do **not** flip `sites.status`. Allowlisted branch (`main` \| `beta` \| `alpha`) sets `channel` and clears `channel_needs_review`. Other branches (`develop`) set `channel_needs_review` and **do not** overwrite `channel`. Skip channel while status is `provisioning` or `deploying`. **Never** write `status`, `app_key_encrypted`, or `agent_secret_encrypted`. App 404 skips that site (inventory rows gone from Coolify may still be deleted; **sites are not auto-deleted**). Sync still does not write inventory `is_active` (does not zero it). Other connections’ sites are left alone. Flash includes the sites-filled count.

**Per-site Sync:** `POST /sites/{site}/sync` (`ops.sites.sync`) does the same fill + deployment pull for one site. GET `/sites/{site}/sync` is a 302 to show (does not sync). Operator / Super Admin. Viewer forbidden.

**Sites list Sync:** `POST /sites/bulk/sync` (`ops.sites.bulk.sync`) runs that per-site fill for selected ids or `all=1` (sites without an app UUID are skipped). GET is a 302 to the list. Live homepage probe (HTTP status + favicon href) is a separate `POST /sites/bulk/live-sync` — not a Coolify call.

## Create path

Git + `build_pack=dockercompose` + `docker_compose_location=/docker-compose.coolify.yml` (Coolify requires a leading slash). **Not an operator field.**

- `POST /applications/private-github-app` when `github_app_uuid` is set
- `POST /applications/private-deploy-key` when `private_key_uuid` is set
- `POST /applications/public` otherwise

Deprecated `POST /applications/dockercompose` (raw YAML, no git) is not implemented.

Preflight (Turkish errors) runs in Plane **before** that POST: selected server must be in `listServers`; selected Git source must be in the matching sources list.

Coolify **422** `message` + `errors{field: []}` is appended on `CoolifyApiException` and shown on the site provision flash + audit (`site.provision_failed`). Tokens stay redacted.

## Domains

Live GET `docker_compose_domains` is often a **JSON string** object (`{"app":{"domain":"https://…"}}`); `Application.fqdn` is often null on older compose apps. Coolify **generate-domain** (create without a domain) writes `{uuid}.demo.codron.co` or `{uuid}.random.codron.co` on **`fqdn`** and does **not** clear it when Plane PATCHes only compose domains.

Create and `setDomains` send **only** `docker_compose_domains: [{ "name": "app", "domain": "https://{operator-host}" }]`. **Do not send `fqdn`** — Coolify compose create/PATCH returns `Validation failed. fqdn: This field is not allowed.` `force_domain_override` defaults false. If Coolify still writes a generate-domain, Plane does **not** make it the `site_domains` primary. Do not PATCH live generate-domains off existing apps without operator OK — they may still be on the proxy.

## Coolify menu (not Settings)

Ops **Coolify** menu (`/coolify`): connections, API token, test, sync, aktif/pasif, defaults, deploy webhook URL. Encrypted `webhook_secret` per connection; env fallback `COOLIFY_WEBHOOK_SECRET`. Settings is GitHub theme catalog only — leftover `POST /settings` and `POST /settings/coolify/test` redirect here. Deploy webhooks: [coolify-webhooks.md](coolify-webhooks.md).

### Connection routes

| Method | Path | Name | Notes |
|--------|------|------|-------|
| GET | `/coolify/{connection}` | `ops.coolify.show` | Connection page. Inventory table rows open show/detail, never edit |
| GET | `/coolify/{connection}/servers/{server}` | `ops.coolify.servers.show` | Server detail |
| GET | `/coolify/{connection}/projects/{project}` | `ops.coolify.projects.show` | Project detail + environments |
| GET | `/coolify/{connection}/environments/{environment}` | `ops.coolify.environments.show` | Environment detail |
| GET | `/coolify/{connection}/git-sources/{source}` | `ops.coolify.git-sources.show` | Git source detail. Linked sites match `coolify_git_source_uuid` on this connection (or `coolify_connection_id` null when this connection is default). |
| POST | `/coolify/{connection}/sync` | `ops.coolify.sync` | Inventory sync (`CoolifyInventorySync`). CSRF. Operator / Super Admin |
| GET | `/coolify/{connection}/sync` | `ops.coolify.sync.get` | **Does not sync.** 302 to show + flash “use the Sync button” |
| POST | `/coolify/{connection}/test` | `ops.coolify.test` | `listServers` only |

**Sync UI:** dedicated `<form method="POST">` + CSRF + **Sync** button (topbar and Bağlantı panel). Never `<a href="…/sync">` and never `formaction` on the PUT Kaydet form (`_method=PUT` would 405). Browser GET `/coolify/1/sync` is a redirect, not a 405.
