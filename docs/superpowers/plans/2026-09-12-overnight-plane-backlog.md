# Overnight Plane backlog — operator productivity

**Date:** 2026-09-12
**Repo:** Deamon Plane only (control plane). No CMS work, no marketplace, no ZIP upload.
**Status:** backlog — nothing here is committed. Implementers pick items in wave order and
append to [2026-09-13-overnight-morning-report.md](2026-09-13-overnight-morning-report.md).

Every item below was checked against the code, not invented. File paths are the ones a
worker will actually open. Effort is **S** (< ~1h), **M** (a focused slice), **L** (needs a
design note first).

## Ground rules for this cycle

- No `git commit`, no PR, no push. Leave the worktree dirty; the morning report is the handoff.
- Turkish UI copy lives in `lang/tr/**`; never hard-code a new string in Blade (skill rule 5).
- Lists stay progressive-enhancement: every control remains a plain link or GET form
  ([docs/modules/ops-list-async.md](../../modules/ops-list-async.md)).
- Anything that only *asks* Coolify to work must report **Tetiklendi**, never `Bitti`
  (`OpsBackgroundJob::triggersRemoteWork()`).
- Touch `docs/` + the ledger in the same slice as the code. Code-only diffs are unfinished.

## Already in flight (check before you start)

At the time this backlog was written the worktree was **dirty with someone else's slice**: an
empty-state / filter-chip pass on Sites. It adds `ops.partials.filter-chips`, an
`activeListFilters()` helper and a `totalSites` view variable in `SiteController@index`, a
distinct filtered-empty panel in `ops/sites/_region.blade.php`, and matching `lang/{tr,en}` and
`ops-ui.css` additions.

Two consequences:

- **P2-18** is partly done for Sites. Do not redo it; extend it to Domains, Coolify, mail servers
  and Themes, reusing `ops.partials.filter-chips` rather than a second chip implementation.
- **P0-1** should reuse that work instead of adding a parallel count. Note that `totalSites`
  falls back to the unfiltered fleet count when the filtered result is empty, so it is **not**
  the selection scope — read `$sites->total()` for that.

Run `git status` before picking an item; another worker may have moved since.

## Wave order

| Wave | Items | Theme |
|------|-------|-------|
| 1 | P0-1 … P0-5 | Stop the panel lying or costing money |
| 2 | P1-6 … P1-11 | Triage speed: find it, see how fresh it is, act in bulk |
| 3 | P1-12 … P1-15 | Fleet-wide health surfaces and history |
| 4 | P2-16 … P2-19 | Consistency and discoverability polish |

## Status board (updated 2026-09-14 morning finalize — still uncommitted)

Nothing below is committed; "done" means code + tests + docs + ledger are in the dirty worktree.
Details per item are in
[2026-09-13-overnight-morning-report.md](2026-09-13-overnight-morning-report.md).

