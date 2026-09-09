# Theme agent client

Task 12. Plane asks each CMS instance to git-install a catalog theme. CMS Task 11 (**Deamon v1.2.5**) owns the installer in `codron-co/deamon`. This repo only has the signed HTTP client.

SoT: CMS `docs/modules/control-plane-agent.md`. Constants: `ControlPlaneAgentContract`.

## HMAC (same as health)

Canonical string: `{timestamp}.{nonce}.{rawBody}`. GET body is empty `""`. POST signs the **compact JSON bytes** sent (`ControlPlaneAgentContract::encodeJson` — no pretty-print). HMAC-SHA256 lowercase hex, no `sha256=` prefix.

Headers: `X-Deamon-Timestamp` / `X-Deamon-Nonce` / `X-Deamon-Signature`. Never `X-Control-Plane-*`.

## Endpoints (CMS v1.2.5)

Base: `/internal/control/v1`

| Method | Path | Body |
|--------|------|------|
| GET | `/health` | empty |
| GET | `/themes` | empty |
| POST | `/themes/install` | `{theme_id, repo, ref, source:"git", sha?, clone_token?}` |
| POST | `/themes/update` | same as install |
| POST | `/themes/activate` | `{theme_id}` |
| POST | `/themes/sync` | `{action:sync_all\|capability, mode:merge\|reset, theme_id?, capability_id?, preserve?}` |

Install does **not** activate or data-sync. Assign flow is three separate calls: install → (optional) activate → (optional) sync.

`repo` allow on CMS: `deamon-themes/premium-{id}`, `deamon-themes/deamon-theme-{id}`, or `https://github.com/deamon-themes/….git`. `source` omit or `"git"` only. ZIP/ssh/http → CMS 422 `unsupported_source`. `default` cannot install/update (`system_theme`). Plane refuses `theme_id=default` before calling.

`clone_token` is a short-lived GitHub App installation token when App credentials exist. A PAT is **never** sent to the site. Token is not written to logs, audit `after`, or Blade.

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
