# Runbook: Token rotation

Never paste live tokens into git, chat, or audit notes. After rotation, confirm the old value is revoked at the source.

## Coolify API token

1. Coolify → Keys & Tokens → create a least-privilege token.
2. Plane → **Coolify** → open the connection → paste into API token (leave blank later to keep). Save.
3. **Test connection**.
4. Revoke the previous Coolify token.
5. Audit is not written for the token value (encrypted column only).

Webhook signing secret (`COOLIFY_WEBHOOK_SECRET` / Coolify menu webhook secret) is a **different** secret. Rotate it in Plane and in the Coolify Notifications URL (`?token=`) together, or unsigned POSTs 401. Signed proxies must update the HMAC key at the same time.

## GitHub App (theme catalog)

Theme connections live under **Themes**, not Settings. Coolify `GET /github-apps` UUID is a different object — do not rotate or reuse it here.

1. Preferred: Themes → **Connect GitHub** (Manifest creates the Plane App) then install on each user/org. **Connect another** reuses the same App.
2. To rotate the App private key: GitHub → the Plane App → generate a new private key; code worker / operator must replace `github_settings.private_key` (encrypted). Manifest conversion already stored PEM / `client_secret` / `webhook_secret` — those values are never shown again.
3. To rotate access to repos: GitHub → install settings (grant/revoke repos), or Plane picker (`all` / `selected`). Disconnect does not delete the App.
4. Advanced PAT (local / no public callback): Themes → **Connect with token**. Fine-grained contents read on the chosen account’s theme repos. Blank token on save keeps the existing value. Revoke the old PAT at GitHub after save.
5. Theme webhook secret is App-level (`github_settings.webhook_secret` or `GITHUB_WEBHOOK_SECRET`). Distinct from the Coolify webhook secret. Manifest sets the App webhook URL to `https://{plane}/webhooks/github`. Update GitHub and Plane together if you rotate the secret by hand.
6. A PAT is never sent to a CMS site. `clone_token` is only a short-lived installation token.

Legacy Settings → GitHub paste (org + PAT/PEM) is removed. Leftover `POST /settings/github` redirects to Themes.

## Site agent HMAC

Per site. See [agent-secret-inject.md](agent-secret-inject.md). Import does not invent secrets. After Coolify env patch + redeploy, use **Check health**.

Do not reuse one secret across all customer sites if you can avoid it.

## Plane `APP_KEY`

Generated once (`php artisan key:generate --show`) and stored in Coolify env for the Plane app. Rotating `APP_KEY` invalidates encrypted columns (`coolify_settings`, `github_settings`, `theme_git_connections.token`, `sites.*_encrypted`). Treat as a break-glass rebuild, not a weekly rotate.
