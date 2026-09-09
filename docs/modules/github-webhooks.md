# GitHub theme webhooks

Task 13. **Not** Coolify deploy webhooks (`POST /webhooks/coolify`, `X-Coolify-Signature`).

## Endpoint

`POST /webhooks/github`

| Item | Value |
|------|--------|
| Signature header | `X-Hub-Signature-256: sha256=<hex>` |
| Event header | `X-GitHub-Event` (`ping`, `push`, `release`) |
| Secret | `github_settings.webhook_secret` (encrypted) or `GITHUB_WEBHOOK_SECRET` |
| Empty secret | **Rejected** (HMAC of `""` is not accepted) |

Throttle: 60/minute.

## Behaviour

1. Verify HMAC.
2. Match `repository.full_name` → `themes.repo_full_name`.
3. Update `latest_sha` / `latest_tag`.
4. Fan-out `ThemeUpdateJob` only where `auto_update = true` (v1 default **off**).
5. Skip + audit `theme.update_skipped_version` when site `deamon_version` &lt; theme `minimum_deamon_version`.
6. Concurrency: `ops.themes.fanout_concurrency` (default 3) via staggered job delay + unique jobs.

Configure the webhook on the `deamon-themes` org (or each `deamon-theme-*` repo) to this URL. Content type JSON.
