# Themes catalog — UI rollout note

Screen owners: Themes index, Theme show/detail. Site assignment UI stays on `resources/views/ops/sites/_themes.blade.php`.

## Implementation note

- Index is a scan table: name, theme ID, repository, default ref, visibility, last sync, install count. Rows use `data-href` → `ops.themes.show`. Long catalog copy lives in a `.site-hint`. `git-only` remains in the rendered HTML.
- Theme show reuses Site detail primitives (hero, sticky tabs, metrics, fact lists, next action, technical disclosure) with five true tabs: Overview, Installed / linked, Version / ref, Sync, Operations. Tab JS matches Site detail: hash restore, `preventDefault` (no scroll-on-tab), ArrowLeft / ArrowRight / Home / End.
- Visibility and default ref still POST the existing `ops.themes.update` contract (the unused field is a hidden input). Allowlist revoke keeps `data-confirm`. Assign / activate are not added here.
- Preview art and extra compat fields are omitted — the catalog model only has description, `minimum_deamon_version`, latest SHA/tag, and install `last_error`.

## Shared requests (other owners)

- **Extract detail tabs** — Theme show inlines the same tab script as Site detail, on `[data-ops-tabs]` / `[data-ops-panel]`. Move it to `public/js/ops-ui.js` and point Site detail at the same primitive. Do not keep a second copy per screen.
- **Rename Site primitives to `ops-*`** — Theme detail reuses `.site-hero`, `.site-section-nav`, `.site-hint`, `.site-metric-grid`, `.site-card`, `.site-fact-list`, `.site-next-action`, `.site-technical-card`. These are now used by two screens; aliases or a rename belong in orchestrator-owned `ops-ui.css`.
- **List toolbar auto-submit** — Themes index duplicates the Sites toolbar debounce (`[data-ops-list-toolbar]` + `[data-ops-list-filter]`). Lift that into `ops-ui.js` and stop shipping `ops-sites-list.js` as the only copy.
- **Catalog sync error on the row** — Index can only show `last_synced_at` / never. There is no per-theme catalog sync error column. If operators need failed GitHub fetches on the scan table, add a stored field or `withCount` of `status=error` installations in `ThemeController@index` (out of this agent’s edit set).

## Intentionally not requested

- No ZIP upload, marketplace, or CMS theme authoring.
- No edits to `ops.sites._themes`, `lang/*/ops.php`, layout, or shared CSS/JS.
- No invented preview URLs, theme stacks, or new backend columns.
- Native `<select>` stays in the HTML so the global Plane select enhancer can attach.
