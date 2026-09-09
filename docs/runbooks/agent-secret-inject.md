# Runbook: Inject CMS agent secret

Internal ops only. Do not paste `CONTROL_PLANE_AGENT_SECRET`, `APP_KEY`, or Coolify tokens into tickets or this file.

Import (`ops:import-coolify-apps`) and leftover sites **do not** get an agent secret. Plane will skip health polls and mark the site `needs_secret` until the CMS env and the Plane row match.

Dalga 5 hardens this into a Coolify env-patch + redeploy job. Until then this is a manual Coolify UI step.

## Preconditions

1. The site exists in Plane (`coolify_app_uuid` set).
2. You can open the customer Coolify application (not Plane).
3. You have a fresh random secret (64+ chars). Generate it out of band. Do not reuse the Coolify API token or the Plane webhook secret.

## Steps

1. Coolify → customer Deamon app → **Environment**.
2. Add `CONTROL_PLANE_AGENT_SECRET` on the **app** service only. Do not put it on MySQL/Redis. Do not commit it.
3. Redeploy that Coolify app (volumes stay; do not DELETE the application).
4. In Plane, store the **same** value on `sites.agent_secret_encrypted` (encrypted cast). v1: tinker / ops shell — not the Sites form (the form never shows secrets).
5. Plane → site edit → **Check health**. Expect status `ok` and a `deamon_version`.
6. Plane → **Check health**. CMS Task 8 (`/internal/control/v1/health`, Deamon v1.1.43) must be deployed on the site. Headers are locked: `X-Deamon-Timestamp`, `X-Deamon-Nonce`, `X-Deamon-Signature`.

## Failure notes

| Symptom | What to do |
|---------|------------|
| `needs_secret` | Plane row has no encrypted secret. Do not invent one in import. Inject as above. |
| `bad_signature` | CMS secret ≠ Plane secret. Rotate both sides to the same value. Headers are locked (`X-Deamon-*`) — see [agent-client.md](../modules/agent-client.md). |
| Timeout | CMS down, wrong `agent_base_url`, or agent routes not registered (`CONTROL_PLANE_AGENT_SECRET` empty on CMS skips route register). |
| Secret in logs / Blade | Bug — stop and rotate. Health payload and audit must never contain the secret. |

Coolify webhook HMAC (`COOLIFY_WEBHOOK_SECRET`) is a **different** secret. Do not reuse it as the agent key.

ZIP, SSH, and Mailcow are out of scope.
