# Site publish state (yayın durumu) + Sites list preferences — design

Date: 2026-09-12 · Repos: `codron-co/deamon-plane` (Plane), `codron-co/deamon` (CMS)

Operators asked for three things on the Sites screen: see whether a site is actually
**yayında** (live to the public) or still **taslak**, change that from Plane, and stop
scrolling a fixed nine-column table — pick the columns, click the headers to sort, and
have both choices survive a logout.

The first ask crosses the Plane↔CMS boundary, which is why this spec exists.

## 1. There is already exactly one publish model — it lives in the CMS

`sites.status` in the **CMS** is the publish state:

- values `draft` | `published` (`sites` migration `2026_05_25_000001`, `status` column, default `draft`)
- `Site::isPublished()`, and `first_published_at` is stamped on the first `published` save
- `ShowMaintenanceWhenUnpublished` (global `web` middleware) serves the
  “Yakında Açılıyoruz!” maintenance page while the site is `draft`

So publish state is not cosmetic: it is what decides whether a visitor sees the storefront
or the coming-soon page. Turkish labels already shipped in the CMS admin panel:
**Yayında** / **Taslak**.

Plane's own `sites.status` (`App\Enums\SiteStatus`: `draft`, `provisioning`, `active`,
`deploying`, `stopped`, `error`, `archived`) is the **infrastructure lifecycle** — has
Coolify built this app, is it running, did it fail. A Plane-`active` site can perfectly
well be CMS-`draft`, and that is the exact blind spot operators are complaining about.

**Decision: Plane does not get a second publish flag.** The CMS stays the source of truth.
Plane mirrors it for display/sort and mutates it only through the signed agent.

The two are rendered as two separate columns with distinct headers so they can never be
misread as one thing:

| Column | Turkish header | Source | Means |
|--------|----------------|--------|-------|
| `publish` | **Yayın** | CMS `sites.status` (via agent) | Visitors see the site / they see the maintenance page |
| `status` | **Durum** | Plane `sites.status` | Coolify/provisioning lifecycle |

### Read path already exists

`GET /internal/control/v1/health` has returned `site_status` since CMS 1.2.x
(`ControlPlaneHealthReporter::payload()`), and Plane already allowlists it into
`sites.last_health_payload.site_status` (`SiteHealthChecker::sanitizedSummary`). Nothing
in the UI surfaced it. That is the whole read path — it just needs a column.

### Write path is the one new CMS endpoint

The CMS can already toggle publish state, but only from its own admin panel
(`PATCH /admin/site/publish-status` → `Admin\SettingsController::togglePublishStatus`),
which also fires a `PlatformSoftwareMailer` notification
(`SITE_PUBLISHED` / `SITE_UNPUBLISHED`). There is no signed-agent equivalent, so Plane
cannot change it today.

Add one endpoint to the existing agent surface:

```
POST /internal/control/v1/site/status      body {"status":"published"|"draft"}
                                       → 200 {"ok":true,"site_status":"published","label":"Yayında"}
                                       → 422 {"message":"..."} on a bad value
```

It reuses `EnsureControlPlaneSignature` (same HMAC, same `X-Deamon-*` headers, compact
JSON body) — no new auth surface. The publish transition + notification move into
`App\Services\Site\SitePublishState`, which both the admin panel and the agent controller
call, so a Plane-driven publish sends the same customer mail as an admin-driven one and
cannot drift. CMS version → **1.2.16**.

## 2. Plane mirror columns

`sites.last_health_payload` is JSON, so sorting and filtering on it means JSON paths in
`ORDER BY`. Plane already solved this once for the live probe (`last_live_http_status` +
`last_live_checked_at` are mirror columns of a remote read), so follow that precedent:

```
sites.cms_site_status      string(32) nullable index   -- 'published' | 'draft' | null (never polled)
sites.cms_site_status_at   timestamp  nullable         -- when the mirror was last confirmed
```

`App\Enums\CmsPublishStatus` (`published`, `draft`) carries `label()` and `tone()`.

Mirror write rules — the mirror is a cache, never an authority:

- a health poll writes it from `site_status` (and clears it to `null` if the CMS stops reporting)
- a successful `POST /site/status` writes the value the CMS echoed back, not the value we asked for
- a **failed** publish call leaves the mirror untouched and surfaces the error

