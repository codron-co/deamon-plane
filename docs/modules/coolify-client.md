# Coolify HTTP adapter

Task 2 client for Deamon Plane. Spike lock: [Coolify spike notes](../plans/2026-08-13-coolify-spike-notes.md).

Ops inventory (N connections, allowlists, site dropdowns): this page + [ops-sites.md](ops-sites.md).

## Classes

| Class | Role |
|-------|------|
| `App\Services\Coolify\CoolifyClient` | Bearer HTTP to `{COOLIFY_BASE_URL}/api/v1` |
| `App\Services\Coolify\CoolifyApplicationService` | Thin wrappers + channel allowlist on create/updateBranch. `forConnection()` builds a per-connection client. |
| `App\Services\Coolify\CoolifyInventorySync` | `listServers` / `listProjects` / `listEnvironments` / `listGithubApps` / `listPrivateKeys` → allowlist tables |
| `App\Services\Coolify\CoolifyProvisionPreflight` | Before `POST` create: server must appear in live `listServers`; Git source in live GitHub App or deploy-key list |
| `App\Services\Coolify\CoolifyApiException` | Status, `conflicts[]`, validation `errors` in the message, token redaction |
| `App\Services\Coolify\CoolifyUnsupportedOperationException` | Hybrid gap + `ManualChecklist` DTO |
| `App\Models\CoolifyConnection` | N rows; `api_token` / `webhook_secret` encrypted + hidden; one `is_default` |
| `App\Models\CoolifySetting` | Legacy singleton; webhook fallback + `resolvedWebhookSecrets()` (all connections + settings + env) |

Do not call Coolify from tests with a live token. Use `Http::fake`. Never log `api_token` or env `value`.

Provision consumes `CoolifyApplicationService` + preflight. Flow: [provision-site.md](../runbooks/provision-site.md).

Fleet import (Task 7) calls `listApps` only (`ops:import-coolify-apps`). It does not create apps, patch env, or deploy. Runbook: [import-coolify-apps.md](../runbooks/import-coolify-apps.md).

## Connections (ops menu)

Left nav **Coolify**. Super Admin / operator write; viewer read.

| Table | Role |
|-------|------|
| `coolify_connections` | name, base_url, encrypted token + webhook secret, `is_enabled`, `is_default`, selected defaults |
| `coolify_servers` | uuid, name, `is_active` — inactive cannot be chosen on site create |
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

Create and `setDomains` send **only the operator host**: OpenAPI array `docker_compose_domains: [{ "name": "app", "domain": "https://…" }]` **and** `fqdn` set to that same first host (replaces leftover generate-domain). `force_domain_override` defaults false. Do not PATCH live generate-domains off existing apps without operator OK — they may still be on the proxy.

## Coolify menu (not Settings)

Ops **Coolify** menu (`/coolify`): connections, API token, test, sync, aktif/pasif, defaults, deploy webhook URL. Encrypted `webhook_secret` per connection; env fallback `COOLIFY_WEBHOOK_SECRET`. Settings is GitHub theme catalog only — leftover `POST /settings` and `POST /settings/coolify/test` redirect here. Deploy webhooks: [coolify-webhooks.md](coolify-webhooks.md).

### Connection routes

| Method | Path | Name | Notes |
|--------|------|------|-------|
| GET | `/coolify/{connection}` | `ops.coolify.show` | Connection page |
| POST | `/coolify/{connection}/sync` | `ops.coolify.sync` | Inventory sync (`CoolifyInventorySync`). CSRF. Operator / Super Admin |
| GET | `/coolify/{connection}/sync` | `ops.coolify.sync.get` | **Does not sync.** 302 to show + flash “use the Sync button” |
| POST | `/coolify/{connection}/test` | `ops.coolify.test` | `listServers` only |

**Sync UI:** dedicated `<form method="POST">` + CSRF + **Sync** button (topbar and Bağlantı panel). Never `<a href="…/sync">` and never `formaction` on the PUT Kaydet form (`_method=PUT` would 405). Browser GET `/coolify/1/sync` is a redirect, not a 405.
