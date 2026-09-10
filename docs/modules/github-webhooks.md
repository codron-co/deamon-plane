# GitHub theme webhooks

Task 13. **Not** Coolify deploy webhooks (`POST /webhooks/coolify`, `X-Coolify-Signature`). **Not** Coolify `GET /github-apps` (customer compose git source).

Theme Git connections: [theme-catalog.md](theme-catalog.md) · spec [../superpowers/specs/2026-09-10-theme-git-connections-design.md](../superpowers/specs/2026-09-10-theme-git-connections-design.md).

## Endpoint

`POST /webhooks/github`

| Item | Value |
|------|--------|
| Signature header | `X-Hub-Signature-256: sha256=<hex>` |
| Event header | `X-GitHub-Event` (`ping`, `push`, `release`; also `installation`, `installation_repositories`) |
| Secret | `github_settings.webhook_secret` (encrypted, **App-level**) or `GITHUB_WEBHOOK_SECRET` |
| Empty secret | **Rejected** (HMAC of `""` is not accepted) |

Throttle: 60/minute. Distinct from every Coolify connection webhook secret.

The GitHub App Manifest sets `hook_attributes.url` to this endpoint. Do not create a second org webhook unless the operator uses PAT-only (no App deliveries).

## Behaviour

1. Verify HMAC against the App secret.
2. Route:
   - `payload.installation.id` present → `theme_git_connections.installation_id` → that connection’s theme rows (`themes.theme_git_connection_id` / picker).
   - Installation id absent (manual PAT/org webhook) → match `repository.full_name` → `themes.repo_full_name` (legacy global match).
3. `push` / `release`: update `latest_sha` / `latest_tag`.
4. Fan-out `ThemeUpdateJob` only where `auto_update = true` (v1 default **off**). Source rows are the resolved connection’s themes (or the globally matched theme when there is no installation id).
5. Skip + audit `theme.update_skipped_version` when site `deamon_version` &lt; theme `minimum_deamon_version`.
6. Concurrency: `ops.themes.fanout_concurrency` (default 3) via staggered job delay + unique jobs.
7. `installation` deleted/suspend → connection `status=error` (no secrets in logs). `installation_repositories` refreshes the picker; catalog upsert stays on Sync.

Content type JSON.
