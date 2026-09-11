# Theme agent client

Task 12. Plane asks each CMS instance to git-install a catalog theme. CMS Task 11 (**Deamon v1.2.7** — theme git install) owns the installer in `codron-co/deamon`. This repo only has the signed HTTP client.

SoT: CMS `docs/modules/control-plane-agent.md` (Sürüm 1.2.7). Constants: `ControlPlaneAgentContract::CMS_VERSION` = `1.2.7` (comment: “Theme git install (CMS 1.2.7+)”). HMAC and `clone_token` unchanged from the v1.2.5 lock.

## HMAC (same as health)

Canonical string: `{timestamp}.{nonce}.{rawBody}`. GET body is empty `""`. POST signs the **compact JSON bytes** sent (`ControlPlaneAgentContract::encodeJson` — no pretty-print). HMAC-SHA256 lowercase hex, no `sha256=` prefix.

Headers: `X-Deamon-Timestamp` / `X-Deamon-Nonce` / `X-Deamon-Signature`. Never `X-Control-Plane-*`.

## Endpoints (CMS v1.2.7)

Base: `/internal/control/v1`

| Method | Path | Body |
|--------|------|------|
| GET | `/health` | empty |
| GET | `/themes` | empty |
| POST | `/themes/install` | `{theme_id, repo, ref, source:"git", sha?, clone_token?}` |
| POST | `/themes/update` | same as install |
| POST | `/themes/activate` | `{theme_id}` |
| POST | `/themes/data-install` | `{theme_id}` — CMS **1.2.14+** |
| POST | `/themes/sync` | `{action:sync_all\|capability, mode:merge\|reset, theme_id?, capability_id?, preserve?}` |

Install does **not** activate or data-sync. Assign flow is three separate calls: install → (optional) activate → (optional) sync.

## Data package (CMS 1.2.14+)

CMS theme sync reads `storage/app/theme-data/{theme_id}/sync.json`. SoT repos keep theme files under `theme/` and the data package (`sync.json` + `data/`) at the repo root, so CMS < 1.2.14 published only the theme subtree and every sync failed with `Bu tema için sync.json tanımlı değil.`

- CMS 1.2.14 `install` / `update` now install the data package themselves — no extra Plane call needed for new rollouts.
- `POST /themes/sync` preflights the data root and returns `422 data_package_missing` instead of queueing a doomed task.
- `ThemeRolloutService` retries once on that code: `POST /themes/data-install` (audited as `theme.data_installed` / `theme.data_install_failed`), then re-sends the sync. Sites installed by an older agent recover without SSH. Both **Assign** (install + sync) and **Sync now** take this path.
- A sync that succeeds — including one that only succeeded after the repair — clears `last_error` and lifts the installation out of `error`, so a healed site stops reporting the fixed failure.
- If the repair itself fails (404 on a CMS older than 1.2.14, pruned clone), the **original** sync error is surfaced, not the repair's; the repair failure stays in the audit log. Fix by upgrading the CMS or re-running **Update to latest**.

`repo` on the wire stays `owner/repo` (`ControlPlaneAgentContract::installBody`). CMS **v1.2.7** accepts any github.com owner when `source=git`:

- `owner/name`
- `https://github.com/owner/name`
- `https://github.com/owner/name.git`

Owner/name = `[A-Za-z0-9_.-]+`, no `..`. ZIP / ssh / `file://` / `http://` / non-github hosts → 422 `unsupported_source`. Old `deamon-themes/premium-*` and `deamon-themes/deamon-theme-*` still valid. Repo **name** no longer must match `theme_id` (`theme.json` id still must). `default` cannot install/update (`system_theme`). Plane refuses `theme_id=default` before calling.

Plane remains the trust boundary: only connected/selected catalog rows are assigned. Coolify `GET /github-apps` is still a different object. ZIP stays out of Plane.

`clone_token` is a short-lived GitHub App **installation** token minted for the theme’s `theme_git_connections.installation_id`. A PAT (`github_settings.token` leftover or `theme_git_connections.token`) is **never** sent to the site. Token is not written to logs, audit `after`, or Blade.

CMS `auto_update` is always `false` in agent JSON. Opt-in lives on Plane `site_theme_installations`.

## Errors (safe messages only)

| HTTP | `error` |
|------|---------|
| 404 (no JSON) | Secret missing — routes not registered |
| 401 | `unauthorized` |
| 404 | `theme_not_found` |
| 422 | `unsupported_source` / `path_traversal` / `system_theme` / `validation_failed` |
| 502 | `git_failed` |

## Plane UI

Site → **Themes** tab: assign (confirm modal when activating), Update to latest, Sync now, Activate, auto-update toggle (default **off**).

Allowlist/public/private enforced in `ThemeVisibilityGate`.

## Out of scope

CMS controllers, ZIP in Plane, Coolify SSH/exec, copying PHP onto volumes.