| Item | State | Note |
|------|-------|------|
| P0-1 | done | bulk bar publishes its scope; every confirm carries the count |
| P0-2 | done | fleet scan off the keystroke path, 60s cache with a freshness note |
| P0-3 | done | `sırada 4 / 22` per host, `1 derleniyor · 22 kuyrukta` header |
| P0-4 | done | hidden tabs stop polling; 1.5s → 5s → 10s tiers; one Coolify read per window |
| P0-5 | done | failed-deploy KPI windowed and per-site; both loose ends closed by P1-7 |
| **NEW P0** · waiting ≠ failure | **done (wave 2)** | the sweep no longer queues behind its own builds; third `sıra bekliyor` bucket |
| P1-7 | **done** | `deploy=failed` plus ADR-10 leftovers: persisted verdict + `/sites?health=unhealthy` + `/sites?app=issues`. Fleet unhealthy **Tümünü gör** / `+N` and the Fix App issues menu now drill down. SQL only — no fleet scan |
| P1-8 | done | fleet search over aliases / Coolify uuid; `SiteFleetSearchTest` green in the full-suite run (673 passed, 0 failed) |
| P2-19 | done | Posta nav group + platform-mail state chip |
| P1-9 | **done** | `/` + `g` chords + `?` overlay + `Ctrl/⌘+K` palette; `GET /palette` reuses the list scopes |
| P1-11 | done | Domains bulk bind; `all=1` adopts the P0-1 summary; one Coolify PATCH per site; 429 → `atlandı` |
| P2-18 | **done** | Coolify inventory was the last leftover; Sites / Domains / Themes / mail already had chips |
| P1-10 | done | relative age + visible **Eski**; `updated` column is relative and never stale |
| P1-14 | **done** | mail, Coolify inventory, and fleet attention toolbar (`q` + `kind`; not a second Sites list) |
| P1-15 | **done** | pin is a typed SHA; auto-deploy is Aç / Kapat; `enabled` required |
| P2-17 | **done** | Sites + Coolify / mail / Themes + Cloudflare delete confirms carry title + label + explicit danger |
| P1-13 | **done (tick 4)** | `/activity` merges jobs, deploys and audits; Viewer can read other operators' jobs; payloads redacted |
| P1-12 | **done** | fleet card + `?agent=` filter + bulk inject that never rotates |
| P1-6 | **done** | named filter/sort/column presets in DB prefs; default 302s bare `/sites`; chips are GET links |
| everything else | mixed | ADR-11 **fifth slice landed** (`node --test tests/js/*.js`: toolbar, backoff, interpolate, typing/confirm, `pageIsHidden`, list `fetch` / `replaceState`). `PlaneUI.refresh()` still waits for jsdom. No Playwright |

**P1-7 leftovers — landed.** `health=unhealthy` and `app=issues` are SQL over
`SiteFilterVerdict` columns written on the existing inspect / health save.
See [ADR-10](../../decisions/adr-10-health-app-filter-verdict.md) and
`HealthAppFilterTest`. Do not add a fleet scan.

---

## P0 — do first

### P0-1 · Bulk bar must say what it will actually touch · M

**Problem.** The header checkbox posts `all=1`, which the server reads as *every site matching
the current filters* (`SiteController` bulk actions + `Site::scopeMatchingListFilters`), while
the operator only sees the 25 checkboxes on the page tick. Nothing anywhere shows a count:
`setupBulkSelection` in `public/js/ops-ui.js` only toggles `actions.hidden`, and the confirm
strings say a flat `Seçili siteler` (`lang/tr/site_ops.php` `bulk.confirm_*`). Single-site
confirms use `:name`, so bulk is the only place the operator is asked to approve an unnamed,
uncounted set — including **Hard delete**, which sits in that same button row
(`resources/views/ops/sites/_region.blade.php`).

**Proposed UX.** A selection summary line above the bulk actions, driven by the real numbers:

- page selection → `12 site seçildi` with a `Filtreye uyan 214 sitenin tümünü seç` link.
- `all=1` → `Filtreye uyan 214 sitenin tümü seçildi` plus `Sadece bu sayfayı seç`.
- every bulk confirm interpolates `:count` (`214 site yeniden derlenecek …`), reusing the
  existing `data-confirm-template` mechanism already used for the branch target.

**Files.** `resources/views/ops/sites/_region.blade.php`, `public/js/ops-ui.js`
(`setupBulkSelection`), `app/Http/Controllers/Ops/SiteController.php` (`index` passes
`$sites->total()` — see the in-flight note about `totalSites`), `lang/{tr,en}/site_ops.php`,
`lang/{tr,en}/sites.php`.

**Acceptance.**
- With 3 rows ticked the bar reads `3 site seçildi`; the confirm body contains `3`.
- With the header box ticked the bar reads the filtered total, not `25`.
- Changing a filter re-renders the region and resets the summary to `0` (no stale count survives
  an `ops-list.js` swap — verify via `PlaneUI.refresh()`).
- `sites.danger.hard_confirm_bulk` names the count and no longer says only `Seçili siteler`.
- Feature test asserts the bulk POST with `all=1` and a filter affects exactly the filtered set
  and that the rendered summary matches `$sites->total()`.

