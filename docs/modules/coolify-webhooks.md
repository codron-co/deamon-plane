# Coolify deploy webhooks

Task 6. Coolify → Plane deploy events update the same `deployments` rows as `PollDeploymentJob`. GitHub theme webhooks are Task 13 (separate controller).

## Endpoint

`POST /webhooks/coolify` (no session, no CSRF). CSRF-exempt because the route is registered outside the `web` group.

Paste this URL into Coolify **Notifications → Webhook**. There is no Coolify Public API to register the URL (spike: OpenAPI has no register-webhook method).

## Assumed HMAC (Coolify does not sign today)

Coolify’s notification channel sends unsigned JSON (`Content-Type: application/json`). Plane still **requires** HMAC so a public URL cannot rewrite fleet status.

| Item | Value |
|------|--------|
| Secret | `coolify_settings.webhook_secret` (encrypted) or env `COOLIFY_WEBHOOK_SECRET` |
| Preferred header | `X-Coolify-Signature: sha256=<hex>` |
| Also accepted | `X-Hub-Signature-256`, `X-Signature` (`sha256=<hex>` or raw hex) |
| MAC | `HMAC-SHA256(raw body, secret)` |
| Empty secret | Rejected (401). Never `hash_hmac(..., '')`. |

Invalid or missing signature → **401**. Secrets are never logged or rendered after save.

If the Coolify instance cannot attach a signature header, put a signing proxy in front of `/webhooks/coolify` or keep relying on the 15–30s poll job.

## Payload → `deployments.status`

Accepted shapes:

1. Coolify notification: `event` `deployment_success` / `deployment_failed`, plus `deployment_uuid`, `application_uuid`, optional `commit`.
2. API-shaped: `uuid` / `status` / `commit` / `application_uuid` (same fields as `GET /deployments/{uuid}`).

Status map matches `SiteProvisioner::mapRemoteStatus` (`finished`/`success` → `finished`; `failed`/`error` → `failed`; `cancelled`; `queued`/`pending` → in-progress).

Lookup order: `coolify_deployment_uuid` → open (queued/in_progress) row for `sites.coolify_app_uuid` → create a `trigger=manual` row when the app uuid is known.

Terminal rows are not regressed by a later in-progress event (webhook and poll share this guard).

## UI

- Site detail **Deployments**: last 25 rows, status chip, duration, short SHA, **Open in Coolify** (assumed `{base}/project/{project}/environment/{environment_name}/application/{uuid}`).
- Fleet dashboard KPIs (5): total sites, by channel, unhealthy (`status=error` **or** agent timeout / bad signature / `queue_ok=false` / stale), failed deploys, deploying. `needs_secret` does not count. See [agent-client.md](agent-client.md).

## Poll fallback

`PollDeploymentJob` already polls `GET /deployments/{uuid}` every `ops.provision.poll_seconds` (default 15s). Webhooks are the fast path; poll remains required until Coolify can sign outbound notifications.
