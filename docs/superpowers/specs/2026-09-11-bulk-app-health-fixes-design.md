# Bulk App health fixes (Sites list)

**Date:** 2026-09-11  
**Status:** draft — awaiting review  
**Repo:** Deamon Plane only  
**Order:** Wave 1 of 3 (before deploy-status sync and domain reconcile)

## Context

Site detail already exposes per-issue App health fixes via `POST /sites/{site}/app-health/fix` (`sync_env`, `migrate_compose`, `inject_secret`, `redeploy`, `check_health`). Operators confirmed those single-site buttons succeed and clear issues.

The Sites list (`GET /sites`) only shows an App chip that copies the issue text. There is no row-level fix menu and no header action to repair the same issue category across the fleet. Bulk Coolify actions already exist (`sites.bulk_*` via `OpsJobRunner` + jobs widget); App health has no bulk counterpart.

## Goals

- From a site row, open an actions menu and run **one** fix or **all applicable** fixes for that site’s current App issues.
- From the Sites page header, open a dropdown and run:
  - one fix **category** across all sites that currently report that category, or
  - **all fixable** App issues across all sites.
- Reuse `SiteAppHealthFixer` (no second write path for env / secret / pack / redeploy).
- Queue long fleet work through `ops_background_jobs` + jobs widget (same pattern as `sites.bulk_deploy`).
- Keep i18n (`tr` / `en`), Plane menus/selects, and write-permission gates.

## Non-goals

- Changing Coolify env catalog semantics or inventing a free-form env editor.
- Fixing stale `deploy_failed` cache (Wave 2).
- Domain inventory / Coolify domain rebind (Wave 3).
- Auto-running fixes on a schedule.
- Viewer role write access.

## Approaches considered

| Approach | Pros | Cons |
|----------|------|------|
| A. Extend existing checkbox bulk bar with App fix buttons | Minimal new UI | Wrong UX: operators want “all sites with this error”, not “checked rows”; category without selection is awkward |
| B. **Row kebab + header “Fix App issues” menu** (recommended) | Matches request; category fleet + per-site; keeps checkbox bar for deploy/branch | New menus + bulk job types |
| C. Only detail page “Fix all” | Tiny | Misses list/header fleet need |

**Recommendation:** B.

## Design

### Issue → fix mapping (unchanged)

| Issue code | Fix key | Notes |
|------------|---------|--------|
| `missing_env` / `wrong_env` | `sync_env` | Deduped once per site even if many keys |
| `dockerfile_pack` / `wrong_compose_file` | `migrate_compose` | |
| `missing_agent_secret` / Coolify missing `CONTROL_PLANE_AGENT_SECRET` | `inject_secret` | |
| `deploy_failed` | `redeploy` | Confirm; may start Coolify deploy |
| `agent_unhealthy` | `check_health` | |
| `missing_app` / `coolify_unreachable` | *(none)* | Skip; surface in job result |

When applying **all** fixes for a site, run unique fix keys in this order:

1. `migrate_compose`
2. `sync_env`
3. `inject_secret`
4. `redeploy`
5. `check_health`

Skip fixes the site does not need (from current display report / local+cached issues). After each site’s chosen fixes, call `SiteAppHealthInspector::inspect` once (fixer already re-inspects after a single fix; bulk runner should avoid double-inspect storms by batching).

### Data source for “who has this issue”

Use `SiteAppHealthReport::forDisplay($site)` (cached payload or local signals). Do **not** live-hit Coolify for every site before enqueueing. After fixes, each site’s inspect refreshes cache.

Header category counts on the list page: compute from the paginated+filtered collection **and** a cheap fleet scan of `last_app_health_payload` + local-only codes for sites not yet inspected. Exact rule:

- Category present if any issue’s `fix` equals the category, **or** (for display grouping) issue `code` maps to that fix.
- Header menu lists only categories with count ≥ 1 among **all** non-soft-deleted sites the operator can update (not only the current page). Use a single query selecting `id`, `coolify_app_uuid`, `agent_secret_encrypted`, `notes`, `last_app_health_payload`, plus `latestDeployment.status` when needed for `deploy_failed`.

### UI — Sites list

**Row (`ops-actions-col`):**

- Keep View / Edit.
- Add icon button (wrench / sparkles) `aria-haspopup="menu"` → Plane menu:
  - Section: listed issues (message, disabled if no fix).
  - Actions: one menuitem per unique fix for that site.
  - Separator + **Fix all for this site**.
- Empty / healthy: menu shows “No App issues” (disabled).
- Destructive-ish: `redeploy` and `inject_secret` keep confirm dialogs (existing `data-confirm` pattern).

**Header (`@section('actions')`):**

- New secondary control **Fix App issues** (dropdown), only when `$canWrite`.
- Menu structure:
  - **By category** — one item per fix key with count, e.g. `Fix env (12)`.
  - Separator.
  - **Fix all sites** — every site with any fixable issue; runs per-site fix-all order.
- Confirm copy must state category + approximate site count.

### API / jobs

| Route | Behavior |
|-------|----------|
| `POST /sites/{site}/app-health/fix` | Unchanged for single fix. Add optional `fixes[]` or `fix=all` to run ordered unique fixes for one site (JSON + redirect). |
| `POST /sites/bulk/app-health-fix` | Body: `fix` = one of the five keys **or** `all`; optional `site_ids[]` / `all=1` like other bulk endpoints. Queues `sites.bulk_app_health_fix`. |

`OpsJobRunner` handles `sites.bulk_app_health_fix`:

- Resolve sites (ids or all + same filters as other bulk when provided).
- Filter to sites that need the requested fix(es).
- Per site: run fixer; catch `SiteAppHealthException` → record failure for that site; continue.
- Result payload: `{ ok, failed, sites: { id: { health view } } }` so list chips can update like live sync.

Permissions: `update` on Site (same as detail fix). Viewer → 403.

### Frontend

- Extend `ops-app-health.js` (or small `ops-app-health-bulk.js`) for row/header menus: CSRF POST JSON, pending state, toast/flash from JSON, patch App chips via existing list updater.
- Progressive enhancement: forms with hidden `fix` still work without JS where practical; menus may require the existing ops menu JS.

### Tests (`Http::fake`)

- Single site `fix=all` runs ordered unique fixes and returns refreshed health JSON.
- Bulk category only targets sites with that fix; skips healthy.
- Bulk `all` queues job and runner applies fixes.
- Viewer forbidden.
- List renders row fix control and header menu when write + issues exist.

### Docs

- Update `docs/modules/ops-sites.md` App health section.
- Progress ledger entry for Wave 1.

## Acceptance

- Operator can fix one or all issues for a site from the list row without opening detail.
- Operator can fix one category or all fixable issues across the fleet from the header.
- Jobs widget shows progress for fleet runs.
- Secrets never appear in UI or job results.
- Existing detail fix buttons still work.

## Out of wave handoff

Wave 2 must refresh App health (or merge local deploy status) after deployment sync so bulk `redeploy` / `deploy_failed` counts stay honest.
