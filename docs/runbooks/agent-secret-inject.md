# Runbook: Inject CMS agent secret

Internal ops only. Do not paste `CONTROL_PLANE_AGENT_SECRET`, `APP_KEY`, or Coolify tokens into tickets or this file.

Import (`ops:import-coolify-apps`) and leftover sites **do not** get an agent secret. Plane will skip health polls and mark the site `needs_secret` until the CMS env and the Plane row match.

Plane **Generate & inject secret** on site edit calls Coolify `PATCH /applications/{uuid}/envs/bulk` with `CONTROL_PLANE_AGENT_SECRET`. The value is generated if missing, stored encrypted on the site, and never rendered. Provision also attempts this after compose create. Tinker is leftover only if the Coolify env API fails.

## Preconditions

1. The site exists in Plane (`coolify_app_uuid` set) — provision or attach.
2. A Coolify connection is configured (Coolify menu) so `updateEnvs` can run.
3. Do not reuse the Coolify API token or the Plane webhook secret.

## Steps

1. Plane → site edit → **Generate & inject secret**.
2. If Coolify env write fails, use Coolify UI on the **app** service only (not MySQL/Redis). Redeploy; do not DELETE the application. Do not paste the secret into tickets.
3. Plane → site edit → **Check health**. Expect status `ok` and a `deamon_version`.
4. CMS Task 8 (`/internal/control/v1/health`) must be deployed. Headers: `X-Deamon-Timestamp`, `X-Deamon-Nonce`, `X-Deamon-Signature`.

## Failure notes

| Symptom | What to do |
|---------|------------|
| `needs_secret` | Plane row has no encrypted secret. Do not invent one in import. Inject as above. |
| `bad_signature` | CMS secret ≠ Plane secret. Rotate both sides to the same value. Headers are locked (`X-Deamon-*`) — see [agent-client.md](../modules/agent-client.md). |
| Timeout | CMS down, wrong `agent_base_url`, or agent routes not registered (`CONTROL_PLANE_AGENT_SECRET` empty on CMS skips route register). |
| Secret in logs / Blade | Bug — stop and rotate. Health payload and audit must never contain the secret. |

Coolify webhook HMAC (`COOLIFY_WEBHOOK_SECRET`) is a **different** secret. Do not reuse it as the agent key.

ZIP, SSH, and Mailcow are out of scope.
