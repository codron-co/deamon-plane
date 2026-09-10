# Runbook: Token rotation

Never paste live tokens into git, chat, or audit notes. After rotation, confirm the old value is revoked at the source.

## Coolify API token

1. Coolify → Keys & Tokens → create a least-privilege token.
2. Plane → **Coolify** → open the connection → paste into API token (leave blank later to keep). Save.
3. **Test connection**.
4. Revoke the previous Coolify token.
5. Audit is not written for the token value (encrypted column only).

Webhook signing secret (`COOLIFY_WEBHOOK_SECRET` / Coolify menu webhook secret) is a **different** secret. Rotate it in Plane and in the Coolify Notifications URL (`?token=`) together, or unsigned POSTs 401. Signed proxies must update the HMAC key at the same time.

## Cloudflare API token

1. Cloudflare dashboard → **My Profile → API Tokens → Create Token**.
2. Custom token (do not use the **DNS Write** template alone).
3. Required permission groups:
   - **DNS & Zones → DNS:** Read + Edit
   - **DNS & Zones → Zone:** Read + Edit
4. Resource: **All zones in the account, including future zones**. A zone-scoped token cannot `POST /zones`.
5. Do not grant Developer Platform, AI, App Security, Rules, Zero Trust, Analytics, Network, Email Routing, Cache, SSL, Apps, or Page Shield.
6. Plane → **Cloudflare** → paste Account ID + token (leave token blank later to keep). Save.
7. **Test connection** (probes verify + Zone Read + Zone Edit via nameless `POST /zones` + DNS Read/Edit if a zone exists). Never creates a real zone.
8. Revoke the previous token.

## GitHub PAT / App

1. PAT: GitHub → Fine-grained token, org `deamon-themes`, contents read on `deamon-theme-*`.
2. Or GitHub App: install on the org; paste App id, installation id, PEM into Settings → GitHub.
3. Save. **Test GitHub**.
4. Revoke the old PAT or rotate the App private key.
5. Theme webhook secret (`GITHUB_WEBHOOK_SECRET`) is distinct from the Coolify webhook secret. Update the GitHub webhook and Plane together.

## Site agent HMAC

Per site. See [agent-secret-inject.md](agent-secret-inject.md). Import does not invent secrets. After Coolify env patch + redeploy, use **Check health**.

Do not reuse one secret across all customer sites if you can avoid it.

## Plane `APP_KEY`

Generated once (`php artisan key:generate --show`) and stored in Coolify env for the Plane app. Rotating `APP_KEY` invalidates encrypted columns (`coolify_settings`, `github_settings`, `sites.*_encrypted`). Treat as a break-glass rebuild, not a weekly rotate.
