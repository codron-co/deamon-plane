# Coolify deploy webhooks

Task 6. Coolify → Plane deploy events update the same `deployments` rows as `PollDeploymentJob`. GitHub theme webhooks are Task 13 (separate controller).

## Endpoint

`POST /webhooks/coolify` (no session, no CSRF). CSRF-exempt because the route is registered outside the `web` group.

There is no Coolify Public API to register the URL (spike: OpenAPI has no register-webhook method).

## Coolify native (unsigned + query token)

Coolify **Notifications → Webhook** POSTs JSON (`Content-Type: application/json`) with **no** HMAC header. Paste this URL (same signing secret as Coolify menu → connection → webhook signing secret):

```
https://{plane-host}/webhooks/coolify?token=<webhook signing secret>
```

Example for this fleet: `https://plane.codron.co/webhooks/coolify?token=<same webhook signing secret>`.

| Item | Value |
|------|--------|
| Query | `token` (preferred) or `secret` |
| Match | `hash_equals` against `coolify_settings.webhook_secret` or env `COOLIFY_WEBHOOK_SECRET` |
| Empty secret | Rejected (401). Never compare against `''`. |
| Logging | Token and secret are never logged or rendered after save |

If HMAC headers are **missing**, Plane accepts the request only when the query token matches. Wrong or missing token → **401**.

## HMAC (preferred when a signer exists)

If `X-Coolify-Signature`, `X-Hub-Signature-256`, or `X-Signature` is present, Plane verifies HMAC-SHA256 of the raw body. A present-but-invalid header is **401** (query token is not a bypass). Use this path for a signing proxy in front of `/webhooks/coolify`.

| Item | Value |
|------|--------|
| Secret | Any `coolify_connections.webhook_secret` (encrypted), then `coolify_settings.webhook_secret`, then env `COOLIFY_WEBHOOK_SECRET` |
| Preferred header | `X-Coolify-Signature: sha256=<hex>` |
| Also accepted | `X-Hub-Signature-256`, `X-Signature` (`sha256=<hex>` or raw hex) |
| MAC | `HMAC-SHA256(raw body, secret)` |
| Empty secret | Rejected (401). Never `hash_hmac(..., '')`. |

Neither HMAC nor token match → **401**.

## Payload → `deployments.status`

Accepted shapes:

1. Coolify notification: `event` `deployment_success` / `deployment_failed`, plus `deployment_uuid`, `application_uuid`, optional `commit`.
2. API-shaped: `uuid` / `status` / `commit` / `application_uuid` (same fields as `GET /deployments/{uuid}`).

Status map matches `SiteProvisioner::mapRemoteStatus` (`finished`/`success` → `finished`; `failed`/`error` → `failed`; `cancelled`; `queued`/`pending` → in-progress).

Lookup order: `coolify_deployment_uuid` → open (queued/in_progress) row for `sites.coolify_app_uuid` → create a `trigger=manual` row when the app uuid is known.

Terminal rows are not regressed by a later in-progress event (webhook and poll share this guard).

## UI

- Coolify menu → connection: **Deploy webhook URL** is the path only. The hint tells the operator to append `?token=` + the webhook signing secret. The live secret is never rendered after save.
- Site detail **Deployments**: last 25 rows, status chip, duration, short SHA, **Sync Coolify**, **Open in Coolify**. Sync pulls Coolify’s application + `GET /deployments/applications/{uuid}`. Row click opens the deployment **show** page (full Coolify `message` + `errors` JSON, truncated redacted logs, copy).
- Poll and webhook **failure** persist `error_message` (Coolify message + `errors` JSON) and `log_excerpt` (truncated, secrets redacted). A webhook failure with a deployment uuid will GET `/deployments/{uuid}` when the site connection has a token, so logs are not missing just because the notification body was thin.
- Fleet dashboard KPIs (5): total sites, by channel, unhealthy (`status=error` **or** agent timeout / bad signature / `queue_ok=false` / stale), failed deploys, deploying. `needs_secret` does not count. See [agent-client.md](agent-client.md).

## Poll fallback

`PollDeploymentJob` already polls `GET /deployments/{uuid}` every `ops.provision.poll_seconds` (default 15s). Webhooks are the fast path; poll remains the backup if notifications are misconfigured.
