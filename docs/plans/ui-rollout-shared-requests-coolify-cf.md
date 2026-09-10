# Shared UI requests — Coolify / Cloudflare rollout

Worktree: `ui/rollout-coolify-cloudflare`. Foundation files (`layouts/ops.blade.php`, `public/css/ops.css`, `public/css/ops-ui.css`, `public/js/ops-ui.js`, `lang/*/ops.php`) were out of scope.

## Requests for the foundation / shared-primitive owner

1. **Extract resource tabs into `ops-ui.js`.** Coolify connection/inventory and Cloudflare account/zone/defaults copy the Site detail tab script (`[data-site-tabs]`, hash, Arrow/Home/End, `aria-selected`). A single `ops-resource-tabs` primitive would remove the duplication.

2. **Generic `ops-*` aliases for Site primitives.** These screens reuse `site-hint`, `site-section-nav`, `site-hero`, `site-metric-*`, `site-card`, `site-next-action`, and `site-technical-card` so shared CSS applies. Prefer `ops-hint` / `ops-section-nav` / `ops-resource-*` names (keep `site-*` as aliases).

3. **Persist Coolify last test.** `POST /coolify/{connection}/test` only flashes. Overview health uses `last_synced_at` (sync) plus enabled/token. A `last_tested_at` (and optional payload) would match Cloudflare `last_probe_at` without changing allowlist semantics.

4. **Pass overview aggregates from the controller.** Connection show currently counts linked sites in the view (`sites()` plus null `coolify_connection_id` when default). Prefer `withCount` / an explicit view model so Blade does not query.

5. **DNS record show route (optional).** Deamon DNS defaults and zone records stay inline editors: there is no record detail route, so rows are not `data-href` targets. If the table→detail rule must apply here, add a read-only record show and keep edit explicit.

## Not requested (intentionally unchanged)

- Coolify / Cloudflare API payloads, allowlists, and DNS template records
- Token fields remain `type="password"` with blank value (keep-on-save)
- `ops-coolify-form.js` still reads native `name="default_*"` selects and `data-coolify-*`
- Confirm modal attributes on disconnect / remove account / delete zone / delete DNS