### P0-2 · Typing in the Sites search must not scan the whole fleet · S

**Problem.** `SiteController@index` always computes
`app(SiteAppHealthFixer::class)->categoryCounts()` for write users. That method
`chunkById(100)`s the **entire** `sites` table with `latestDeployment` eager-loaded and runs
`neededFixes()` per site. Since `ops-list.js` re-requests the same URL on every keystroke,
filter change, sort and page, each character typed in the search box triggers a full-fleet
app-health scan — and the counts are only consumed by the page header, which a
`X-Ops-List-Fragment: region` response does not even render.

**Proposed UX.** No visible change on the happy path; the header menu keeps its counts but they
come from a short-lived cache with an explicit freshness note, and fragment requests skip the
work entirely.

**Files.** `app/Http/Controllers/Ops/SiteController.php`,
`app/Services/Sites/SiteAppHealthFixer.php` (cache `categoryCounts`, add a `forget` on fix
completion), `app/Support/Lists/ListFragment.php` (expose "is this a region request"),
`resources/views/ops/sites/index.blade.php`.

**Acceptance.**
- A region request (`X-Ops-List-Fragment: region`) executes no full-table chunk scan — assert
  with a query count or a spy on `SiteAppHealthFixer`.
- A full page render still shows correct counts; a completed `sites.bulk_app_health_fix`
  invalidates the cache so the menu does not offer fixes that are already applied.
- Search-as-you-type on a 200-site fixture stays under the current region latency budget.

### P0-3 · Deploy queue tells the operator where they are in line · M

**Problem.** `config/ops.php` caps builds at `max_concurrent_per_server = 1`, so a 23-site
bulk deploy is one build plus 22 waiting. The widget row for those says only
`manuel · kuyrukta` — no position, no depth, no idea whether "queued" means 2 minutes or an
hour. `OpsCoolifyDeployQueue` knows the slot state and `Deployment::toWidget()` already carries
`kind_label` / `status_label` / `detail`, so the information exists and is thrown away.

**Proposed UX.** Queued rows read `Coolify deploy · beyazlar` / `kuyrukta · sırada 4 / 22`, and
the widget header summarises `1 derleniyor · 22 kuyrukta`. The running row keeps its elapsed
timer. Force start stays where it is (it already repoints the row rather than cancelling).

**Files.** `app/Services/Ops/OpsCoolifyDeployQueue.php` (position + depth per connection),
`app/Models/Deployment.php` (`toWidget`), `public/js/ops-jobs.js` (render, patch in place — do
not reintroduce `replaceChildren`), `lang/{tr,en}/ops.php` (`jobs.*`).

**Acceptance.**
- Two queued deployments on one connection report positions `1` and `2` with the same depth.
- Position recomputes after a cancel or a force start without a page reload.
- No English string reaches the widget; `DeploymentPollWidgetTest` extended.

### P0-4 · Jobs widget stops hammering Coolify from a background tab · M

**Problem.** `public/js/ops-jobs.js` polls `GET /jobs` on a fixed `POLL_MS = 1500` while any row
is active, with no `visibilitychange` handling. Each poll runs `OpsJobController@index`, which
calls `OpsCoolifyDeployQueue::widgetRows()` — a full `sites` read plus
`listRunningDeployments()` **per Coolify connection** — then `refreshOpen()` and
`kickStaleDeploymentPolls()`. A single forgotten tab during a 10-minute build is ~400 Coolify
requests; two tabs double it. This is the same pressure that produced the
`Too Many Attempts.` incident ([2026-09-11-coolify-throttle-cure-design.md](../specs/2026-09-11-coolify-throttle-cure-design.md)).

**Proposed UX.** Invisible when things are quick; long waits cost less. Pause polling while the
document is hidden and re-poll once on focus; grow the interval geometrically (1.5s → 5s → 10s,
capped) the longer a row stays in the same state; cache `widgetRows()` per connection for a few
seconds so N tabs cost one Coolify read.

