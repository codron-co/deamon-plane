# Runbook: Token rotation

Never paste live tokens into git, chat, or audit notes. After rotation, confirm the old value is revoked at the source.

## Coolify API token

1. Coolify → Keys & Tokens → create a least-privilege token.
2. Plane → **Settings → Coolify** → paste into API token (leave blank later to keep). Save.
3. **Test connection**.
4. Revoke the previous Coolify token.
5. Audit is not written for the token value (encrypted column only).

Webhook HMAC (`COOLIFY_WEBHOOK_SECRET` / Settings webhook secret) is a **different** secret. Rotate it in Coolify Notifications and Plane together or signed POSTs 401.

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