`null` renders as a deliberate **Bilinmiyor** chip (skill rule 11: unknown states are
explicit), not as “taslak”.

## 3. Changing publish state from Plane

| Route | Name | Who |
|-------|------|-----|
| `POST /sites/{site}/publish-status` | `ops.sites.publish-status` | operator, super_admin |
| `POST /sites/bulk/publish-status` | `ops.sites.bulk.publish-status` | operator, super_admin |

`SitePublishStateUpdater` (Plane) calls the agent, mirrors the confirmed value, and writes
an audit row (`site.published` / `site.unpublished`). Sites with no `agent_secret` are
rejected with the existing `needs_secret` copy rather than silently skipped.

- **Site detail** gets a `Yayın` card beside agent health: current chip + one submit button
  (**Yayına al** / **Yayından kaldır**), both behind `PlaneConfirm`. Unpublish is
  `data-confirm-danger="true"` — it takes a customer site off the air.
- **Site edit** does *not* get this control. Edit writes desired state only
  (`ops-sites.md`); publish is a live remote call, so it belongs with the other remote ops
  on the detail page.
- **Bulk** follows the existing list pattern exactly: JSON callers queue
  `sites.bulk_publish_status` on `ops_background_jobs` and get the jobs widget;
  HTML posts fan out inline and flash through `BulkResultSummary`, so the ok/hata/atlandı
  accounting rules in `ops-sites.md` apply unchanged.

## 4. Sites list: operator-chosen columns, persisted per user

Preferences live in the DB, not `localStorage` — an operator switching machines keeps their
layout. The existing prefs pattern is typed columns on `users` (`locale`, `appearance`)
driven by `PreferencesController`; a per-list column set is open-ended, so it gets one JSON
column instead of a migration per list:

```
users.list_preferences  json nullable
```

```json
{ "sites": { "columns": ["site", "domain", "publish", "status"],
             "sort": { "key": "publish", "dir": "desc" } } }
```

`App\Support\Lists\SiteListColumns` is the catalog; `App\Support\Lists\SiteListView`
resolves a request + stored prefs into “which columns, which sort”. Unknown keys are
dropped and an empty selection falls back to defaults, so a stale payload can never render
a table with no columns.

| Key | Header | Default | Sort column |
|-----|--------|---------|-------------|
| `site` | Site | ✔ **locked** | `name` |
| `domain` | Domain | ✔ | `primary_domain` |
| `repo_branch` | Repo dalı | ✔ | `channel` |
| `publish` | Yayın | ✔ | `cms_site_status` |
| `status` | Durum | ✔ | `status` |
| `app` | App | ✔ | — |
| `live` | Canlı | ✔ | `last_live_http_status` |
| `theme` | Tema | ✔ | — |
| `health` | Son sağlık | ✗ | `last_health_at` |
| `updated` | Güncellendi | ✗ | `updated_at` |

`site` is locked visible: it carries the row's name, slug and identity mark, and hiding it
would leave a clickable row with nothing to click. `app` and `theme` are computed at render
time from payloads/relations, so they are shown but not sortable — an unsortable header is
plain text, never a dead link. The checkbox and row-action cells are structural, not
preferences.

## 5. Sorting

Headers are real `<a href>` links carrying `?sort={key}&dir={asc|desc}` through the
existing `withQueryString()` filter chain, so sorting works with JS off and a sorted view
is a shareable URL. `aria-sort` is set on the active header, and direction is shown with a
caret **plus** the accessible name — not colour (skill rule 16).

Every sort appends `name asc` as a tiebreaker so pagination can't repeat or drop a row.

Sort is persisted the same way columns are: when a request resolves to a different sort than
the stored one, the new sort is written back to `users.list_preferences`. Visiting `/sites`
with no query string then restores the operator's last sort instead of snapping back to
`name asc`. Default for a user who has never sorted stays `site` asc, matching today's
`orderBy('name')`.

`POST /sites/list-preferences` (`ops.sites.list-preferences`) saves a column set;
`DELETE` resets to defaults. Available to every ops role including viewer — a viewer
choosing their own columns is not a write to fleet state.

## 6. Out of scope

- Scheduling a publish (`published_at` in the future) — CMS has no such field
- Publishing from the Fleet dashboard or a Coolify-level bulk sweep
- Column ordering/drag-reorder or per-column width; the catalog order is fixed in v1
- Sorting by `app` health or theme, which are not single SQL columns
