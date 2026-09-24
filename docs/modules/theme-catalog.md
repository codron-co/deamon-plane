# Theme catalog (Plane)

Task 10, extended 2026-09-10: **theme Git connections**. Plane mirrors git theme repos from one or more GitHub user/org **installations** (one Plane GitHub App, many connections). **ZIP is not accepted in Plane.** Emergency ZIP remains CMS Super Admin `themes.import`.

SoT for the new connect model: [../superpowers/specs/2026-09-10-theme-git-connections-design.md](../superpowers/specs/2026-09-10-theme-git-connections-design.md). Implementation todos: [../plans/2026-09-10-theme-git-connections.md](../plans/2026-09-10-theme-git-connections.md).

`GITHUB_ORG` / `github_settings.org` is **not** a catalog lock. Coolify `GET /github-apps` UUID is a **different** object (customer compose apps) and must not be reused here.

## Tables

| Table | Role |
|-------|------|
| `themes` | Catalog row (`theme_id`, `repo_full_name`, visibility, `minimum_deamon_version`, `smoke_paths` (json), latest sha/tag, nullable `theme_git_connection_id`) |
| `theme_site_access` | Allowlist (`theme_id` + `site_id`) |
| `site_theme_installations` | Per-site install (`ref`, `pinned_sha`, `is_active`, **`auto_update` default false**) |
| `github_settings` | Singleton **App** row: encrypted PEM / `client_secret` / `webhook_secret`, `app_id`, `slug`. Not the per-account connection. `org` is leftover only. |
| `theme_git_connections` | One row per connected GitHub user or org (`kind` `github_app` \| `pat`; `selection_mode` `all` \| `selected`; optional `repo_name_prefix`) |
| `theme_git_connection_repos` | Picker inventory per connection (`included` gates catalog when mode is `selected`) |

Visibility: `public_catalog` (any managed site) · `allowlist` (access row required) · `private` (operator may still assign explicitly).

## Connect (Themes, not Settings)

Themes → **Connect GitHub** (GitHub App Manifest, Coolify-style) creates the Plane App once, then installs it on a user or org. **Connect another** installs the **same** App on another account. Those forms use `data-ops-native` so `ops-async.js` does not intercept the POST (the connect action returns an HTML manifest page that posts to GitHub; connect-another redirects away to GitHub). Advanced **PAT** is allowed when Plane has no public callback URL. After install, operator clicks **Sync repos** on the connection (the install callback does not auto-refresh the picker).

Per connection the operator includes **all** accessible repos or a **selected** subset. Optional repo-name prefix. A connection migrated from the old Settings paste keeps prefix `deamon-theme-` so existing orgs do not suddenly ingest every repo. New connections default prefix to empty.

Settings no longer hosts PAT/PEM/org paste. It shows a one-line pointer back to Themes; the Settings jump / palette `webhook` needle lands on that panel (`#github-connection-heading`). Leftover `POST /settings/github` redirects here.

## Sync

Themes → **Sync catalog**, per-connection Sync, or `php artisan ops:sync-theme-catalog`.

`ThemeCatalogSync` iterates **every** `status=connected` connection, lists repos that installation/token can see (`GET /installation/repositories` or PAT list — not a global `GET /orgs/{GITHUB_ORG}/repos` lock), applies optional prefix, then:

- `all`: every matching repo → upsert `themes`
- `selected`: only `included` repos → upsert `themes`

After those upserts, sync **deletes** catalog rows whose `theme_git_connection_id` is null (left behind when a connection was disconnected). Site installations and allowlist rows for those themes cascade. Connected themes are not pruned just because a repo was deselected.

Reads `theme.json` then `theme/theme.json`, stores `minimum_deamon_version` and `smoke_paths` (storefront paths only: leading `/`, no scheme/host/`//`/`..`, at most 10; anything else is dropped). First sync defaults visibility to **private**. Existing visibility is not reset. `theme_id` collision across different `repo_full_name` skips (does not steal the row).

Live GitHub credentials are optional for development. Tests use `Http::fake` only.

## Empty states

Catalog-empty and filter-empty are different problems, same pattern as Sites.

- Nothing in the catalog yet → `themes.empty.title` plus **Katalogu senkronla** for writers (`can('sync', Theme)`), or `ops.viewer_readonly` for a Viewer. The hint is operator copy only — it does not mention the test suite.
- Filters excluded everything → `themes.empty.filtered_title`, the catalog size (`themes.empty.filtered_hint`), one removable chip per active filter (`ops.partials.filter-chips`; search and visibility), and **Filtreleri temizle** as the primary action.

`ThemeController::activeListFilters()` builds the chips. The async region ships the same states. Tests: `ThemeListEmptyStatesTest`.

The Ctrl/⌘+K palette searches this catalog through `Theme::matchingListFilters` — the same
predicate the toolbar posts — and jumps to `ops.themes.show`. Tests: `PaletteSearchTest`.

## Agent install

Assign is Task 12 (`ThemeRolloutService`) via the CMS theme agent — [theme-agent-client.md](theme-agent-client.md). CMS **1.2.7** accepts any github.com `owner/name` (and https / `.git` forms) for `source=git`; Plane still only assigns connected/selected catalog rows. GitHub push fan-out is Task 13 — [github-webhooks.md](github-webhooks.md). A theme with `ci_gate` on moves its catalog sha and fans out only after its repo's `CI` workflow is green for the pushed commit — [ci-gated-rollout.md](ci-gated-rollout.md#themes). Site Themes tab / list / overview show a Plane installation when one exists; otherwise they show `last_health_payload.active_theme_id` as reported-by-health and do not claim the site has no theme.

`clone_token` is minted from the **theme’s connection** installation id. PAT connections never send a token to the site.

**CMS version gate on assign.** When the site's last health reports a `deamon_version` below the theme's `minimum_deamon_version`, assign is refused before anything is sent (`sites.theme_flash.cms_too_old`). Unknown version does not block (same rule as updates, `ThemeVersionGate`).

**Storefront smoke check (`ThemeSmokeCheck`).** After an install, update or sync of the **active** theme, Plane GETs `https://{primary_domain}/` plus every `smoke_paths` entry. Only a 5xx other than 503 counts (503 = CMS maintenance page; connection errors are inconclusive), and a failing path is asked twice. On failure Plane first polls agent health:

| Health says | Plane does | Installation |
|-------------|------------|--------------|
| `core_theme_in_sync: false` | Nothing to the theme; the health poll restarts the app (`CoreThemeHealer`) | `error`, `smoke_core_stale` |
| otherwise, update with `previous_pinned_sha` | `rollbackThemeFiles` (back to the previous commit) | `error`, `smoke_rolled_back_files` |
| otherwise, sync with `last_sync_task_id` | `rollbackLastSync` (CMS restores the rows) | `error`, `smoke_rolled_back_sync`; operator sync gets the error flash |
| otherwise (first install) | Nothing to go back to | `error`, `smoke_failed` |

Audit `theme.smoke_failed` (`change`, `failures`, `core_theme_stale`, `rolled_back`). Config `ops.themes.smoke.*` (`OPS_THEME_SMOKE_ENABLED`, `OPS_THEME_SMOKE_TIMEOUT`); phpunit disables it, tests opt in. Origin: moonagro.com 2026-09-23 — new theme installed, `/timeline` 500'd on `core::partials.timeline.feed`.
