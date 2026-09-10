# Runbook: Theme rollout

Internal ops only. Do not paste GitHub PATs, App PEMs, clone tokens, or site agent secrets into tickets.

Themes are **git-only**. Plane has no ZIP upload. Emergency ZIP is CMS Super Admin `themes.import`.

## Preconditions

1. Theme catalog synced (Themes → **Connect GitHub** / connect another account or org, then **Sync catalog**). Not Settings paste. Legacy migrated connection keeps prefix `deamon-theme-`; new connections have no prefix unless set. Coolify GitHub App UUID is unrelated.
2. Site is **active** (or at least has `agent_base_url` / primary domain) and has `CONTROL_PLANE_AGENT_SECRET` injected — [agent-secret-inject.md](agent-secret-inject.md).
3. CMS **v1.2.7+** agent routes are live (`CONTROL_PLANE_AGENT_SECRET` injected). Theme git `repo` may be any github.com owner (`owner/name` or https / `.git`). HMAC/paths match the v1.2.5 lock. Plane Task 12 stays install → activate → sync.
4. Visibility: public_catalog, or allowlist membership, or an explicit private assign.

## Assign

1. Plane → **Sites** → open the site → **Themes**.
2. Pick a catalog theme + optional ref. Leave **Activate** checked to replace the live CMS theme.
3. Confirm modal (`PlaneConfirm`, not `window.confirm`). Server rejects activate without `confirmed`.
4. Plane writes `site_theme_installations` (`auto_update` stays **off**) and POSTs **install**, then optional **activate**, then optional **sync** (three calls). Install never activates or data-syncs on the CMS. Sync body is `{action:sync_all, mode:merge, theme_id}`.
5. Status **active** and SHA appear on success. Failures stay on the row (`last_error`) and in audit. Secrets are not in audit.

## Update / sync

- **Update to latest** — agent `themes/update` to catalog `latest_sha` / ref.
- **Sync now** — agent `themes/sync` (CMS BackgroundTasks).
- **Enable auto-update** — opt-in only. GitHub push then fans out [github-webhooks.md](../modules/github-webhooks.md).

If `minimum_deamon_version` is set and last health `deamon_version` is lower, Plane skips and audits `theme.update_skipped_version`.

## GitHub webhook

1. App Manifest already points the GitHub App webhook at `https://{plane}/webhooks/github`. PAT-only: create a repo/org webhook to the same URL.
2. Secret = App-level `github_settings.webhook_secret` (distinct from Coolify webhook secret). Never shown after save.
3. Events: `push`, `release` (and `ping`).
4. Only installations with auto-update on receive `ThemeUpdateJob`.

## Failure notes

| Symptom | What to do |
|---------|------------|
| Flash: agent secret | Inject CMS `CONTROL_PLANE_AGENT_SECRET`, then Check health. |
| Flash: allowlisted | Add the site on the theme Allowlist, or change visibility. |
| Flash: confirmation required | Activate/assign-activate without the modal. |
| CMS 404 on `/themes/*` (no JSON) | Agent secret missing on the CMS Coolify app — routes are not registered. |
| Flash / last_error: system theme | Do not assign `default`. |
| Version skip | Upgrade the site channel / CMS version, or assign a theme without a floor. |
