# Sites index + create/edit — UI rollout note

Screen owners: Sites index, Site create, Site edit (configuration form only).

## Implementation note

- Sites table already used one GET toolbar (`q`, `channel`, `status`), `data-href` → `ops.sites.show`, and neighboring branch/version chips. This pass adds a primary-domain identity mark (`data-favicon-host` + letter fallback) and keeps bulk Compose / auto-deploy actions visible with existing `data-confirm` contracts.
- Create/edit forms are regrouped to Identity → Domain → Coolify placement → Git / repo branch → Advanced → Notes. Field names, Coolify `data-*` hooks, and `channel` storage are unchanged. Display copy is **Repo branch**.
- `edit.blade.php` no longer includes `_coolify-ops`. Save is configuration-only. Provision, auto-deploy, pin, and pack migrate stay on Site detail. The partial file is not deleted.

## Shared requests (other owners)

- **Compact identity mark** — `.site-identity-mark` is 42×42 (detail hero). Table rows need a ~24–28px variant (for example `.site-identity-mark.is-compact`) in `ops-ui.css`. Do not change the `[data-favicon-host]` JS contract.
- **Toolbar filter labels** — list filters are `aria-label` + `.ops-filter` only. If Fleet / Coolify / Themes want visible labels, add that to the shared `ops-list-toolbar` primitive rather than per-page markup.
- **Form section kicker** — Site detail uses `.site-section-kicker`. Create/edit use `.ops-form-section` + `h2` only. If other forms need kickers, add a global `.ops-form-kicker`, not `site-*` classes.

## Worktree test note

This worktree’s `vendor` is a junction to `deamon-plane/vendor`. PHPUnit must set `APP_BASE_PATH` to this worktree, or Laravel boots the main checkout and renders stale views/lang. Also create `storage/framework/views` (and cache/sessions) in the worktree before the first view render.

## Intentionally not requested

- No backend rename of `channel`.
- No edits to `ops-ui.js`, `ops-ui.css`, `ops.css`, or Site detail / `_coolify-ops`.
- Native `<select>` stays in the HTML so the global Plane select enhancer can attach.
