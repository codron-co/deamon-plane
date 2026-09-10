# Theme catalog (Plane)

Task 10, extended 2026-09-10: **theme Git connections**. Plane mirrors git theme repos from one or more GitHub user/org **installations** (one Plane GitHub App, many connections). **ZIP is not accepted in Plane.** Emergency ZIP remains CMS Super Admin `themes.import`.

SoT for the new connect model: [../superpowers/specs/2026-09-10-theme-git-connections-design.md](../superpowers/specs/2026-09-10-theme-git-connections-design.md). Implementation todos: [../plans/2026-09-10-theme-git-connections.md](../plans/2026-09-10-theme-git-connections.md).

`GITHUB_ORG` / `github_settings.org` is **not** a catalog lock. Coolify `GET /github-apps` UUID is a **different** object (customer compose apps) and must not be reused here.

## Tables

| Table | Role |
|-------|------|
| `themes` | Catalog row (`theme_id`, `repo_full_name`, visibility, `minimum_deamon_version`, latest sha/tag, nullable `theme_git_connection_id`) |
| `theme_site_access` | Allowlist (`theme_id` + `site_id`) |
| `site_theme_installations` | Per-site install (`ref`, `pinned_sha`, `is_active`, **`auto_update` default false**) |
| `github_settings` | Singleton **App** row: encrypted PEM / `client_secret` / `webhook_secret`, `app_id`, `slug`. Not the per-account connection. `org` is leftover only. |
| `theme_git_connections` | One row per connected GitHub user or org (`kind` `github_app` \| `pat`; `selection_mode` `all` \| `selected`; optional `repo_name_prefix`) |
| `theme_git_connection_repos` | Picker inventory per connection (`included` gates catalog when mode is `selected`) |

Visibility: `public_catalog` (any managed site) · `allowlist` (access row required) · `private` (operator may still assign explicitly).

## Connect (Themes, not Settings)

Themes → **Connect GitHub** (GitHub App Manifest, Coolify-style) creates the Plane App once, then installs it on a user or org. **Connect another** installs the **same** App on another account. Those forms use `data-ops-native` so `ops-async.js` does not intercept the POST (the connect action returns an HTML manifest page that posts to GitHub; connect-another redirects away to GitHub). Advanced **PAT** is allowed when Plane has no public callback URL. After install, operator clicks **Sync repos** on the connection (the install callback does not auto-refresh the picker).

Per connection the operator includes **all** accessible repos or a **selected** subset. Optional repo-name prefix. A connection migrated from the old Settings paste keeps prefix `deamon-theme-` so existing orgs do not suddenly ingest every repo. New connections default prefix to empty.

Settings no longer hosts PAT/PEM/org paste. It shows a one-line pointer back to Themes. Leftover `POST /settings/github` redirects here.

## Sync

Themes → **Sync catalog**, per-connection Sync, or `php artisan ops:sync-theme-catalog`.

`ThemeCatalogSync` iterates **every** `status=connected` connection, lists repos that installation/token can see (`GET /installation/repositories` or PAT list — not a global `GET /orgs/{GITHUB_ORG}/repos` lock), applies optional prefix, then:

- `all`: every matching repo → upsert `themes`
- `selected`: only `included` repos → upsert `themes`

Reads `theme.json` then `theme/theme.json`, stores `minimum_deamon_version`. First sync defaults visibility to **private**. Existing visibility is not reset. `theme_id` collision across different `repo_full_name` skips (does not steal the row).

Live GitHub credentials are optional for development. Tests use `Http::fake` only.

## Agent install

Assign is Task 12 (`ThemeRolloutService`) via the CMS theme agent — [theme-agent-client.md](theme-agent-client.md). CMS **1.2.7** accepts any github.com `owner/name` (and https / `.git` forms) for `source=git`; Plane still only assigns connected/selected catalog rows. GitHub push fan-out is Task 13 — [github-webhooks.md](github-webhooks.md). Site Themes tab / list / overview show a Plane installation when one exists; otherwise they show `last_health_payload.active_theme_id` as reported-by-health and do not claim the site has no theme.

`clone_token` is minted from the **theme’s connection** installation id. PAT connections never send a token to the site.
