# Coolify HTTP adapter

Task 2 client for Deamon Plane. Spike lock: [Coolify spike notes](../plans/2026-08-13-coolify-spike-notes.md).

## Classes

| Class | Role |
|-------|------|
| `App\Services\Coolify\CoolifyClient` | Bearer HTTP to `{COOLIFY_BASE_URL}/api/v1` |
| `App\Services\Coolify\CoolifyApplicationService` | Thin wrappers + channel allowlist on create/updateBranch |
| `App\Services\Coolify\CoolifyApiException` | Status, `conflicts[]`, token redaction |
| `App\Services\Coolify\CoolifyUnsupportedOperationException` | Hybrid gap + `ManualChecklist` DTO |
| `App\Models\CoolifySetting` | Singleton row; `api_token` encrypted + hidden |

Do not call Coolify from tests with a live token. Use `Http::fake`. Never log `api_token` or env `value`.

Provision (Task 4) consumes `CoolifyApplicationService` only — do not add HTTP mapping here. Flow: [provision-site.md](../runbooks/provision-site.md).

Fleet import (Task 7) calls `listApps` only (`ops:import-coolify-apps`). It does not create apps, patch env, or deploy. Runbook: [import-coolify-apps.md](../runbooks/import-coolify-apps.md).

## Create path

Git + `build_pack=dockercompose` + `docker_compose_location=docker-compose.coolify.yml`.

- `POST /applications/private-github-app` when `github_app_uuid` is set
- `POST /applications/private-deploy-key` when `private_key_uuid` is set
- `POST /applications/public` otherwise

Deprecated `POST /applications/dockercompose` (raw YAML, no git) is not implemented.

## Domains

Live GET `docker_compose_domains` is often a **JSON string** object (`{"app":{"domain":"https://…"}}`); `Application.fqdn` is often null. The adapter parses that string. PATCH always sends the OpenAPI **array** `[{ "name": "app", "domain": "https://…" }]`. `force_domain_override` defaults false.

## Settings

Ops **Settings → Coolify connection**: encrypted token save + Test connection (`listServers`). Encrypted `webhook_secret` (HMAC for `POST /webhooks/coolify`) with env fallback `COOLIFY_WEBHOOK_SECRET`. Env `COOLIFY_*` remains a fallback when the DB row is empty. Deploy webhooks: [coolify-webhooks.md](coolify-webhooks.md).