**Files.** `public/js/ops-jobs.js`, `app/Http/Controllers/Ops/OpsJobController.php`,
`app/Services/Ops/OpsCoolifyDeployQueue.php`, `config/ops.php`.

**Acceptance.**
- Hidden tab issues no `/jobs` request; focus triggers exactly one immediate poll.
- A state change (queued → running) resets the interval to the fast tier.
- Two browser sessions polling concurrently produce one `listRunningDeployments()` call per
  cache window, asserted with `Http::fake`.
- Cancel / force-start / dismiss stay clickable throughout (regression guard from the
  2026-09-12 fix).

### P0-5 · Fleet KPIs stop counting history as if it were now · S

**Problem.** `FleetDashboardKpis::snapshot()` returns
`Deployment::where('status', Failed)->count()` with **no time window**, so the `failed_deploys`
card only ever grows and still includes rows from the throttle storm. `unhealthySites()` does a
bare `->get()` of the whole table and filters in PHP, and `attention.blade.php` renders every
one of them with no cap. Meanwhile `recentFailedDeploys()` limits to 8, so the card number and
the list below it disagree.

**Proposed UX.** `Son 24 saatte başarısız deploy` with the window in the label, counted by
distinct site, and a `Tümünü gör` link. The attention list caps at 8 with
`+14 site daha` pointing at the filtered list.

**Files.** `app/Services/Fleet/FleetDashboardKpis.php`,
`resources/views/ops/dashboard/{kpis,attention}.blade.php`, `lang/{tr,en}/fleet.php`.

**Acceptance.**
- A failed deployment older than the window is excluded from the card.
- Card count and attention list agree on the same query.
- Dashboard render issues a bounded number of queries on a 200-site fixture (no unbounded
  `->get()` of all columns).

---

## P1 — high value, same night

### P1-6 · Saved site views · L

**Problem.** `SiteListView` / `SiteListColumns` already persist columns and last sort per user in
`users.list_preferences`, but filters are URL-only. The daily triage sets
(`?status=error`, `?publish=unknown`, `?channel=beta`) must be retyped or bookmarked, and a
bookmark cannot carry a column layout.

**Proposed UX.** A chip row under the toolbar: built-in views (`Tümü`, `Sorunlu`,
`Yayında değil`, `Dockerfile kalanları`) plus up to five operator-saved views. Saving captures
filters + columns + sort; one view can be marked default for `/sites` with no query string.

**Files.** `app/Support/Lists/{SiteListView,SiteListColumns}.php`, new
`app/Support/Lists/SiteSavedViews.php`, `app/Http/Controllers/Ops/SiteListPreferencesController.php`,
`resources/views/ops/sites/{index,_saved-views}.blade.php`, `public/js/ops-list.js`,
`lang/{tr,en}/sites.php`.

**Acceptance.**
- Saving a view then visiting `/sites` bare applies it, including hidden columns.
- A saved view referencing a filter value that no longer exists (removed channel) falls back
  cleanly, the way `SiteListView` already degrades a sort on a hidden column.
- Views are per user and survive a region swap; `SiteListPreferencesTest` extended.
- Works with JS off: chips are plain links carrying the query string.

### P1-7 · KPI and attention cards drill into a filtered list · M

**Problem.** `kpis.blade.php` renders five numbers with no links; the only way from
"3 unhealthy" to the sites is to guess a filter. The list has no filter for health, app issues
or failed deploys, so the drill-down target does not exist yet.

**Proposed UX.** Every KPI value becomes a link to `/sites` with a matching filter. Adds three
list filters: `health=unhealthy`, `app=issues`, `deploy=failed`.

**Files.** `app/Models/Site.php` (`scopeMatchingListFilters` + scopes),
`app/Http/Controllers/Ops/SiteController.php`, `resources/views/ops/sites/index.blade.php`,
`resources/views/ops/dashboard/{kpis,attention}.blade.php`, `lang/{tr,en}/sites.php`.

