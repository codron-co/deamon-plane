# GitHub theme webhooks

Task 13. **Not** Coolify deploy webhooks (`POST /webhooks/coolify`, `X-Coolify-Signature`). **Not** Coolify `GET /github-apps` (customer compose git source).

Theme Git connections: [theme-catalog.md](theme-catalog.md) · spec [../superpowers/specs/2026-09-10-theme-git-connections-design.md](../superpowers/specs/2026-09-10-theme-git-connections-design.md).

## Endpoint

`POST /webhooks/github`

| Item | Value |
|------|--------|
| Signature header | `X-Hub-Signature-256: sha256=<hex>` |
| Event header | `X-GitHub-Event` (`ping`, `push`, `release`, `workflow_run`; also `installation`, `installation_repositories`) |
| Secret | `github_settings.webhook_secret` (encrypted, **App-level**) or `GITHUB_WEBHOOK_SECRET` |
| Empty secret | **Rejected** (HMAC of `""` is not accepted) |

Throttle: 60/minute. Distinct from every Coolify connection webhook secret.

The GitHub App Manifest sets `hook_attributes.url` to this endpoint and subscribes `push`, `release`, `workflow_run` (permissions `metadata`, `contents`, `actions`: read). An App created before the CI gate must be updated by hand: **Permissions & events** → Actions: Read-only + event **Workflow run**, then each installation accepts the new permission ([ci-gated-rollout.md](ci-gated-rollout.md#operator-steps-order-matters)). Do not create a second org webhook unless the operator uses PAT-only (no App deliveries); that webhook then needs **Workflow runs** too.

## Behaviour

1. Verify HMAC against the App secret.
2. Route:
   - `payload.installation.id` present → `theme_git_connections.installation_id` → that connection’s theme rows (`themes.theme_git_connection_id` / picker).
   - Installation id absent (manual PAT/org webhook) → match `repository.full_name` → `themes.repo_full_name` (legacy global match).
3. `push` / `release`: update `latest_sha` / `latest_tag`. A push to the default ref also records the branch head (`ci_branch_heads`). A theme with `ci_gate = true` does **not** move `latest_sha` on push; its green `CI` run does ([ci-gated-rollout.md](ci-gated-rollout.md#themes)).
4. Fan-out `ThemeUpdateJob` only where `auto_update = true` (v1 default **off**). Source rows are the resolved connection’s themes (or the globally matched theme when there is no installation id).
5. Skip + audit `theme.update_skipped_version` when site `deamon_version` &lt; theme `minimum_deamon_version`.
6. Concurrency: `ops.themes.fanout_concurrency` (default 3) via staggered job delay + unique jobs.
7. `installation` deleted/suspend → connection `status=error` (no secrets in logs). `installation_repositories` refreshes the picker; catalog upsert stays on Sync.

8. CMS repo (`DeamonRepo::fullName()`) `push` to a channel branch: records the branch head and queues `SyncCoolifyEnvCatalogJob`.
9. `workflow_run` (`GitHubWorkflowRunHandler`): `completed`, name `ops.ci.workflow_name` (`CI`), run event `push`. CMS repo → CI verdict per channel branch and, when green for the recorded head, a fleet rollout of `ci`-gated sites; theme repo (default ref, `ci_gate`) → catalog sha + fan-out. Red / superseded / unknown-head runs are recorded and audited, nothing deploys. Details: [ci-gated-rollout.md](ci-gated-rollout.md).

Every event goes through the same delivery dedupe (`X-GitHub-Delivery`, 7 days): a redelivered `workflow_run` never starts a second rollout (and a re-run of the same commit is idempotent anyway).

Content type JSON.
