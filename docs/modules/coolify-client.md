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
| `coolify_environments` | project_uuid + uuid/name, `is_active` |
| `coolify_git_sources` | `github_app` \| `deploy_key`, uuid, name, `is_active` |

Existing `coolify_settings` row is copied into the first connection on migrate (encrypted columns copied as-is). Settings → Coolify is a pointer to this menu.

**Default connection:** `is_default` — new sites pre-select it.

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

Live GET `docker_compose_domains` is often a **JSON string** object (`{"app":{"domain":"https://…"}}`); `Application.fqdn` is often null. The adapter parses that string. PATCH always sends the OpenAPI **array** `[{ "name": "app", "domain": "https://…" }]`. `force_domain_override` defaults false.

## Settings vs Coolify menu

Ops **Coolify** menu: connections, test, sync, aktif/pasif, defaults. Encrypted `webhook_secret` per connection; env fallback `COOLIFY_WEBHOOK_SECRET`. Settings page only links here. Deploy webhooks: [coolify-webhooks.md](coolify-webhooks.md).