**Acceptance.**
- Clicking the unhealthy KPI lands on a list whose row count equals the KPI number.
- New filters are validated against an allowlist (same pattern as `channel` / `status`) and
  appear in the `filtersActive` clear-filters logic and in the bulk `filter_*` hidden inputs.
- Unknown filter values are ignored, not 500.

### P1-8 · Fleet search finds aliases and Coolify uuids · S

**Problem.** `Site::scopeMatchingListFilters` searches `name`, `slug`, `primary_domain` only.
An operator pasting a `www.` host, an alias from `site_domains`, or the Coolify app uuid they
just copied out of Coolify gets "no results" for a site that exists. `site_domains` is already
a real table with the `unbound` registry behind it.

**Proposed UX.** Same search box, wider reach: alias/`www`/temporary hosts via a
`whereHas('domains')`, plus an exact match on `coolify_app_uuid`. A row matched by an alias
shows which host matched under the site name.

**Files.** `app/Models/Site.php`, `resources/views/ops/sites/_cell.blade.php`,
`lang/{tr,en}/sites.php`.

**Acceptance.**
- Searching an alias host returns its site exactly once (no duplicate rows from the join).
- Searching a full Coolify app uuid returns exactly that site.
- Search term escaping still holds (`addcslashes` on `%_\`), asserted with a `%` term.

### P1-9 · Keyboard shortcuts and quick jump · M

**Problem.** There are no global shortcuts. Every `keydown` listener in `public/js/ops-ui.js`
belongs to one component (action menus, tabs, hints, modal). Moving from a site to another site
is always sidebar → Sites → search → click.

**Proposed UX.** Three additions, all documented in one `?` overlay:

- `/` focuses the current list search (no-op where there is no toolbar).
- `Ctrl/⌘+K` opens a quick-jump palette over sites, domains, themes and top-level pages.
- `g` then `s` / `d` / `t` / `f` jumps to Sites / Domains / Themes / Fleet.

Never swallow a keystroke while focus is in an input, and respect the confirm modal's focus trap.

**Files.** new `public/js/ops-palette.js`, `resources/views/layouts/ops.blade.php`, a small
JSON search endpoint (reuse the P1-8 scopes), `lang/{tr,en}/ops.php`.

**Acceptance.**
- Shortcuts do nothing while typing in a field or while the confirm modal is open.
- The palette is reachable and operable by keyboard only, has an accessible name, and closes on
  Escape and outside click (skill rule 16).
- The endpoint is authorized like the lists it searches — a Viewer cannot palette-jump to
  something they cannot open.

### P1-10 · Stale-data badges instead of bare timestamps · M

**Problem.** Freshness is either hidden or raw. In `_cell.blade.php` the `health` column prints
`Y-m-d H:i` or `Hiç`, `live` hides `last_live_checked_at` in a `title`, and `publish` hides
`cms_site_status_at` in a `title`. Agent health polls every `ops.agent.poll_minutes`
(5–15, clamped in `routes/console.php`), so "last checked 4 hours ago" means the poll is broken
— and today that looks identical to a healthy site.

**Proposed UX.** One shared freshness primitive: relative age (`4 sa önce`) with the absolute
time in the tooltip, and a `stale` tone when the age exceeds 2× the expected poll interval.
Applies to agent health, live probe and publish mirror. `null` stays an explicit `Bilinmiyor`,
never a silent blank (skill rule 11).

**Files.** new `resources/views/components/ops/freshness.blade.php` (or a `Site` accessor plus a
partial), `resources/views/ops/sites/_cell.blade.php`,
`resources/views/ops/sites/{_agent-health,_publish-state}.blade.php`,
`app/Models/Site.php`, `public/css/ops-ui.css`, `lang/{tr,en}/ops.php`.

**Acceptance.**
- A site whose `last_health_at` is older than 2× the poll window renders the stale tone and
  says so in text, not by color alone.
- `null` renders `Hiç` / `Bilinmiyor` and never a stale tone.
- Relative labels are translated, not built by string concatenation in Blade.

### P1-11 · Domains: bulk bind the unbound · M

**Problem.** `/domains` already has an `unbound` filter that auto-applies, and the registry
knows which hosts are missing from Coolify. But `domains/_region.blade.php` offers **only a
per-row Bind button** — clearing 30 unbound hosts after a Cloudflare change is 30 clicks, each
its own request. Sites has the whole bulk machinery (`data-ops-bulk`, `BulkResultSummary`,
`OpsBackgroundJob`) and Domains uses none of it.

**Proposed UX.** Checkbox column plus the shared bulk bar, with one action: `Coolify'a bağla`.
Runs as a background job with the per-site pacing already in `CoolifyRateGuard`, and reports
through the widget like any other sweep.

**Files.** `resources/views/ops/domains/{index,_region}.blade.php`,
`app/Http/Controllers/Ops/DomainController.php`, `routes/ops/domains.php`,
`app/Services/Ops/OpsJobRunner.php` (+ `OpsBackgroundJob` type `domains.bulk_bind`),
`lang/{tr,en}/domains.php`.

**Acceptance.**
- Header checkbox selects every domain matching the current filters and the P0-1 summary rules
  apply here too.
- The sweep paces through `CoolifyRateGuard`; a 429 reports `atlandı (istek sınırı)`, never a
  fabricated failure.
- If the action ends in a Coolify rebuild it reports **Tetiklendi** via
  `OpsBackgroundJob::triggersRemoteWork()`.
- Bind is idempotent: re-binding an already-bound host is a no-op, not an error.

---

## P1 — fleet-wide surfaces

### P1-12 · Agent secret health across the fleet · M

**Problem.** `CONTROL_PLANE_AGENT_SECRET` injection is the single most common reason a site's
theme assign, admin management and publish toggle all fail (see the ledger's hybrid leftovers).
Today it is only visible one site at a time, inside that site's App health card, as the
`inject_secret` fix. There is no fleet answer to "which sites have no secret, and which have one
the CMS has never accepted?" — `FleetDashboardKpis` reads `agent_secret_encrypted` but only to
fold it into a generic `unhealthy` count.

**Proposed UX.** An attention card `Agent gizli anahtarı` splitting `yok` / `doğrulanmamış` /
`tamam`, a list filter, and a bulk `Gizli anahtar üret ve gönder` for the missing set. Never
display or log a secret (`SecretRedactor` stays in the path).

**Files.** `app/Services/Fleet/FleetDashboardKpis.php`,
`app/Services/Sites/SiteAgentSecretInjector.php`, `app/Models/Site.php`,
`resources/views/ops/dashboard/attention.blade.php`, `resources/views/ops/sites/index.blade.php`,
`lang/{tr,en}/{fleet,sites}.php`.

**Acceptance.**
- A site with a stored secret the CMS has never answered `200` for reads `doğrulanmamış`, not
  `tamam`.
- Bulk inject only targets sites genuinely missing a secret; it never rotates a working one
  (existing rule: generate only when missing).
- No secret value appears in the response, the widget, the audit row or the log.

### P1-13 · Job and audit history page · L

**Problem.** `/jobs` is a **JSON-only** route: `OpsJobController@index` returns
`jobs` + `deployments`, filtered to `actor_user_id = me`, the last 2 hours, 20 rows. There is no
Blade view for it anywhere under `resources/views/ops/`. So once the widget is dismissed, what
happened is unrecoverable from the UI — and `app/Models/AuditLog.php` exists with **no UI at
all**, despite audit rows being written on every theme mutation, force start and publish toggle.

**Proposed UX.** `/activity`: one reverse-chronological, filterable table merging ops jobs,
deployments and audit rows — filter by actor, site, kind and outcome, reusing the async list
region so it behaves like Sites. Row click opens the existing deployment show page or a job
detail. Audit rows are read-only and redaction stays enforced.

**Files.** new `app/Http/Controllers/Ops/ActivityController.php`, new
`resources/views/ops/activity/{index,_region}.blade.php`, `routes/web.php`,
`resources/views/layouts/ops.blade.php` (nav), `app/Models/AuditLog.php` (scopes),
`lang/{tr,en}/ops.php`.

**Acceptance.**
- Search / filter / sort / paging work through `ListFragment` with no page reload and still work
  with JS off.
- A Viewer sees only what their role allows; audit payloads stay redacted.
- Another operator's jobs are visible here (unlike the widget) but only to roles permitted to
  see them — decide and document that rule in the slice.
- Page is bounded: no unpaginated `->get()`.

### P1-14 · Coolify inventory and mail servers get list controls · M

**Problem.** [docs/modules/ops-list-async.md](../../modules/ops-list-async.md) names this gap
outright: "Coolify inventory, mail servers and the fleet dashboard have no list controls yet."
`coolify/inventory-show.blade.php` and `mail-servers/index.blade.php` render bare tables, so
finding one app among 18 or one order among many is Ctrl+F.

**Proposed UX.** The same toolbar + `[data-ops-list-region]` pair the other three lists use —
search, the relevant filter, clear, shared pagination. No new primitive; this is adoption.

**Files.** `resources/views/ops/coolify/inventory-show.blade.php` (+ `_region`),
`resources/views/ops/mail-servers/index.blade.php` (+ `_region`),
`app/Http/Controllers/Ops/{CoolifyInventoryController,MailServerOpsController}.php`,
`lang/{tr,en}/{coolify,mail}.php`.

**Acceptance.**
- Both pages update in place and degrade to full navigation without JS.
- Both send `Vary: X-Ops-List-Fragment` and use `ops.partials.pagination`.
- `OpsListFragmentTest` extended to cover both routes.

### P1-15 · Bulk defaults that match what the operator selected · M

**Problem.** Two bulk defaults are misleading at scale. `$bulkPinCommits` in
`SiteController@index` is built from `$sites->getCollection()` — **only the current page** — so
with `all=1` over 200 sites the "pin to commit" dropdown offers commits from 25 of them and
silently omits the rest. And `Auto-deploy aç/kapat` resolves mixed selections to *off*
(`site_ops.bulk.confirm_auto_toggle`), which is a surprising destructive default for a button
whose label promises a toggle.

**Proposed UX.** Pin offers commits across the whole selection scope (or, when `all=1`, asks for
an explicit ref instead of a page-scoped list). Auto-deploy splits into two explicit buttons,
`Aç` and `Kapat`, using the `confirm_auto_on` / `confirm_auto_off` strings that already exist in
`lang/tr/site_ops.php` and are currently unused by the bulk bar.

**Files.** `app/Http/Controllers/Ops/SiteController.php`,
`resources/views/ops/sites/_region.blade.php`, `lang/{tr,en}/site_ops.php`.

**Acceptance.**
- With `all=1`, the ref control never presents a commit list that covers only one page.
- `Aç` and `Kapat` each have their own confirm; no mixed-selection guessing remains.
- `SiteBulkActionsTest` extended with an `all=1` + multi-page fixture.

---

## P2 — polish

### P2-16 · Destructive bulk actions separated from routine ones · S

**Problem.** In `resources/views/ops/sites/_region.blade.php` the bulk row is ten buttons in one
flat group, ending with `btn-danger` **Hard delete** immediately after `Commite geç`. The Plane
skill forbids exactly this ("destructive actions never sit beside the primary Save action
without separation"), and `all=1` makes a mis-click fleet-wide.

**Proposed UX.** Routine actions stay inline; destructive actions move into a separated
`Tehlikeli işlemler` menu behind the existing `ops-action-menu` primitive, with the P0-1 count in
the confirm.

**Files.** `resources/views/ops/sites/_region.blade.php`, `public/css/ops-ui.css`,
`lang/{tr,en}/sites.php`.

**Acceptance.** Hard delete requires opening a menu, then a confirm that names the count;
keyboard path to it still works; no action is lost.

### P2-17 · Confirm copy consistency pass · M

**Problem.** The confirm surface drifted. Singles interpolate `:name`, bulks say
`Seçili siteler`. Danger flags are applied by feeling: `sites.bulk.publish` is
`data-confirm-danger="false"` while `unpublish` is `true`; `site_ops.bulk.compose` sets no danger
flag at all; some forms set `data-confirm-title` and `data-confirm-label` and some omit them. One
`data-confirm` even builds its body inline in Blade with string concatenation
(`__('sites.app_health.fixes.'.$fixKey).' — '.$site->name.'?'`), which cannot be translated
properly.

**Proposed UX.** A documented matrix — who/what/how many, reversible or not — and one rule for
when `danger` is set (anything that deletes, unpublishes, stops, or triggers a build on more
than one site). Every confirm body becomes a real translation key with placeholders.

**Files.** `lang/{tr,en}/{sites,site_ops,domains,themes,coolify}.php`, the Blade views carrying
`data-confirm`, `public/js/ops-confirm.js`, `docs/modules/ops-sites.md`.

**Acceptance.**
- A test walks the rendered ops views and asserts every `data-confirm` form also carries
  `data-confirm-title`, `data-confirm-label` and an explicit `data-confirm-danger`.
- No confirm body is assembled by concatenation in a template.
- Turkish and English both read as full sentences.

### P2-18 · Empty states that offer the next action · S

**Problem.** Sites is being handled by the in-flight slice described above (filtered-empty panel
plus `ops.partials.filter-chips`). Domains is not: `domains/_region.blade.php` renders a title
plus a static hint with **no action**, and the filtered-empty case reuses the same hint. Coolify,
mail servers and themes need the same audit.

**Proposed UX.** Every empty state states the reason and offers the one useful next step —
`Coolify'dan içe aktar` for an empty domain registry, `Filtreleri temizle` for a filtered miss.

**Files.** `resources/views/ops/domains/_region.blade.php`,
`resources/views/ops/{coolify,mail-servers,themes}/*.blade.php`,
`lang/{tr,en}/{domains,coolify,mail,themes}.php`.

**Acceptance.** No empty state is a dead end; filtered-empty and truly-empty read differently
(the pattern Sites already uses).

### P2-19 · Platform mail is discoverable and shows its state · S

**Problem.** The sidebar `Mail` item links to `route('ops.mail-servers.index')` while matching
`ops.platform-mail*` for its active state (`resources/views/layouts/ops.blade.php`), so
`/platform-mail` — the Deamon product SMTP that sends every site-down and deploy-failed alert —
is reachable only through a `btn-ghost` link at the top of the mail servers page. Nothing in the
nav reveals whether it is configured, or whether the last push to sites succeeded.

**Proposed UX.** Split the nav entry into `Mail sunucuları` and `Yazılım maili`, or give the Mail
item a sub-list. Add a state chip on the platform mail page and the mail servers page:
`Yapılandırılmadı` / `Etkin` / `Son gönderim başarısız`, using the push-failure surfacing that
already exists.

**Files.** `resources/views/layouts/ops.blade.php`,
`resources/views/ops/{mail-servers/index,platform-mail/edit}.blade.php`,
`app/Http/Controllers/Ops/PlatformMailSettingsController.php`,
`lang/{tr,en}/{ops,platform_mail}.php`.

**Acceptance.** Platform mail is reachable in one click from any page; the chip reflects real
state including the last push outcome; SMTP password is never rendered
(`platform_mail.fields.password_saved` behavior preserved).

---

## Out of scope tonight

- Multi-tenant SaaS, marketplace, customer theme ZIP upload.
- Mailcow and other related infra ([docs/related-infra.md](../../related-infra.md)).
- Any CMS (`codron-co/deamon`) change. The `git`-missing CMS runtime blocker in the ledger stays
  a CMS repo problem; do not work around it in Plane.
- Live Coolify mutations against production apps. `Http::fake` in tests; never touch Susa
  `crxguq6nodorlzy88wf9x305`.
