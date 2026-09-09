# Theme catalog (Plane)

Task 10. Plane mirrors `deamon-theme-*` repos from GitHub org `deamon-themes` (config `GITHUB_ORG`). **ZIP is not accepted in Plane.** Emergency ZIP remains CMS Super Admin `themes.import`.

## Tables

| Table | Role |
|-------|------|
| `themes` | Catalog row (`theme_id`, `repo_full_name`, visibility, `minimum_deamon_version`, latest sha/tag) |
| `theme_site_access` | Allowlist (`theme_id` + `site_id`) |
| `site_theme_installations` | Per-site install (`ref`, `pinned_sha`, `is_active`, **`auto_update` default false**) |
| `github_settings` | Encrypted PAT / GitHub App PEM / webhook secret |

Visibility: `public_catalog` (any managed site) · `allowlist` (access row required) · `private` (operator may still assign explicitly).

## Sync

Settings → **GitHub theme catalog** (encrypted). Then Themes → **Sync catalog**, or `php artisan ops:sync-theme-catalog`.

`GitHubAppClient` lists org repos, keeps the `deamon-theme-` prefix, reads `theme.json` then `theme/theme.json`, stores `minimum_deamon_version`. First sync defaults visibility to **private**.

Live GitHub credentials are optional for development. Tests use `Http::fake` only.

## Agent install

Assign is Task 12 (`ThemeRolloutService`) via the CMS theme agent — [theme-agent-client.md](theme-agent-client.md). GitHub push fan-out is Task 13 — [github-webhooks.md](github-webhooks.md).
