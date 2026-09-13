# Overnight cycle — morning report

**Cycle:** 2026-09-12 Sat night → 2026-09-13 Sun (~07:00 Istanbul target)
**Finalized:** 2026-09-14 Mon ~01:00 Istanbul (past the wake window)
**Backlog:** [2026-09-12-overnight-plane-backlog.md](2026-09-12-overnight-plane-backlog.md)
**Repo:** Deamon Plane only
**Git:** dirty, **nothing committed**. HEAD is still `2ccf2ae` on `alpha`. Most overnight files are staged; the compose-domain slice also has unstaged hunks (code, tests, runbook). No PR, no push.

---

## Operator handoff (read this first)

The Sat→Sun backlog (**P0-1…P0-5**, **NEW P0** waiting ≠ failure, **P1-6…P1-15**, **P2-16…P2-19**) is **code-complete in this worktree**, with tests and docs. The same dirty tree also holds ADR-10 / ADR-11, Settings jump, site-detail copy, `/activity` CSV, jobs **Sadece hatalılar**, Domains leftover clear, and a compose-domain bind-after-first-deploy slice that had no Done entry until this finalize.

**Do not ship this pile.** Do not deploy Plane. Do not mutate live Coolify. Review, then commit, when you choose — this finalize did neither.

### Shipped (grouped)

**Honesty — stop the panel lying or costing money**

- Bulk bar states `all=1` scope; every confirm interpolates the count, including Hard delete (P0-1).
- Sites search-as-you-type no longer `chunkById`s the fleet; Fix App counts are a 60s cache with a freshness note (P0-2).
- Queued deploys read `sırada 4 / 22`; widget header `1 derleniyor · 22 kuyrukta` (P0-3). Hidden tabs stop polling; interval 1.5s → 5s → 10s; one Coolify read per cache window (P0-4).
- Failed-deploy KPI is a 24h per-site window, not lifetime history (P0-5). Cards drill to `/sites?deploy=failed` (P1-7).
- A site waiting for the build slot is `sıra bekliyor`, not `hata`. The sweep no longer queues behind its own builds (NEW P0).
- `health=unhealthy` and `app=issues` are SQL over persisted verdicts — no fleet scan (P1-7 leftovers / ADR-10).
- Search reaches aliases / `www` / Coolify app UUID (P1-8). Freshness is relative + visible **Eski** (P1-10). Pin is a typed SHA; auto-deploy is **Aç** / **Kapat** (P1-15).
- Hard delete sits behind **Tehlikeli işlemler** (P2-16). Confirms carry title + label + explicit danger on Sites, Coolify, mail, Themes, Cloudflare (P2-17).
- Compose create no longer sends `docker_compose_domains` before git has loaded compose; bind happens after the first deploy. Coolify’s English order error becomes Turkish `coolify.errors.compose_domains_before_raw` (in the tree; not a backlog id).

**Triage — find it, act in bulk**

- Named filter/sort/column presets; bare `/sites` 302s to the default view (P1-6).
- `/` focuses list search; `g` then `s`/`d`/`t`/`f`; `?` overlay; `Ctrl/⌘+K` palette over pages + sites + domains + themes (P1-9).
- Domains bulk **Coolify'a bağla** (one PATCH per site; 429 → `atlandı`) plus leftover **Kayıttan sil** (Plane-only, no Coolify HTTP) (P1-11 + leftover).
- Fleet **Agent gizli anahtarı** card (`yok` / `doğrulanmamış` / `tamam`); bulk inject never rotates (P1-12).
- `/activity` merges jobs, deploys, audits (Viewer can read other operators; payloads redacted) plus **CSV indir** (P1-13).
- List toolbars on mail servers, Coolify inventory, and fleet attention (`q` + `kind`) (P1-14).

**Discoverability**

- Two-cause empty states + shared filter chips on Sites, Domains, Themes, mail, Coolify (P2-18). Operator copy, not `Http::fake`.
- **Posta** nav: Posta sunucuları + Yazılım maili; state chip on both mail pages (P2-19). Chip stays off the global nav (P0-2 cost).
- Settings hash jump + in-page filter; env-default **rows** hide in place (not a ListFragment).
- Site detail copies Site ID, Coolify UUIDs, and the Coolify UI deep link.

**Harness**

- ADR-11: Pest keeps Blade/`data-*`; `node --test tests/js/*.js` covers toolbar URL, backoff, interpolate, typing/confirm, `pageIsHidden`, Settings `textMatches`, list `fetch` / `replaceState`. No jsdom, no `package.json`.
- `PlaneUI.refresh()` after a region swap still waits for a fixture.

### Suite status (this finalize)

Run 2026-09-14 ~01:00 Istanbul on the **current working tree** (staged overnight + unstaged compose-domain hunks).

| Check | Command | Result |
|-------|---------|--------|
| Full suite | `php artisan test` | **847 passed**, 0 failed (**4619** assertions), 209.25s |
| JS contracts | `node --test tests/js/ops-contracts.test.js` (only file under `tests/js/`; same as `tests/js/*.js`) | **18 passed**, 0 failed |
| JS syntax | `node --check` on `ops-jobs.js` `ops-ui.js` `ops-list.js` `ops-palette.js` `ops-shortcuts.js` `ops-contracts.js` | pass |
| Style | `php vendor/bin/pint --dirty --test` | **fail** — `app/Support/Ops/PaletteFilters.php` `line_ending` only. Not rewritten this tick. |
| Health / app filters | inside the full suite | `HealthAppFilterTest` **18** passed |
| Empty-copy neighbours | inside the full suite | `ThemeListEmptyStatesTest` 5, `MailListEmptyStatesTest` 8, `CoolifyInventoryListTest` 10 — all pass |
| Git | `git status` | **dirty, nothing committed** |

Last overnight tick (~03:30) recorded **845 passed** / 4611 assertions and **13** then **18** node tests. This run is **+2 Pest** (`CoolifyClientRateLimitTest` + `DeploymentFailureTextTest` compose-order cases) and **18** node, matching `tests/js/ops-contracts.test.js`.

### Still open / do not ship yet

1. **Uncommitted interleaved pile.** Many workers, one worktree. Reverting one slice without the others is unsafe. Most files are already staged. Unstaged on top of that: `DeploymentFailureText.php`, `ProvisionSiteTest.php`, `SiteLandingFlowTest.php`, `CoolifyClientRateLimitTest.php`, `DeploymentFailureTextTest.php`, `docs/runbooks/provision-site.md`, plus small hunks on `docs/modules/{coolify-client,ops-sites}.md`. This finalize also left unstaged edits on the morning report, backlog status line, and ledger.
2. **Do not deploy Plane or PATCH live Coolify** from this tree. Tests are factories + `Http::fake` only. Susa `crxguq6nodorlzy88wf9x305` was not touched.
3. **ADR-11 leftover:** jsdom only when a test needs `PlaneUI.refresh()` after a region swap, confirm focus-trap, or palette arrows. Do not add `package.json` until then. Do not add Playwright.
4. **CMS runtime image has no `git`.** Theme install/update stays dead on live. That is a CMS repo fix.
5. **Hybrid leftovers unchanged:** unsigned Coolify Notifications webhook (`?token=`), each site still needs `CONTROL_PLANE_AGENT_SECRET` before theme assign 200s, live Plane deploy historically skipped.
6. **Pint `line_ending`** on `PaletteFilters.php` — style only; suite is green.
7. Out of scope stayed out: marketplace, customer theme ZIP, Mailcow, CMS modules.

### No commit

This finalize **did not** stage, commit, open a PR, or push. HEAD remains `2ccf2ae`.

---

## How to append to this file

Implementers add to the sections below; nobody rewrites another worker's entry.

- One entry per backlog item, headed by its id (`P0-1`, `P1-8`, …).
- Say what an operator can now do that they could not before, in one sentence, before any
  implementation detail.
- Name the tests you ran and their result. "Should pass" is not a result.
- Record files touched so the morning reviewer can read the diff without hunting.
- **No commit, no PR, no push** this cycle. The worktree stays dirty; this file is the handoff.
- If a slice changed behavior, the same slice must have updated `docs/` and
  `docs/plans/progress-ledger.md`. A code-only entry belongs under **In progress**, not **Done**.

Entry template:

```md
### P0-1 · <short title>

- **Operator-visible change:** …
- **Files:** …
- **Tests:** `<TestName>` — N passed / N failed; `vendor/bin/pint --dirty` clean?
- **Docs + ledger:** updated / not yet
- **Notes:** anything the next worker must know (a decision taken, a fixture added, a knob in
  `config/ops.php`)
```

---

## Done

> Only items whose code, tests, docs and ledger entry all landed. Nothing here is committed.

_(append below)_

### P0-1 · Bulk bar says what `all=1` will actually touch

- **Operator-visible change:** the bulk bar now states its own scope before anything is
  submitted — `3 site seçildi` for ticked rows, `Filtreye uyan 214 sitenin tümü seçildi` once the
  header box is on — and **every** bulk confirm names that number, so Hard delete asks
  `Seçili 214 site kalıcı silinsin mi?` instead of an uncounted `Seçili siteler`.
- **How it works:** `_region.blade.php` wraps the action row in `[data-ops-bulk-actions]` carrying
  `data-bulk-total="{{ $sites->total() }}"` and the two summary templates.
  `setupBulkSelection` in `ops-ui.js` computes the scope (`all=1` → filtered total, otherwise the
  ticked rows), writes the summary line, and substitutes `__COUNT__` — alongside the existing
  `__TARGET__` — into every `data-confirm-template` in the form. Scope-switch controls
  (`Filtreye uyan N sitenin tümünü seç` / `Sadece bu sayfayı seç`) stay hidden when the filter
  does not reach past the current page, and a partial selection leaves the header checkbox
  `indeterminate` rather than looking like an `all=1` sweep.
- **Decision worth knowing:** the server-rendered `data-confirm` fallback is interpolated with
  `$sites->total()`, not `0`. The confirm modal is itself JavaScript, so that value is only ever
  read if `ops-ui.js` failed to bind — in which case over-warning with the widest reachable scope
  is the safe failure mode. Documented in `ops-sites.md`.
- **Files:** `resources/views/ops/sites/_region.blade.php`, `public/js/ops-ui.js`,
  `public/css/ops-ui.css` (`.sites-bulk-bar`, `.sites-bulk-summary`),
  `lang/{tr,en}/site_ops.php` (`bulk.summary_page`, `bulk.summary_all`,
  `bulk.select_all_matching`, `bulk.select_page_only`, plus `:count` in every `bulk.confirm_*`),
  `lang/{tr,en}/sites.php` (`publish.bulk.confirm_*`, `danger.hard_confirm_bulk`),
  `docs/modules/ops-sites.md`, `docs/plans/progress-ledger.md`.
- **Tests:** new `SiteBulkSelectionScopeTest` — 5 passed (filtered total is published and shrinks
  with the filter, every bulk confirm carries a count placeholder, hard delete names the count in
  Turkish, `all=1` + `filter_channel` purges exactly the filtered set and spares the rest, the
  async region reships the summary so no stale count survives a swap).
  `SiteBulkActionsTest` (13 passed) and `SiteDetailTest` (9 passed) updated: two assertions
  pinned the old uncounted confirm bodies. `vendor/bin/pint --dirty` clean.
- **Docs + ledger:** updated.
- **Notes:** the acceptance line "with 3 rows ticked the bar reads `3 site seçildi`" is browser
  behavior and there is no JS test runner in this repo, so the feature test asserts the contract
  the browser consumes (`data-bulk-total`, the summary templates, and a `__COUNT__` placeholder on
  every bulk confirm) rather than the rendered string. A DOM-level runner is the only way to close
  that last gap — worth deciding before P0-3/P0-4 add more widget logic.

### P0-2 · Typing in the Sites search no longer scans the whole fleet

- **Operator-visible change:** search-as-you-type on Sites stops doing fleet-wide work per
  keystroke; the header **Fix App issues** menu keeps its counts but now says how old they are
  (`Sayımlar 2 dakika önce hesaplandı.`) instead of implying they are live.
- **How it works:** three guards. (1) `SiteController@index` only computes the counts when
  `ListFragment::wanted($request)` is false — a region response never renders that menu, and
  `ops-list.js` always sends the fragment header. (2) Non-writers never trigger it. (3) Page
  renders read `SiteAppHealthFixer::cachedCategoryCounts()`, a `Cache::remember` over the existing
  `categoryCounts()` scan keyed `ops.sites.app_health_category_counts`, TTL
  `ops.app_health.counts_ttl` (default 60s; `0` disables caching and scans per render). Every
  `fix()` attempt clears the entry in a `finally`, including a failed one, since a failed fix can
  still have changed the site.
- **Files:** `app/Http/Controllers/Ops/SiteController.php`,
  `app/Services/Sites/SiteAppHealthFixer.php` (`cachedCategoryCounts()`,
  `forgetCategoryCounts()`), `config/ops.php` (`app_health.counts_ttl`),
  `resources/views/ops/sites/index.blade.php`, `public/css/ops-ui.css` (`.ops-menu-note`),
  `lang/{tr,en}/sites.php` (`app_health.counts_age`), `docs/modules/ops-sites.md`,
  `docs/plans/progress-ledger.md`.
- **Tests:** new `SiteListAppHealthCountCostTest` — 6 passed. A Mockery partial mock counts calls
  to `categoryCounts()` while the cached wrapper runs for real: a region request scans **0** times,
  three consecutive page renders scan **1** time, a Viewer scans **0** times. Plus: a full page
  still renders the counts and the fix labels, the freshness note appears, and a fix attempt drops
  the cache entry. `SiteAppHealthTest` (7 passed) unaffected. `vendor/bin/pint --dirty` clean.
- **Docs + ledger:** updated.
- **Notes:** `ListFragment::wanted()` was already public, so no new "is this a region request"
  helper was needed. The per-row `orderedUniqueFixes()` in `_region.blade.php` is untouched — it is
  page-scoped (25 rows) and feeds the row menus. If P1-7 adds an `app=issues` list filter, it must
  not reintroduce a fleet scan in the filter path.

### P0-5 · Fleet KPIs stop counting history as if it were now

- **Operator-visible change:** the failed-deploy card answers "what is broken right now" —
  `Başarısız dağıtımlar · son 24 sa`, counted once per site — and the list under it finally agrees
  with the number above it. Long attention lists say what they left out (`+22 site daha`) instead
  of printing every row.
- **How it works:** `FleetDashboardKpis::failedDeploysInWindow()` filters
  `COALESCE(finished_at, started_at, created_at) >= now() - :window`, so a build that started three
  days ago and failed an hour ago still counts while the throttle-storm rows drop out. The card is
  `count(distinct site_id)`; the attention list resolves `MAX(id) per site_id` inside the window,
  ordered newest first and capped, so five failed retries of one site are one row and one count.
  Both attention lists render at most `ops.fleet.attention_limit` rows while the chip keeps
  reporting the real total. `unhealthySites()` no longer `->get()`s every column of every row and is
  memoised, so the KPI and the list evaluate the fleet once instead of twice.
- **Files:** `app/Services/Fleet/FleetDashboardKpis.php`,
  `app/Http/Controllers/Ops/FleetController.php`,
  `resources/views/ops/dashboard/{kpis,attention}.blade.php`, `config/ops.php`
  (`fleet.failed_deploy_window_hours`, `fleet.attention_limit`), `public/css/ops-ui.css`
  (`.fleet-attention-more`), `lang/{tr,en}/fleet.php`, `docs/modules/coolify-webhooks.md`,
  `docs/plans/progress-ledger.md`.
- **Tests:** new `FleetFailedDeployWindowTest` — 6 passed (a 72-hour-old failure is excluded; a row
  created 72 hours ago but finished an hour ago is included; retries of one site count and list
  once; the card total, the capped list and the `+N` overflow agree; the label names the window; a
  60-site / 30-failure fixture renders in under 25 queries with both lists capped at 8).
  `FleetDashboardTest` (4 passed) unchanged and still green. `vendor/bin/pint --dirty` clean.
- **Docs + ledger:** updated.
- **Notes:** the backlog also wanted a `Tümünü gör` drill-down from the card. **Deliberately not
  built:** there is no `deploy=failed` list filter yet — that is **P1-7** — and a link to
  `?status=error` would be a different set, so it would be a plausible-looking lie. The `+N site
  daha` line is text for the same reason; turn both into links in the P1-7 slice, which is exactly
  where the filter arrives. `ops.fleet.attention_limit` also caps the unhealthy list; the
  Dockerfile-pack list was left uncapped because it shrinks as the migration completes and is
  already narrow-column.

### P2-18 (Sites only) · Empty states that name their cause

- **Operator-visible change:** a filtered miss now reads differently from an empty fleet. It shows
  the fleet size, one removable chip per active filter, and **Filtreleri temizle** as the primary
  action instead of a ghost link; a viewer looking at an empty fleet is told the role is read-only
  rather than shown nothing.
- **How it works:** `SiteController::activeListFilters()` returns `{key,label,value,url}` per
  active filter, where `url` is the same list minus that one filter. `ops.partials.filter-chips`
  renders them as plain GET links, so `ops-list.js` swaps the region in place and no-JS still
  navigates. `totalSites` distinguishes the two empty causes without a second filtered query.
- **Files:** `app/Http/Controllers/Ops/SiteController.php`, new
  `resources/views/ops/partials/filter-chips.blade.php`,
  `resources/views/ops/sites/{_region,index}.blade.php`, `public/css/ops-ui.css`,
  `lang/{tr,en}/sites.php` (`filter_search`, `empty.filtered_hint` with `:total`,
  `empty.filters_label`), `lang/{tr,en}/ops.php` (`filter.active`, `filter.remove`),
  `docs/modules/ops-sites.md`.
- **Tests:** new `SiteListEmptyStatesTest` — 5 passed (fleet-empty offers create, viewer sees the
  read-only reason, filtered-empty names filters + fleet size, each chip drops only itself while
  clear-all is the primary button, and the async region carries the same states).
  `OpsListFragmentTest` (7 passed) unaffected. `vendor/bin/pint --dirty` clean.
- **Docs + ledger:** updated (ledger entry covers this with P0-1/P0-2).
- **Notes:** this was the in-flight slice the backlog warned about; it is finished, not abandoned.
  `ops.partials.filter-chips` is deliberately generic — Domains, Coolify, mail servers and Themes
  should reuse it rather than grow a second chip implementation.

### P0-3 · A queued deploy says where in line it is

- **Operator-visible change:** a waiting deploy now reads
  `manuel · kuyrukta · sırada 4 / 22` instead of a bare `manuel · kuyrukta`, and the widget header
  says `1 derleniyor · 22 kuyrukta`. An operator who starts a 23-site bulk deploy can finally tell
  whether "kuyrukta" means two minutes or an hour, and can see the line move.
- **How it works:** `OpsCoolifyDeployQueue::queueStanding()` is the single read behind both
  numbers. It ranks unfinished `deployments` per **Coolify host** — connection id, else server
  uuid, else the site alone, deliberately the same grouping `CoolifyDeployGate` counts against,
  because a queue the gate does not share is not a queue the operator waits behind — ordered by
  `created_at` then `id`. `applyQueueStanding()` stamps `queue_position` / `queue_depth` /
  `queue_label` onto a widget row; `queueSummaryLabel()` builds the header line and returns `null`
  when nothing waits. `GET /jobs` and every cancel / force-start response carry
  `queue { running, queued, label }`.
- **Decisions worth knowing:** (1) **Depth counts the whole host queue, not the widget's page.**
  The `/jobs` deployment query is `limit(30)`, so reusing it would have reported `sırada 4 / 30` on
  a 200-site sweep — a new lie in place of the old one. `queueStanding()` therefore runs its own
  narrow aggregate query. (2) **A running build never gets a position**, only the `running` count;
  it already has an elapsed timer, and "sırada 0" would be nonsense. (3) **All copy is translated
  server-side.** `ops-jobs.js` concatenates nothing, so no English can reach the widget through a
  fallback; a Coolify queue row Plane never started has no `deployment_id` and therefore no rank,
  rather than a guessed one.
- **Files:** `app/Services/Ops/OpsCoolifyDeployQueue.php`,
  `app/Http/Controllers/Ops/OpsJobController.php`, `public/js/ops-jobs.js`,
  `resources/views/ops/partials/jobs-widget.blade.php`, `public/css/ops-ui.css`
  (`.ops-jobs-summary-text`, `.ops-jobs-queue`), `lang/{tr,en}/ops.php`
  (`jobs.queue_position`, `jobs.queue_building`, `jobs.queue_waiting`),
  `docs/modules/ops-sites.md`, `docs/plans/progress-ledger.md`.
- **Tests:** new `DeployQueueStandingTest` — **12 passed**, 0 failed. Covers the acceptance list
  directly: two queued deploys on one connection report positions 1 and 2 with the same depth;
  two connections do not share a depth; the oldest deploy is first; running and finished rows leave
  the queue; a 35-deep queue reports depth 35 (the "not the widget page" guard); the row label and
  the header line both read as Turkish under `App::setLocale('tr')`. `DeploymentPollWidgetTest`
  (12 passed) unchanged and still green. `Deployment::toWidget()` was **not** modified — the queue
  keys are stamped by the queue service, so nothing that does not know about queues grew a
  dependency on them.
- **Docs + ledger:** updated.
- **Notes:** `Deployment::toWidget()` was left alone on purpose even though the backlog named it.
  Queue knowledge belongs to `OpsCoolifyDeployQueue`, and a decorator keeps the model honest for
  the bulk-job rows that have no queue at all. Position recomputes on the next poll after a cancel
  or force start (≤ the current tier), and immediately in that action's own response.

### P0-4 · The jobs widget stops hammering Coolify from a background tab

- **Operator-visible change:** nothing on the happy path — which is the point. A tab left open on a
  ten-minute build used to cost ~400 Coolify requests; a hidden tab now costs **zero**, a long wait
  costs a read every 10s instead of every 1.5s, and several open tabs cost what one costs.
- **How it works:** three independent brakes.
  (1) **Hidden tabs stop.** `visibilitychange` clears the timer; becoming visible spends exactly one
  catch-up read and then resumes at the tier the wait had already earned, rather than resetting to
  fast every time the operator alt-tabs past.
  (2) **The interval grows while nothing changes.** `setInterval` is replaced by a chained
  `setTimeout` — an interval whose gap cannot change is the whole bug — walking
  `ops.jobs.poll` tiers `fast_ms` 1500 → `slow_ms` 5000 → `max_ms` 10000, one step per
  `slow_after` (8) polls whose state signature is unchanged. The signature is row id + status +
  progress + `queue_position`, so *queued → running* or the queue advancing one place snaps back to
  the fast tier, as does any operator action through `PlaneJobs.track()`.
  (3) **N pollers, one Coolify read.** `OpsCoolifyDeployQueue::runningDeployments()` caches the raw
  `GET /deployments` payload per connection for `ops.coolify.deploy.queue_cache_seconds` (5).
- **Decisions worth knowing:** (1) **Failures are not cached.** An unreachable or throttled
  connection must be retried on the next poll; `CoolifyRateGuard` already owns the cooldown, and
  caching an exception would turn one bad moment into a five-second lie. (2) **Cancel and force
  start `Cache::forget` the connection's entry**, so the operator who just changed the queue is
  never handed back the version they changed. (3) **Re-showing a tab does not reset the tier.**
  Resetting would let alt-tabbing become a way to hammer Coolify.
- **Files:** `public/js/ops-jobs.js`, `app/Services/Ops/OpsCoolifyDeployQueue.php`,
  `resources/views/ops/partials/jobs-widget.blade.php` (`data-poll-*`), `config/ops.php`
  (`jobs.poll.*`, `coolify.deploy.queue_cache_seconds`), `docs/modules/ops-sites.md`,
  `docs/plans/progress-ledger.md`.
- **Tests:** new `JobsPollPressureTest` — **5 passed**, 0 failed, all with `Http::fake` +
  `Http::preventStrayRequests()`: three consecutive pollers produce **one** `GET /deployments`;
  a poll after the cache window produces a second; `queue_cache_seconds = 0` reads every time;
  cancelling a deploy forces the next poll to re-read; and the widget renders the configured
  `data-poll-*` pacing (the config → DOM wiring the JS depends on). `node --check` on
  `ops-jobs.js` passes.
- **Docs + ledger:** updated.
- **Notes:** the two acceptance lines that are pure browser behavior — "hidden tab issues no
  request", "focus triggers exactly one immediate poll" — are **not** covered by an automated test,
  because this repo still has no JS runner (the same gap P0-1 flagged). They are small, explicit
  blocks at the bottom of `ops-jobs.js`; verify by hand with DevTools' network tab, and read the
  Risks section on the JS-runner decision. `stopIfIdle()` remains an intentional no-op: polling
  continues when idle so Coolify-started deploys still appear, but it now settles at the 10s tier
  instead of 1.5s, which was the actual cost.

### NEW P0 (wave 2) · A site waiting for the build slot is not a failure

- **Operator-visible change:** a bulk deploy against one Coolify host no longer invents failures.
  Where a 23-site sweep used to report `1 deploy tetiklendi, 22 hata` — for 22 sites that were
  never asked to deploy — it now reports `23 deploy tetiklendi`, because the sweep is the queue and
  no longer stands behind its own builds. When the slot really is held by somebody else's build,
  those sites come back as `3 sıra bekliyor` with their own sentence, not as `hata` and not as the
  rate-limit `atlandı`.
- **Root cause, confirmed:** `CoolifyDeployGate` counted the sweep's **own** open builds against
  `max_concurrent_per_server` (1). Site 1 deployed; its open row then refused site 2;
  `PacedFanout` deferred and retried for `ops.coolify.bulk.max_site_attempts` short waits; and
  because a busy gate is not a 429, `isRateLimited()` was false and the site fell through to
  `$failed++`. `2ccf2ae`'s own plan says *"bulk path already serial; gate still protects
  overlapping single-site redeploys"*, so refusing sites inside a sweep was never the intent — the
  gate was simply placed on a code path (`CoolifyDeploySettings::pin/followHead/redeploy`) that
  both the single-site and the bulk callers share.
- **How it works — the sweep owns the slot.** `PacedFanout::run()` wraps the whole sweep in
  `CoolifyDeployGate::duringSweep()`. The gate snapshots the highest `deployments.id` when the
  sweep opens; while it runs, only open builds **at or below** that watermark block a deploy. Rows
  above the watermark are the sweep's own queue — the same queue P0-3 renders as
  `1 derleniyor · 22 kuyrukta`. The watermark is released in a `finally` and nested sweeps inherit
  the outer one. The gate is now a container singleton (`AppServiceProvider`), for the same reason
  `CoolifyRateGuard` already was: the deploy paths resolve it from the container one site at a
  time, so instance state would not survive.
- **How it works — the third bucket.** `PacedFanout` adds `waiting` alongside `failed` and
  `skipped`, chosen by a new `isDeployBusy()` that walks the exception chain for
  `CoolifyDeployBusyException`. A waiting site is kept out of `errors` as well as out of `failed`:
  nothing was sent to Coolify, so there is no error to report. The result array gains
  `waiting`, `waiting_sites` and `deploy_busy` (the mirror of `rate_limited`).
  `BulkResultSummary` appends `ops.bulk.waiting` (`:waiting sıra bekliyor`) as its **own segment**
  after the ok/failed/skipped phrase, and `ops.bulk.deploy_busy` as its own note.
- **Decisions worth knowing:** (1) **Waiting is a separate segment, not a ninth combination key.**
  Folding it in would have meant eight `triggered_*` / `result_*` keys per vocabulary; more
  importantly it is orthogonal — a sweep can have failures *and* waiting sites, and unlike a
  failure, waiting clears itself when the slot frees. (2) **The gate keeps its original job.**
  Verified by test from both sides: a build started outside the sweep still refuses the whole
  sweep, and once the sweep closes, an unrelated single-site redeploy still queues behind the
  builds it left open. (3) **The four `BulkThrottleResilienceTest` assertions were not touched.**
  They were right; they describe the operator's bar (every selected site is accounted for, and one
  deployment per site with no duplicate from retries) and the product now meets it.
- **Files:** `app/Services/Coolify/CoolifyDeployGate.php`, `app/Services/Ops/PacedFanout.php`,
  `app/Services/Ops/BulkResultSummary.php`, `app/Providers/AppServiceProvider.php`,
  `app/Services/Sites/SiteAppHealthFixer.php`,
  `app/Http/Controllers/Ops/SiteAppHealthController.php`, `lang/{tr,en}/ops.php`
  (`bulk.waiting`, `bulk.deploy_busy`), `docs/modules/ops-sites.md`,
  `docs/plans/progress-ledger.md`.
- **Tests:** `BulkThrottleResilienceTest` — **8 passed, 0 failed** (was 4 failed), with **no edit
  to the test file**. New `BulkDeployWaitingBucketTest` — **6 passed**: a six-site sweep triggers
  all six with `max_concurrent_per_server = 1`; a foreign build turns the whole sweep into
  `sıra bekliyor` with neither `hata` nor `atlandı`; a waiting site is left with zero deploy rows
  and `Http::assertNothingSent()`; a two-host sweep prints `hata` and `sıra bekliyor` side by side
  with only the busy note; a pin sweep behind a busy slot waits instead of failing; and the gate
  still refuses a single redeploy after the sweep closes. `CoolifyDeployGateTest` (6 passed)
  unchanged and still green. `vendor/bin/pint --dirty` clean for this slice's files.
- **Docs + ledger:** updated (`ops-sites.md` gains "A sweep does not queue behind its own builds",
  and the bulk-counting section is now ok / hata / atlandı / sıra bekliyor).
- **Notes:** the watermark makes the sweep ignore a deploy another process starts *during* the
  sweep on the same host. That is a deliberate, bounded trade: the alternative is per-deployment
  ownership bookkeeping the gate cannot do without creating the rows itself, and the window is one
  sweep. If Plane ever runs two bulk sweeps concurrently against one host, revisit this before
  adding anything else to the gate.

### P1-7 (wave 2) · The failed-deploy number is a place you can go

- **Operator-visible change:** the fleet **Başarısız dağıtımlar · son 24 sa** card now has
  **Tümünü gör**, and the attention list's `+22 site daha` is a link. Both land on
  `/sites?deploy=failed`, a real Sites filter that resolves **exactly** the sites behind that
  number. An operator who sees "22" can open the 22.
- **What this closes:** P0-5 shipped the windowed card but deliberately left it dead — no
  drill-down, `+N site daha` as plain text — because no list filter matched the set and
  `?status=error` is a different one. This is the filter that item was waiting on.
- **How it works:** `Deployment::scopeFailedInWindow()` becomes the single definition of "failed
  recently": status `failed` and `COALESCE(finished_at, started_at, created_at)` inside
  `ops.fleet.failed_deploy_window_hours`. `FleetDashboardKpis::failedDeploysInWindow()` and the new
  `deploy` branch of `Site::matchingListFilters()` both call it, so the card and the page it opens
  cannot drift apart — the previous copy of that predicate lived only in the KPI service and would
  have been duplicated by a naive filter. The filter is a `whereHas`, not a join, so a site that
  failed five times is one row, matching the card's `distinct site_id`.
- **Decisions worth knowing:** (1) **The label carries the window.** `Dağıtımı başarısız · son 24
  sa`, built in the controller from `Deployment::failedWindowHours()` and used for both the select
  option and the chip. A bare "failed" would reintroduce the open-ended ambiguity P0-5 removed from
  the card, and it would go stale the moment the window config changes. (2) **`filter_deploy` rides
  along in every bulk form** (`_region.blade.php`, both header fix forms, all three `sitesFromBulk`
  resolvers, both `BulkSite*Request` rule sets), so `all=1` under this filter stays inside it —
  P0-1's contract. (3) **An unknown value is dropped, not applied**, so `?deploy=kaboom` widens to
  the whole fleet instead of rendering a mysterious empty table. (4) **The unhealthy list's `+N`
  stays plain text.** That verdict is `SiteHealthEvaluator` over stored agent payloads, not SQL;
  linking it would need a fleet scan in the filter path, which is exactly what P0-2 removed.
- **Files:** `app/Models/Deployment.php` (`scopeFailedInWindow`, `failedWindowHours`),
  `app/Models/Site.php` (`DEPLOY_FILTERS`, the `deploy` branch),
  `app/Services/Fleet/FleetDashboardKpis.php` (delegates to the shared scope),
  `app/Http/Controllers/Ops/SiteController.php`,
  `app/Http/Controllers/Ops/{SiteCoolifyOpsController,SiteAppHealthController,SitePublishStatusController}.php`,
  `app/Http/Requests/Ops/{BulkSiteIdsRequest,BulkSitePublishStatusRequest}.php`,
  `resources/views/ops/sites/{index,_region}.blade.php`,
  `resources/views/ops/dashboard/{kpis,attention}.blade.php`, `public/css/ops-ui.css`
  (`.kpi-link`, `.fleet-attention-more a`), `lang/{tr,en}/sites.php`
  (`filter_deploy`, `all_deploys`, `deploy_states.failed`), `lang/{tr,en}/fleet.php`
  (`attention.see_all`), `docs/modules/ops-sites.md`, `docs/plans/progress-ledger.md`.
- **Tests:** new `FailedDeployFilterTest` — **10 passed**. The load-bearing one asserts the card's
  count and the filter's row count are the same number on a fixture built to break a naive
  implementation (one site failed three times, one once, one outside the window, four clean); the
  rest cover the window edge measured from `finished_at`, both links present with the card offering
  none when nothing failed, the chip naming its window while dropping only itself, an unknown value
  widening rather than emptying, the async region shipping `filter_deploy`, and `all=1` under the
  filter resolving only the failed site. `FleetFailedDeployWindowTest` (6) unchanged and green.
  `vendor/bin/pint` clean on this slice's files.
- **Docs + ledger:** updated.
- **Notes:** the earlier "a third worker arrived mid-cycle (P1-7 + P1-8)" note read this slice off
  the diff before it had an entry — this is that entry, from the implementer. P1-8 (fleet search /
  `SiteFleetSearchTest`) is a **different** worker's slice; it was green in the full-suite run
  below, so it has since landed too.

### P1-8 · Fleet search finds aliases, www hosts, preview hosts and Coolify uuids

- **Operator-visible change:** the operator can now paste **whatever they have in hand** into the
  Sites search box and land on the site. A `www.` host, an alias from `site_domains`, the temporary
  preview host, or the Coolify app uuid they just copied out of Coolify all resolve — where before
  every one of those answered "no results" for a site that plainly exists. When the match is
  something not on screen, the row says which host matched under the site name, so the result never
  looks arbitrary. The placeholder names the new reach (`Ad, slug, domain, alias veya Coolify uuid
  ara`) instead of leaving it to be discovered by accident.
- **How it works:** one `where` group in `Site::scopeMatchingListFilters` with four legs — `name`,
  `slug`, `primary_domain` as `like`, every `site_domains` host via `whereHas('domains')`, and an
  exact match on `coolify_app_uuid`. `Site::searchMatchReason()` supplies the row explanation and
  `_region.blade.php` hands it to `_cell.blade.php`; `sites.search_match.alias` / `.uuid` carry the
  copy.
- **Decisions worth knowing:**
  1. **Exists subquery, not a join.** A join would return a site once per matching alias and inflate
     `$sites->total()` with it — which would then be the number P0-1's bulk summary and every bulk
     confirm print. A wider search must not quietly corrupt the selection scope.
  2. **The uuid leg is exact, not a prefix.** A prefix match would pull in every app sharing an id
     fragment and read like a broken search; an operator pasting a uuid always has the whole thing.
     There is a test pinning that a partial uuid finds nothing, so nobody "improves" it later.
  3. **The row stays quiet when the term is already visible.** `searchMatchReason()` returns `null`
     if the term is in the name, slug or primary domain, so an ordinary name search gains no second
     line of noise. Explaining a match the operator can already see is clutter, not clarity.
  4. **`domains` is eager-loaded only while searching.** Browsing the fleet pays nothing; a search
     pays one extra query for the whole page. Both directions are asserted, because the obvious
     implementation of the match line is an N+1 per row.
- **Files:** `app/Models/Site.php` (search legs + `searchMatchReason()`),
  `app/Http/Controllers/Ops/SiteController.php` (conditional eager load),
  `resources/views/ops/sites/{_region,_cell}.blade.php`, `public/css/ops.css`
  (`.site-search-match`), `lang/{tr,en}/sites.php` (`search_match.*`, wider `search_placeholder`),
  `docs/modules/ops-sites.md`, `docs/plans/progress-ledger.md`.
- **Tests:** new `SiteFleetSearchTest` — **10 passed**, 0 failed. Alias host found exactly once with
  two matching hosts on one site; temporary preview host found; full uuid finds exactly that site;
  partial uuid finds nothing; `%` and `_` are not honoured as wildcards (through the new alias leg
  too); the match line renders in Turkish for a `tr` operator with no English fallback; a
  name-visible match renders no match line; browsing issues **0** `site_domains` reads; a search
  over six aliased sites issues exactly **1**. `vendor/bin/pint` clean.
- **Docs + ledger:** updated.
- **Notes for the next worker:** (1) the alias leg is a leading-wildcard `like` on
  `site_domains.domain`, so it scans that table the same way the existing `name` / `primary_domain`
  legs scan `sites`. Fine at a few hundred hosts; if the registry grows an order of magnitude, that
  is where to look first, not at the exists subquery. (2) **P1-9's quick-jump palette should reuse
  these scopes** — the backlog already says so, and now there is one search definition to reuse
  rather than a second, narrower one to write. (3) The uuid leg is exact by contract; if a future
  slice wants prefix search, that is a product decision with a test to change, not an oversight.

### P2-16 · Destructive bulk actions no longer sit next to the routine ones

- **Operator-visible change:** **Hard Delete** is no longer the tenth button in a flat row, one
  mis-click away from **Commite geç** — with the header checkbox ticked, that mis-click reached the
  whole filtered fleet. It and **Yayından kaldır** now live in a separated `Tehlikeli işlemler`
  menu at the end of the bulk bar, so a fleet-wide purge takes a deliberate open-then-confirm.
  Nothing was removed and no confirm got weaker: both items keep the counted P0-1 confirm.
- **How it works:** the existing `ops-action-menu` primitive (`data-ops-action-menu`), not a new
  one, so Escape, outside click and the `aria-expanded` bookkeeping come for free, and
  `PlaneUI.refresh()` already re-arms it after an `ops-list.js` region swap. `sites.bulk_danger.*`
  names the trigger and the `Geri alınamaz` group label.
- **Decisions worth knowing:**
  1. **The `<details>` sits inside the bulk form.** Nested forms are invalid HTML, so the menu items
     stay plain submit buttons with `formaction`, which keeps `setupBulkSelection`'s
     `data-confirm-template` scan (it queries the *form*) working untouched.
  2. **`<summary>` is the trigger, not a JS button.** It is focusable and opens on Enter/Space with
     no JavaScript, so the keyboard path and the no-JS path are the same path.
  3. **Which actions moved: the two that cannot be walked back** — purge and unpublish, the two that
     already carried `data-confirm-danger="true"`. Publish stays inline; redeploy, branch, pin,
     HEAD, compose and auto-deploy stay inline. Deciding danger by "does it delete or unpublish"
     rather than by feeling is P2-17's job; this slice only used the flag that was already there.
  4. **The popover opens upward.** The bulk bar is the last element on the page; a downward menu
     would open off-screen.
- **Files:** `resources/views/ops/sites/_region.blade.php`, `public/css/ops-ui.css`
  (`.sites-bulk-danger`), `lang/{tr,en}/sites.php` (`bulk_danger.trigger`, `bulk_danger.label`),
  `docs/modules/ops-sites.md`, `docs/plans/progress-ledger.md`.
- **Tests:** new `SiteBulkDangerMenuTest` — **5 passed**, 0 failed: both destructive routes are
  inside the menu and neither (nor `btn-danger`) is left in the inline row; the menu is a native
  `<details>`/`<summary>` with two `role="menuitem"` items; both items still carry
  `data-confirm-danger="true"` and a `__COUNT__` template plus the count-bearing fallback body;
  every routine bulk route is still inline and `value="published"` stayed while `value="draft"`
  moved; a Viewer never sees the menu at all. `SiteBulkActionsTest` (9) and
  `SiteBulkSelectionScopeTest` (5) unchanged and green — the endpoints and the scope contract did
  not move. `vendor/bin/pint` clean.
- **Docs + ledger:** updated.
- **Notes for the next worker:** the same treatment is now trivial for the **site detail** action
  menus and for **Domains** bulk when P1-11 lands — reuse `ops-action-menu` plus the
  `sites.bulk_danger.*` pattern rather than inventing a second danger grouping. Hover/positioning of
  the upward popover was verified only by reading the CSS; there is still no browser or DOM runner
  in this repo (the gap P0-1, P0-3 and P0-4 all flagged), so if anyone adds one, this menu's
  open/close and focus behavior is a cheap first test case.

### P2-19 (wave 3) · Platform mail is in the nav, and it says whether it works

- **Operator-visible change:** **Yazılım maili** is now its own sidebar entry under a **Posta** group,
  next to **Posta sunucuları** — one click from any page, instead of a `btn-ghost` link hidden at the
  top of the mail servers page. Both mail pages now open with a state chip that answers the question
  the nav could not: `Yapılandırılmadı`, `Etkin · sitelere aktarılmadı`,
  `Son gönderim başarısız · 3 site`, or `Etkin`, each with a full sentence beside it.
- **What was actually wrong with the nav:** the single `Mail` item linked to `route('ops.mail-servers.index')`
  while matching `ops.platform-mail*` for its active state. So the product SMTP behind every password
  reset, site-down alert and deploy-failed mail was both undiscoverable *and*, once you found it, shown
  under a highlighted nav item that pointed somewhere else.
- **The failed state needed a fact that did not exist.** This is the part of the item worth reading.
  The backlog says to use "the push-failure surfacing that already exists" — there is none. A failed
  push reached `Log::warning` and nothing else: `DispatchPlatformMailPushJob` fans out to one
  `PushPlatformMailJob` per site and stamps `last_pushed_at` **unconditionally**, so the settings row
  records that a push was attempted, never whether any site accepted it. Rendering
  `Son gönderim başarısız` off that would have been a guess. So `PlatformMailConfigurer::sync()` — the
  one choke point both the queued push and the site-detail sync go through — now stamps
  `sites.platform_mail_pushed_at` / `platform_mail_push_failed_at` / `platform_mail_push_error`.
- **Decisions worth knowing:**
  1. **A success clears the failure.** `push_failed` therefore means "this site's **last** attempt
     failed", not "it failed once in the past" — the same distinction P0-5 drew for the failed-deploy
     card, one surface over. It also makes the count a single `whereNotNull` rather than a timestamp
     comparison that would go wrong the first time two pushes raced.
  2. **The nav carries no state.** A chip in the layout would put a settings read plus a `count()` on
     every page render in the fleet, which is exactly the cost P0-2 removed from the Sites list. The
     chip lives on the two pages that already query mail.
  3. **Sites with no agent secret are never stamped.** `sync()` returns before the HTTP call for them,
     so "never pushed" stays null and is not counted as a failure — a fresh fleet reads `Etkin`, not
     `Son gönderim başarısız · 12 site`.
  4. **Reasons are short codes** (`timeout`, `http_500`, `no_base_url`), never a response body, so the
     new column cannot become a leak path. The chip renders the count, not the codes.
  5. **A group label, not a submenu.** A `<details>` disclosure would have made platform mail two
     clicks from a cold page and added JS state to the one component that must work everywhere. Two
     plain links under an uppercase group label are one click, keyboard-navigable, and need no script.
- **Files:** `resources/views/layouts/ops.blade.php`, new
  `resources/views/ops/partials/platform-mail-chip.blade.php`, new
  `app/Services/Mail/PlatformMailState.php`, `app/Services/Mail/PlatformMailConfigurer.php`,
  `app/Http/Controllers/Ops/{PlatformMailSettingsController,MailServerOpsController}.php`,
  `app/Models/Site.php` (fillable + casts), new migration
  `2026_09_13_010000_add_platform_mail_push_state_to_sites_table.php`,
  `resources/views/ops/{mail-servers/index,platform-mail/edit}.blade.php`, `public/css/ops.css`
  (`.ops-nav-group`, `.ops-nav-group-label`, `.ops-nav-item.is-sub`, `.status-warning`,
  `.ops-mail-state*`, plus the ≤768px row treatment), `lang/{tr,en}/ops.php` (`nav.mail_servers`,
  `nav.platform_mail`), `lang/{tr,en}/platform_mail.php` (`state.*`, `state_hint.*`, `state_label`,
  `state_pushed_at`), `docs/modules/platform-mail.md`, `docs/plans/progress-ledger.md`.
- **Tests:** new `PlatformMailStateTest` — **8 passed**, 0 failed. Fleet, Sites and Themes all link to
  `/platform-mail` and name both entries in Turkish; the mail-servers entry is **not** `is-active`
  while on the platform mail page (the actual nav bug, pinned); all four chip states render on the
  page that owns them; a 500 then a 200 through the real `PlatformMailConfigurer` stamps and then
  clears the site's push columns; a never-pushed site does not turn the fleet red; the active-state
  page does not echo the SMTP password. `PlatformMailSettingsTest` (7), `PlatformMailPushJobTest` (2),
  `MailServerOpsTest` (8) and `NavPagesTest` (1) unchanged and green.
- **Docs + ledger:** updated.
- **Notes:** the migration is additive and nullable, so an existing fleet reads `Etkin · sitelere
  aktarılmadı` until the first push after it lands — correct, not a gap. `PlatformMailState` is
  deliberately a value object with no container binding: if a later slice wants this on the fleet
  dashboard, cache it there rather than making the layout pay for it.

### P1-11 · Domains: bulk bind the unbound

- **Operator-visible change:** `/domains` can now bind a filtered set in one confirm. The bar
  reads `2 domain seçildi` or `Filtreye uyan 2 domainin tümü seçildi`, the confirm names that
  number (`2 domain Coolify’e bağlansın mı?`), and the widget shows **Coolify’e bağla · 2 domain**
  finishing as **Bitti** — not 30 per-row clicks and not a fake rebuild.
- **How it works:** `all=1` re-resolves through `SiteDomain::matchingListFilters($q, $unbound)`,
  the same two filters the toolbar posts, so the P0-1 contract holds here. `DomainBindSweep`
  groups selected unbound hosts by site and calls `SiteLanding::syncCoolifyDomains` once per
  site: Coolify's payload is the whole host list, so two aliases of one site are one PATCH.
  Already-bound selected hosts increment `ok` and never leave Plane. A 429 rides
  `PacedFanout` into `skipped` (`atlandı (istek sınırı)`); a host with no Coolify app is
  `unready`, a separate sentence, so it cannot borrow the throttle note. Job type
  `domains.bulk_bind` is **not** trigger-only: `setDomains` is a PATCH, not a deploy.
- **Decisions worth knowing:**
  1. **Counts are domains, Coolify calls are sites.** The confirm and the widget subject name
     hosts; the sweep's HTTP cost is unique sites. That is the only way the confirm can keep
     P0-1's promise without lying about how many Coolify writes happened.
  2. **`ops-ui.js` now matches `name$="_ids[]"`**, so Domains can use `domain_ids[]` without a
     second bulk-bar script. Sites `site_ids[]` still match.
  3. **Temporary hosts stay selectable** when they appear on a search, matching the per-row
     bind. The unbound filter still excludes them, as it did before.
- **Files:** `app/Models/SiteDomain.php` (`scopeMatchingListFilters`), new
  `app/Services/Domains/DomainBindSweep.php`, new
  `app/Http/Requests/Ops/BulkDomainIdsRequest.php`,
  `app/Http/Controllers/Ops/DomainController.php`, `routes/ops/domains.php`,
  `app/Services/Ops/OpsJobRunner.php`, `app/Models/OpsBackgroundJob.php`,
  `resources/views/ops/domains/_region.blade.php`, `public/js/ops-ui.js`,
  `lang/{tr,en}/{domains,ops}.php`, `docs/modules/ops-sites.md`,
  `docs/modules/ops-list-async.md`, `docs/plans/progress-ledger.md`.
- **Tests:** new `DomainBulkBindTest` — **10 passed**. Filtered total on the bar, counted
  confirm, `all=1` + `unbound` queues only the unbound set, two aliases one PATCH, already-bound
  is `Http::assertNothingSent()`, 429 is `atlandı` with `verified_at` still null, no-app is
  `unready` not a throttle skip, Turkish copy for a `locale=tr` operator, Viewer sees no bar,
  async region reships `data-bulk-total`, widget status is **Bitti**.
  `TruthfulJobCompletionTest` (9) now includes `domains.bulk_bind` in the Plane-finished list.
  `SiteDomainReconcileTest` (3), `OpsListFragmentTest` (7), `SiteBulkSelectionScopeTest` (5)
  unchanged and green. `vendor/bin/pint` clean on this slice's PHP.
- **Docs + ledger:** updated.
- **Notes:** P1-9 (`/`, `g` chords, Ctrl+K palette) was already mid-flight in
  `ops-shortcuts.js` / `shortcuts-overlay` when this slice started; those files were not
  opened. Tick3 may still be finishing the palette — do not merge a second `keydown`
  listener into `ops-ui.js`.

### P2-18 (Domains) · Empty states that name their cause

- **Operator-visible change:** a filtered miss on `/domains` no longer reuses the empty-registry
  hint. It shows the registry size, one removable chip per active filter, and **Filtreleri
  temizle** as the primary action. An empty registry offers **Domain ekle** (inline form) and
  **Coolify’dan içe aktar**; a viewer is told the role is read-only.
- **How it works:** the same `ops.partials.filter-chips` primitive Sites already uses.
  `DomainController::activeListFilters()` builds `{key,label,value,url}` for `q` and `unbound`.
  `totalDomains` is the unfiltered registry count when the filtered page is empty, so the hint
  cannot collapse to zero.
- **Files:** `app/Http/Controllers/Ops/DomainController.php`,
  `resources/views/ops/domains/_region.blade.php`, `public/css/ops-ui.css`
  (`.empty-panel-form`), `lang/{tr,en}/domains.php` (`empty.filtered_*`, `empty.import`,
  `filter_search`).
- **Tests:** new `DomainListEmptyStatesTest` — **6 passed** (empty registry offers create +
  Coolify import, viewer sees the read-only reason, filtered-empty names filters + registry
  size, each chip drops only itself while clear-all is the primary button, the async region
  carries the same states, Turkish copy for a `locale=tr` operator).
- **Docs + ledger:** updated (same ledger entry as P1-11).
- **Notes:** Coolify inventory and mail servers still need this pass. Themes is Done below.
  Mail-servers was just touched by P2-19; leave it for a worker that is not also on the Posta
  pages.

### P1-10 · Stale-data freshness badges

- **Operator-visible change:** health, live and publish no longer hide age in a `title`. Each
  cell shows a relative age (`4 sa önce`) with the absolute time in the tooltip, and a visible
  **Eski** word when the stamp is older than 2× the clamped agent poll window. A site that has
  never been probed reads `Hiç` / `Bilinmiyor` and is never flagged stale.
- **How it works:** `App\Support\OpsFreshness` is the one primitive (`pollWindowMinutes()`,
  `staleAfterMinutes()`, `describe()`). The badge is `<x-ops.freshness :at="..." :missing="..."/>`.
  Live has no scheduler of its own; the same 2× window means "nobody probed recently." The
  fleet unhealthy KPI still uses `ops.agent.stale_after_minutes` (30) inside
  `SiteHealthEvaluator` — that is a stronger verdict, not this badge.
- **Files:** `app/Support/OpsFreshness.php`, `resources/views/components/ops/freshness.blade.php`,
  `resources/views/ops/sites/_cell.blade.php`, `_agent-health.blade.php`,
  `_publish-state.blade.php`, `public/css/ops-ui.css`, `lang/{tr,en}/ops.php`
  (`freshness.just_now|minutes|hours|days|stale`), `docs/modules/ops-sites.md`.
- **Tests:** new `OpsFreshnessTest` — **4 passed**. New `SiteFreshnessBadgeTest` — **4 passed**
  (relative label, **Eski** when over the window, null never stale, health column preference
  required). `SiteDetailTest` (6), `SiteListPreferencesTest` (15), `SitePublishStateTest` (17),
  `SiteHealthEvaluatorTest` (3) unchanged and green.
- **Docs + ledger:** updated.
- **Notes:** the `updated` list column is still raw `Y-m-d H:i` — out of the original item.
  Do not fold KPI stale into this 2× window; they answer different questions.

### P2-18 (Themes) · Empty states that name their cause

- **Operator-visible change:** a filtered miss on `/themes` no longer reuses the empty-catalog
  hint. It shows the catalog size, one removable chip per active filter, and **Filtreleri
  temizle** as the primary action. An empty catalog offers **Katalogu senkronla** for writers;
  a viewer is told the role is read-only.
- **How it works:** the same `ops.partials.filter-chips` primitive Sites and Domains already
  use. `ThemeController::activeListFilters()` builds `{key,label,value,url}` for search and
  visibility. `totalThemes` is the unfiltered catalog count when the filtered page is empty.
- **Files:** `app/Http/Controllers/Ops/ThemeController.php`,
  `resources/views/ops/themes/_region.blade.php`, `lang/{tr,en}/themes.php`
  (`filter_search`, `empty.filtered_hint`, `empty.filters_label`).
- **Tests:** new `ThemeListEmptyStatesTest` — **5 passed**. `ThemeCatalogPageTest` (6)
  unchanged (`filtered_title` still the same key).
- **Docs + ledger:** updated.
- **Notes:** Coolify inventory still needs this pass. Mail servers is Done below.

### P1-14 (mail servers) · List controls

- **Operator-visible change:** `/mail-servers` is no longer Ctrl+F. Search and an enabled /
  disabled filter update the table in place; without JS they are ordinary GET links. The
  platform-mail chip from P2-19 stays above the toolbar and is not part of the swapped region.
- **How it works:** `ListFragment::respond` on `MailServerOpsController@index`. `q` matches
  `name` and `mail_domain`. `status` is allowlisted `enabled` / `disabled`. Pagination is the
  shared `ops.partials.pagination` at 25 rows.
- **Files:** `app/Http/Controllers/Ops/MailServerOpsController.php`,
  `resources/views/ops/mail-servers/{index,_region}.blade.php`, `lang/{tr,en}/mail.php`,
  `docs/modules/mail-servers.md`, `docs/modules/ops-list-async.md`.
- **Tests:** `OpsListFragmentTest` now includes `/mail-servers` (fragment contract, no
  sidebar, no platform-mail chip in the region, shared pager). `MailServerOpsTest` (8)
  unchanged and green.
- **Docs + ledger:** updated.
- **Notes:** Coolify inventory-show is still a bare table on a tabbed detail page — that
  half of P1-14 was left alone so this slice would not fight the connection show markup.

### P2-18 (mail servers) · Empty states that name their cause

- **Operator-visible change:** a filtered miss no longer reuses "Henüz posta sunucusu yok".
  It shows the registry size, one removable chip per active filter, and **Filtreleri
  temizle** as the primary action. An empty registry offers **Yeni posta sunucusu**; a
  viewer is told the role is read-only.
- **How it works:** `MailServerOpsController::activeListFilters()` builds chips for `q` and
  `status`. `totalServers` is the unfiltered registry count when the filtered page is empty.
- **Files:** same as P1-14 mail. `lang/{tr,en}/mail.php` (`empty_filtered_*`,
  `empty_filters_label`).
- **Tests:** new `MailListEmptyStatesTest` — **7 passed** (empty registry offers create,
  viewer sees the read-only reason, search/status keep matching rows, filtered-empty names
  filters + registry size, each chip drops only itself, async region, Turkish copy for a
  `locale=tr` operator).
- **Docs + ledger:** updated (same ledger entry as P1-14 mail).
- **Notes:** Coolify inventory is the last P2-18 page.

### P1-9 · Keyboard shortcuts and quick jump

- **Operator-visible change:** `Ctrl/⌘+K` opens a labelled **Hızlı atla** palette. Typing
  searches top-level pages plus sites, domains and themes; arrow keys select, Enter opens,
  Esc / outside click closes. The `?` overlay now documents `Ctrl` `K` next to `/` and the
  `g` chords. `/` and `g` then `s`/`d`/`t`/`f` were already in the tree.
- **How it works:** `GET /palette?q=` (`ops.palette`, auth required) is the only search the
  script runs. Empty `q` returns nav pages a Viewer can already open — never `/sites/create`
  or `/jobs`. A non-empty `q` reuses `Site::matchingListFilters`, `SiteDomain::matchingListFilters`
  and `Theme::matchingListFilters` (Themes list search is now that scope), limit 8 per group.
  Bound domains jump to the site; an unbound host opens `/domains?q=`. `%`/`_` stay literal,
  same as P1-8. The script never builds a URL.
- **Decisions worth knowing:**
  1. **`/` and `g` stay off while typing; `Ctrl/⌘+K` still opens from a field.** The backlog
     "never swallow a keystroke in an input" is for bare keys. The confirm modal still owns
     the keyboard.
  2. **Palette JS is its own file** (`ops-palette.js`), not a second listener in `ops-ui.js`.
     Opening the palette closes the `?` overlay via `PlaneShortcuts.close()`.
- **Files:** `app/Services/Ops/PaletteSearch.php`, `app/Http/Controllers/Ops/OpsPaletteController.php`,
  `app/Models/Theme.php` (`scopeMatchingListFilters`), `app/Http/Controllers/Ops/ThemeController.php`,
  `routes/web.php`, `resources/views/ops/partials/{palette,shortcuts-overlay}.blade.php`,
  `resources/views/layouts/ops.blade.php` (include + script only), `public/js/ops-palette.js`,
  `public/css/ops-ui.css`, `lang/{tr,en}/ops.php` (`shortcuts.palette`, `palette.*`).
- **Tests:** new `PaletteSearchTest` — **9 passed**. `KeyboardShortcutsTest` (6) now pins the
  palette markup, the `Ctrl K` row, and the search URL on every ops page. Neighbours
  `ThemeCatalogPageTest` (6), `ThemeListEmptyStatesTest` (5), `SiteFleetSearchTest` (10),
  `OpsListFragmentTest`, `NavPagesTest`, `SiteCrudTest` green.
- **Docs + ledger:** updated.
- **Notes:** Coolify inventory is still the last P2-18 / P1-14 leftover. Mail-servers was
  landed by the other worker and was not opened here except a pint `line_ending` on
  `MailListEmptyStatesTest.php`.

### P1-14 leftover + P2-18 leftover (Coolify inventory) · List controls and two-cause empty

- **Operator-visible change:** `/coolify/{connection}` inventory is no longer Ctrl+F. Search, a kind filter and an active/inactive filter update the four allowlists in place; without JS they are ordinary GET controls and the page opens on the inventory tab. An empty connection offers **Sync** (a viewer is told the role is read-only). A filtered miss names the inventory size, one removable chip per filter, and **Filtreleri temizle**. A server/project/env/git detail with no linked sites now offers Sync + back, not a title alone.
- **How it works:** `CoolifyInventoryQuery` filters the already-loaded allowlists (`q` on name / uuid / IP / project / git kind, allowlisted `kind` and `status`). `ListFragment::respond` on `CoolifyConnectionController@show` swaps `ops.coolify._inventory-region` only. Defaults still read the unfiltered relations. Region requests skip `applyUnambiguousDefaults()` so typing cannot persist. Unknown `kind` / `status` values are dropped. `%` is literal (in-memory `str_contains`). Pagination is omitted: four collections cannot share one pager without becoming a different page.
- **Files:** `app/Support/Lists/CoolifyInventoryQuery.php`, `app/Http/Controllers/Ops/CoolifyConnectionController.php`, `resources/views/ops/coolify/{show,_inventory-region,inventory-show}.blade.php`, `lang/{tr,en}/coolify.php`, `docs/modules/{ops-list-async,coolify-client}.md`, `docs/plans/progress-ledger.md`.
- **Tests:** new `CoolifyInventoryListTest` — see seventh verification run. `OpsListFragmentTest` now includes `/coolify/{connection}`.
- **Docs + ledger:** updated.
- **Notes:** P1-15 is in flight on another worker. Fleet dashboard still has no toolbar.

### P1-10 leftover · `updated` column is a relative age, never **Eski**

- **Operator-visible change:** the optional Sites **Güncellendi** column no longer prints `Y-m-d H:i`. It shows the same relative age as health/live/publish (`4 sa önce`, absolute time in the tooltip) and never the **Eski** word — a site nobody edited for four hours is not a broken poll.
- **How it works:** `<x-ops.freshness>` gained `markStale` (default true). `OpsFreshness::describe($at, markStale: false)` keeps the relative label and forces `stale=false`. The `updated` cell is the only caller that turns it off.
- **Files:** `app/Support/OpsFreshness.php`, `resources/views/components/ops/freshness.blade.php`, `resources/views/ops/sites/_cell.blade.php`, `docs/modules/ops-sites.md`.
- **Tests:** `OpsFreshnessTest` and `SiteFreshnessBadgeTest` extended — see seventh verification run.
- **Docs + ledger:** updated.

### P1-15 · Bulk pin and auto-deploy stop guessing

- **Operator-visible change:** pinning 200 sites no longer offers commits from the 25 on
  screen, and auto-deploy is no longer one **Aç/Kapa** that turns a mixed selection off.
  The operator types a SHA or tag (page SHAs are suggestions only when the filter fits on
  this page) and chooses **Oto-deploy aç** or **Oto-deploy kapat**, each with its own
  counted confirm.
- **How it works:** `SiteController::bulkPinSuggestions()` returns unique page SHAs only
  when `!$sites->hasMorePages()`; otherwise the bar is a text input plus
  `site_ops.bulk.ref_all_hint`. `BulkAutoDeploySiteRequest` requires `enabled`.
  `CoolifyDeploySettings::toggleEnabledFor()` is deleted — it was a Coolify GET per site
  to guess mixed → off. The queued job payload always carries the flag.
- **Files:** `app/Http/Controllers/Ops/{SiteController,SiteCoolifyOpsController}.php`,
  new `app/Http/Requests/Ops/BulkAutoDeploySiteRequest.php`,
  `app/Services/Ops/OpsJobRunner.php`, `app/Services/Sites/CoolifyDeploySettings.php`,
  `resources/views/ops/sites/_region.blade.php`, `public/css/ops-ui.css`
  (`.sites-bulk-pin*`), `lang/{tr,en}/site_ops.php` (`ref_explicit`, `ref_all_hint`),
  `docs/modules/ops-sites.md`, `docs/plans/progress-ledger.md`.
- **Tests:** `SiteBulkActionsTest` — **12 passed** (was 9): explicit on and off, missing
  `enabled` is 422 and `Http::assertNothingSent()`, a 30-site list has no
  `<select name="ref"` and no page SHA, a single-page list offers a datalist, `all=1` +
  `filter_channel=beta` pins 26 sites to the typed ref and spares the rest.
  `SiteBulkSelectionScopeTest` (5), `SiteBulkDangerMenuTest` (5), `SiteDetailTest` (6)
  updated for the split confirms. `vendor/bin/pint --dirty` clean.
- **Docs + ledger:** updated.
- **Notes:** the sibling wave-4 Coolify worker saw this slice mid-flight and left these
  files alone. `toggleEnabledFor` was also a Coolify-pressure bug: one mixed toggle
  GETs every selected app before deciding to turn them off.

### P2-17 · Confirm matrix (Sites)

- **Operator-visible change:** every confirm on the Sites list and site detail now has a
  title, a button label, and an explicit danger flag. Row App-health fixes read as a
  sentence (`Beyazlar üzerinde “Tekrar deploy” çalıştırılsın mı?`) instead of
  `Tekrar deploy — Beyazlar?`. Bulk branch / redeploy / follow HEAD / pin are danger
  because they start builds across the selection; **Aç** is not.
- **How it works:** one rule, documented in `ops-sites.md`: danger = delete, unpublish,
  stop, secret rotate/inject, or a bulk Coolify build. `ConfirmMatrixTest` walks the
  rendered `<form>` / `<button>` tags. Coolify / mail / Themes pages outside Sites were
  left for a later pass — the sibling was on Coolify inventory.
- **Files:** `resources/views/ops/sites/{_region,index,show,_header-actions,_coolify-ops,_agent-health,_channel-switch,_themes,_admins,_mail}.blade.php`,
  `lang/{tr,en}/sites.php` (`app_health.confirm_fix`), new
  `tests/Feature/Ops/ConfirmMatrixTest.php`, `docs/modules/ops-sites.md`.
- **Tests:** new `ConfirmMatrixTest` — **3 passed**. Neighbours in the same run
  (`SiteBulkActionsTest` 12, `SiteBulkDangerMenuTest` 5, `SiteDetailTest` 6,
  `SiteBulkSelectionScopeTest` 5) green.
- **Docs + ledger:** updated.
- **Notes:** Coolify connection/inventory confirms were not walked. A second pass can
  extend the same walker to those pages now that inventory is no longer a bare table.

### P1-13 · Job and audit history is a page

- **Operator-visible change:** dismissing the jobs widget no longer loses the night. **Geçmiş**
  is in the sidebar under Filo. One table lists ops jobs, Coolify deploys and audit rows,
  newest first. Search / kind / outcome / actor / site update in place; a Viewer can open
  another operator's job; a secret in a payload or audit `after` reads `[redacted]`.
- **Visibility rule (the decision the backlog asked for):** `/activity` is a fleet log.
  Every ops role that can see Sites can read every row, including jobs they did not start.
  `GET /jobs` stays personal (`actor = me`, last two hours, `ops.write`). A Viewer who
  opens `/activity/jobs/{id}` gets the Blade page and is still 403 on the JSON widget
  endpoint.
- **How it works:** `ActivityFeed` unions three narrow index queries and paginates the
  merge at 25. Hydration is three `whereIn` loads, not N+1. Job and audit details are new
  Blade pages; deploys reuse `ops.sites.deployments.show`. Unknown filter values drop.
  `%`/`_` stay literal. Ctrl/⌘+K lists Geçmiş with the other top-level pages and never
  offers `/jobs`.
- **Files:** new `app/Support/Ops/{ActivityFeed,ActivityFilters,ActivityRow}.php`, new
  `app/Http/Controllers/Ops/ActivityController.php`, new
  `resources/views/ops/activity/{index,_region,job,audit}.blade.php`,
  `app/Models/AuditLog.php` (`forActor` / `forSite` / `matchingSearch`, `actionLabel`,
  `outcome`), `routes/web.php`, `resources/views/layouts/ops.blade.php` (nav item),
  `app/Services/Ops/PaletteSearch.php` (one catalog row), `lang/{tr,en}/ops.php`
  (`nav.activity`, `activity.*`), `docs/modules/ops-activity.md`,
  `docs/modules/ops-list-async.md`, `docs/README.md`, `docs/plans/progress-ledger.md`.
- **Tests:** new `ActivityFeedTest` — **11 passed**. Guest redirected; empty vs filtered-empty;
  merge order; Viewer sees another operator's job and is 403 on `GET /jobs/{id}`;
  kind/outcome/actor/site allowlists (unknown values widen); `%` is literal; fragment
  omits the shell and ships the shared pager; row `data-href` targets; secrets redacted
  on the list and both detail pages; nav + palette Turkish **Geçmiş**, never `/jobs`.
  Neighbours `PaletteSearchTest` (9), `KeyboardShortcutsTest` (6), `NavPagesTest` (1),
  `AuditLogTest` (2) unchanged and green.
- **Docs + ledger:** updated.
- **Notes:** tick 4 left Coolify inventory, agent-secret, bulk defaults and confirms alone
  (P1-15 / P2-17 Sites landed on another worker while this ran). No `g` then `a` chord —
  that would have reopened P1-9 files. Fleet dashboard still has no toolbar.

### P1-12 · Agent secret health across the fleet

- **Operator-visible change:** Filo now answers "hangi sitelerde `CONTROL_PLANE_AGENT_SECRET` yok, hangilerinde CMS henüz 200 dönmedi?" — **Agent gizli anahtarı** kartı `yok` / `doğrulanmamış` / `tamam` diye ayırır, her kova `/sites?agent=` listesine gider, ve yazan operatör **Gizli anahtar üret ve gönder** ile yalnızca eksikleri basar. Var olan anahtar döndürülmez; değer HTML / audit / widget’ta görünmez.
- **How it works:** `Site::missingAgentSecret` / `unverifiedAgentSecret` / `verifiedAgentSecret` are SQL. Verified is a stored secret plus last health `http_status=200` or `status=ok`. A later timeout overwrites that payload, so a once-ok site flips back to `doğrulanmamış` — there is no `agent_secret_verified_at`. `FleetDashboardKpis::agentSecretCounts()` and `matchingListFilters(..., agent:)` share those scopes. Bulk `POST /sites/bulk/agent-secret` (job `sites.bulk_inject_agent_secret`) filters to missing, calls `SiteAgentSecretInjector` with `rotate: false`, and is Plane-finished (**Bitti**, not **Tetiklendi**). Confirm is danger (P2-17). KPI drill-down links only when that bucket’s count is > 0.
- **Files:** `app/Models/Site.php` (`AGENT_FILTERS`, scopes, `agentSecretFleetState()`), `app/Services/Fleet/FleetDashboardKpis.php`, `app/Services/Sites/SiteAgentSecretSweep.php`, `app/Http/Controllers/Ops/{Site,Fleet}Controller.php`, `resources/views/ops/dashboard/{kpis,attention}.blade.php`, `resources/views/ops/sites/{index,_region}.blade.php`, `lang/{tr,en}/{fleet,sites,ops}.php`, `docs/modules/{ops-sites,agent-client}.md`, `docs/runbooks/agent-secret-inject.md`, `docs/plans/progress-ledger.md`.
- **Tests:** new `AgentSecretFleetTest` — **11 passed** (buckets agree with the filter, card links, no leak, bulk never rotates, mixed selection one Coolify PATCH, JSON job is Plane-finished, viewer 403, Turkish `Gizli anahtar üret ve gönder`, fragment reships `filter_agent`, a later timeout is `unverified`). Neighbours: `FailedDeployFilterTest` 10 (failed-deploy drill-down assertion no longer treats agent KPI links as a lie), `FleetFailedDeployWindowTest` 6 (attention names 16 → 24 for the third capped list), `FleetDashboardTest` 4, `ConfirmMatrixTest`, `SiteBulkDangerMenuTest` 5, `SiteBulkSelectionScopeTest` 5, `TruthfulJobCompletionTest` 9. `vendor/bin/pint` clean on this slice. No commit.
- **Docs + ledger:** updated.
- **Notes:** sibling had the model / card / sweep in the tree; this tick closed duplicate lang keys, `data-confirm-danger="true"`, neighbour tests, docs, and the morning-report entry. **P1-6 is in flight** on `SiteSavedViews` / `SiteController@index` — those files were not claimed here beyond pint touching them. Do not start a second saved-views copy. `ActivityFeed*` was left alone.

### P2-17 leftover · Coolify / mail / Themes confirms

- **Operator-visible change:** disconnecting Coolify, deleting a mail server, revoking a theme allowlist row, or disconnecting theme git now uses the same confirm contract as Sites — title, label, and an explicit **danger** flag — so the modal is not a quiet OK.
- **How it works:** those four forms already had title + label; they were missing `data-confirm-danger="true"`. Coolify sync / make-default stay `false`. `ConfirmMatrixTest` walks the rendered Coolify / mail / theme / theme-git pages the same way it walks Sites.
- **Files:** `resources/views/ops/{coolify/show,mail-servers/show,themes/show,themes/git/show}.blade.php`, `tests/Feature/Ops/ConfirmMatrixTest.php`, `docs/modules/{ops-sites,coolify-client}.md`, `docs/plans/progress-ledger.md`.
- **Tests:** `ConfirmMatrixTest` — **6 passed** (3 Sites + 3 leftover surfaces). Neighbours `ThemeCatalogPageTest` (tabs/revoke), `MailServerOpsTest` (show), `CoolifyConnectionsTest` (disconnect) unchanged and green.
- **Docs + ledger:** updated.
- **Notes:** Cloudflare account/zone/DNS delete confirms are still outside this pass (not in the leftover list). P1-6 files were not opened.

### P2-17 leftover · Cloudflare delete confirms

- **Operator-visible change:** removing a Cloudflare account, deleting a zone, deleting a live DNS row, or deleting a Deamon DNS default now opens the same **danger** confirm as Coolify disconnect — not a quiet OK. Applying defaults and resetting the builtin template stay ordinary confirms.
- **How it works:** those four forms already had title + label; they were missing `data-confirm-danger="true"`. `ConfirmMatrixTest` walks account show, zone show, and the defaults page the same way it walks Sites / Coolify / mail / Themes.
- **Files:** `resources/views/ops/cloudflare/{show,zone,defaults}.blade.php`, `tests/Feature/Ops/ConfirmMatrixTest.php`, `docs/modules/{ops-sites,cloudflare-client}.md`, `docs/plans/progress-ledger.md`.
- **Tests:** `ConfirmMatrixTest` — **7 passed** (3 Sites + Coolify + mail + Themes + Cloudflare). Neighbours `CloudflareSettingsTest` (11) and `CloudflareDnsOpsTest` (10) unchanged and green.
- **Docs + ledger:** updated.
- **Notes:** P1-6 files were not opened. Site Cloudflare zone **create** on site detail stays `danger=false` (it is not a delete).

### P1-14 leftover · Fleet dashboard attention toolbar

- **Operator-visible change:** Filo is no longer Ctrl+F. Search and a card filter update the attention lists in place; without JS they are ordinary GET controls. A clean fleet says so and offers **Siteleri aç**. A filtered miss names how many attention rows existed, shows chips, and **Filtreleri temizle**. KPI numbers do not change while typing.
- **How it works:** not a first-class `/fleet` site list — `/sites` stays that. `FleetAttentionQuery` filters the four cards already on the page (`q` on name / domain / slug / failed-deploy error; `kind=unhealthy|failed|agent|dockerfile`). `ListFragment` swaps only `ops.dashboard.attention`; KPIs stay in the shell and `snapshot()` is skipped on a region request (P0-2). Search runs **before** `ops.fleet.attention_limit` so a site in `+N` is findable. Pagination is omitted. Unknown `kind` is dropped. `%`/`_` stay literal. Agent-secret bulk inject stays on the unfiltered card only so a search cannot shrink `all=1` + `filter_agent=missing`.
- **Files:** new `app/Support/Lists/FleetAttentionQuery.php`, `app/Http/Controllers/Ops/FleetController.php`, `app/Services/Fleet/FleetDashboardKpis.php` (optional cap `0`), `resources/views/ops/fleet/index.blade.php`, `resources/views/ops/dashboard/attention.blade.php`, `lang/{tr,en}/fleet.php`, `tests/Feature/Ops/{FleetDashboardListTest,OpsListFragmentTest}.php`, `docs/modules/ops-list-async.md`, `docs/plans/progress-ledger.md`.
- **Tests:** new `FleetDashboardListTest` — **6 passed**. `OpsListFragmentTest` — **7 passed** (now covers `/`). Neighbours `FleetDashboardTest` 4, `FleetFailedDeployWindowTest` 6, `AgentSecretFleetTest` 11, `ConfirmMatrixTest` 7 green. `vendor/bin/pint --dirty` clean. No commit.
- **Docs + ledger:** updated.
- **Notes:** this is the design the morning report asked for before shipping a toolbar. It is adoption of Coolify inventory’s “not a first-class list URL” pattern, not a second Sites page.

### ADR-10 · Health / app-issue filters need a persisted verdict

- **Operator-visible change:** none. The unhealthy `+N` line stays plain text; there is still no `health=` or `app=` query param.
- **How it works:** wrote [ADR-10](../../decisions/adr-10-health-app-filter-verdict.md). Filtering those PHP verdicts in the list path would undo P0-2. The next implementer adds a column written by `InspectSiteAppHealthJob`, then a SQL filter. Do not scan the fleet on keystroke.
- **Files:** `docs/decisions/{README.md,adr-10-health-app-filter-verdict.md}`, `docs/modules/ops-sites.md`, `docs/README.md`, `docs/plans/progress-ledger.md`.
- **Tests:** none (decision only).
- **Docs + ledger:** updated.
- **Notes:** estimate to land the column remains **M**.

### P1-6 · Saved site views

- **Operator-visible change:** the daily triage sets no longer have to be retyped or bookmarked. A chip row under the Sites toolbar offers **Tümü** / **Sorunlu** / **Yayında değil** / **Dockerfile kalanları** plus up to five named presets; **Görünüm kaydet** stores the current filters, columns and sort on the account. Marking one default means opening Siteler lands on that set, including hidden columns.
- **How it works:** `SiteSavedViews` lives in `users.list_preferences.sites.views` (same JSON row as the column picker). Built-ins are query links (`status=error`, `publish=draft`, `pack=dockerfile`). A saved view chip is `?view={id}&…filters…`. Bare `/sites` 302s to the default; a region request applies it in place so `ops-list.js` does not bounce. `?view=all` is the explicit «everything this time» — that is what **Filtreleri temizle** uses, so clearing a default does not put it back. A dead channel (or any allowlisted value that vanished) is dropped the way a hidden-column sort falls back. First apply of a named view writes its snapshot into the live layout (`applied_view`); later column-picker saves win until a different view is applied. `pack=dockerfile` is `withDockerfileBuildPackWarning()`; `filter_pack` rides with bulk `all=1`. Chips sit in the region (active state re-renders with the table); the save form stays in the toolbar so a search keystroke does not wipe the name.
- **Files:** new `app/Support/Lists/SiteSavedViews.php`, `app/Support/Lists/SiteListView.php`, `app/Http/Controllers/Ops/{SiteController,SiteListPreferencesController,SiteCoolifyOpsController,SiteAppHealthController,SitePublishStatusController}.php`, `app/Http/Requests/Ops/{BulkSiteIdsRequest,BulkSitePublishStatusRequest}.php`, `app/Models/Site.php` (`PACK_FILTERS` only — agent-secret scopes untouched), `routes/ops/sites.php`, `resources/views/ops/sites/{index,_region,_saved-views,_saved-views-form}.blade.php`, `public/js/ops-list.js`, `public/css/{ops.css,ops-ui.css}`, `lang/{tr,en}/sites.php`, `docs/modules/{ops-sites,ops-list-async}.md`, `docs/plans/progress-ledger.md`.
- **Tests:** new `SiteSavedViewsTest` — **14 passed** (builtin chips are query links; save persists filters/columns/sort/default; bare `/sites` 302s including the `updated` column; `view=all` does not apply the default; dead channel dropped; dockerfile leftover pack; region swap; fragment applies default without redirect; sixth view 422 unless the name is overwritten; AJAX `refresh_list`; delete clears default; per-user; Viewer may save; unknown posted values dropped). `SiteListPreferencesTest` — **16 passed** (reset does not wipe views). `SiteListEmptyStatesTest` — **5 passed** (clear-all is `view=all`). Neighbours green: `FailedDeployFilterTest` 10, `AgentSecretFleetTest` 11, `OpsListFragmentTest` 7, `ConfirmMatrixTest` 6, `SiteBulkSelectionScopeTest` 5, `SiteFleetSearchTest` 10, `SiteListAppHealthCountCostTest` 6. `vendor/bin/pint --dirty` clean. No commit.
- **Docs + ledger:** updated.
- **Notes:** P1-12 / `SiteAgentSecretSweep` were left alone; `PACK_FILTERS` is an additive 7th arg on `matchingListFilters`. P2-17 leftover (Coolify / mail / Themes) was already in the tree when this landed — not re-walked. Cloudflare deletes remain outside the confirm matrix.

### Empty-state copy (wave 5 leftover) · Operator hints, not test jargon

- **Operator-visible change:** three empty states that still sounded like leftovers now read as operator Turkish. Mail-servers registry-empty says **posta kutusu**, not «kutu açabilmesi». A Coolify allowlist that is empty while other kinds have rows says **Bu listede henüz kayıt yok. Envanteri senkronlayarak Coolify’den çekin.**, not «Liste boş». Themes catalog-empty no longer mentions `Http::fake`. Coolify connections-empty says **Jeton Ayarlar’da değil**.
- **How it works:** copy-only. No Blade contract change. The inventory region already hides empty allowlists when filters are active, so the allowlist string is only the unfiltered per-kind miss.
- **Left alone on purpose:** `Site.php`, `SiteFilterVerdict`, the `2026_09_13_023500` verdict migration, `InspectSiteAppHealthJob`, `sites/index.blade.php`, `attention.blade.php`, `lang/{tr,en}/sites.php` — a sibling is mid-edit landing ADR-10 (`HealthAppFilterTest` appeared while this tick ran). Platform-mail **nav** chip stays off the sidebar (P2-19: settings + `count()` on every layout render is the class of cost P0-2 removed). Page chips on `/mail-servers` and `/platform-mail` are already there.
- **ADR-11:** already written by another worker as [adr-11-dom-test-harness.md](../../decisions/adr-11-dom-test-harness.md) — skip Playwright / Dusk; land `node --test` + jsdom later (**M**). This tick started a second ADR-11 file and deleted it so the tree has one decision. Concur: do not land a runner in this dirty tree.
- **Files:** `lang/tr/{mail,coolify,themes}.php`, `lang/en/{coolify,themes}.php`, `docs/modules/{theme-catalog,coolify-client}.md`, `docs/decisions/README.md`, `docs/README.md`, `docs/plans/progress-ledger.md`.
- **Tests:** `ThemeListEmptyStatesTest` — **5 passed** (catalog hint must not contain `Http::fake`). `MailListEmptyStatesTest` — **8 passed** (new `posta kutusu` / not `kutu açabilmesi` under `locale=tr`). Combined **13 passed**, 73 assertions.
- **Docs + ledger:** updated.
- **Notes:** ~02:45 Istanbul. Not morning finalize.

### ADR-11 · DOM harness is Node tests, not Playwright (tick 5 / ~02:40)

- **Operator-visible change:** none. The widget and list JS still behave as they did; what changed is the rule for how the next slice is allowed to prove them.
- **How it works:** wrote [ADR-11](../../decisions/adr-11-dom-test-harness.md). Pest stays the source of truth for progressive enhancement (`data-*`, fragments, locale, `ConfirmMatrixTest`). Browser-only contracts (`advanceBackoff`, `__COUNT__` interpolate, `toolbarUrl`, `isTyping` / `confirmOpen`) land later under `node --test` in `tests/js/`, with jsdom only when a test needs `document`. Playwright, Dusk, Cypress, and Pest Browser are rejected for Plane v1. Do not add a bundler. Do not duplicate the algorithms inside the test tree. The harness itself is **not** built this tick — eight notes asked for the decision, not another runner in a dirty pile.
- **Also this tick:** `SiteListAppHealthCountCostTest::the_page_says_how_old_the_counts_are` was a clock flake (page `0 seconds ago`, assert `1 second ago`). Frozen `Carbon` / `CarbonImmutable` for the prime + render. No view or fixer change.
- **Left alone:** `SiteFilterVerdict`, the verdict migration, `InspectSiteAppHealthJob`, `HealthAppFilterTest`, `sites/index.blade.php` health/app selects — sibling landing ADR-10. A `pint --dirty` I ran for the flake test also touched line endings on those three files; whitespace only.
- **Files:** `docs/decisions/{README.md,adr-11-dom-test-harness.md}`, `docs/README.md`, `docs/modules/{ops-sites,ops-list-async}.md`, `docs/plans/progress-ledger.md`, `docs/superpowers/plans/2026-09-12-overnight-plane-backlog.md`, `tests/Feature/Sites/SiteListAppHealthCountCostTest.php`.
- **Tests:** focused overnight set — **232 passed, 1 failed**, then the flake fix → `SiteListAppHealthCountCostTest` **6 passed**. `node --check` on the six widget/list scripts: pass. Sibling `HealthAppFilterTest` probed only — **16 passed** (57 assertions); not this slice. `vendor/bin/pint` on the cost-test file: clean.
- **Docs + ledger:** updated.
- **Notes:** ~02:40 Istanbul. Not morning finalize. Do not fill the 07:00 wake table. Do not start `tests/js/` or a `package.json` in this tree.

### P1-7 leftovers · `health=` / `app=` persisted verdict (ADR-10 landed)

- **Operator-visible change:** the fleet **Sağlıksız** card now has **Tümünü gör** and a linked `+N site daha`, the same way failed deploys already did. Sites toolbar offers **Sağlık → Sağlıksız** and **App → App hatası var**. The header **App hatalarını düzelt** menu links **Sorunlu siteleri gör**.
- **How it works:** [ADR-10](../../decisions/adr-10-health-app-filter-verdict.md). Columns `health_unhealthy` / `health_verdict_at` and `app_has_issues` / `app_health_issue_count` / `app_health_verdict_at` are stamped by `SiteFilterVerdict` on the same `Site` save as the health / inspect payload (also status, secret, dockerfile notes, Coolify uuid). `SiteHealthChecker::check()` and `SiteAppHealthInspector::inspect()` / `refreshLocalCached()` call apply before save; `InspectSiteAppHealthJob` is that inspect path, not a Coolify-only read. `/sites?health=unhealthy` is `Site::scopeUnhealthy()` — persisted flag, or `status=error`, or stored agent-fail JSON, or the stale window — never a PHP walk. A site that goes stale after a healthy write still matches without rewriting the column. `/sites?app=issues` is `app_has_issues`. `FleetDashboardKpis` counts through the same unhealthy scope. `filter_health` / `filter_app` ride with bulk `all=1`. Unknown values widen. Header category counts stay the 60s P0-2 cache.
- **Files:** `database/migrations/2026_09_13_023500_add_health_app_verdicts_to_sites_table.php`, `app/Services/Sites/{SiteFilterVerdict,SiteAppHealthInspector}.php`, `app/Services/Agent/SiteHealthChecker.php`, `app/Jobs/InspectSiteAppHealthJob.php`, `app/Models/Site.php`, `app/Services/Fleet/FleetDashboardKpis.php`, `app/Support/Lists/{FleetAttentionQuery,SiteSavedViews}.php`, `app/Http/Controllers/Ops/{SiteController,SiteAppHealthController,SiteCoolifyOpsController,SitePublishStatusController,SiteListPreferencesController}.php`, `app/Http/Requests/Ops/{BulkSiteIdsRequest,BulkSitePublishStatusRequest}.php`, `resources/views/ops/sites/{index,_region,_saved-views-form}.blade.php`, `resources/views/ops/dashboard/{kpis,attention}.blade.php`, `lang/{tr,en}/sites.php`, `tests/Feature/Ops/{HealthAppFilterTest,SiteSavedViewsTest}.php`, `docs/decisions/adr-10-health-app-filter-verdict.md`, `docs/modules/{ops-sites,ops-list-async,agent-client,coolify-webhooks}.md`, `docs/plans/progress-ledger.md`.
- **Tests:** new `HealthAppFilterTest` — **18 passed** (68 assertions): filter set, stale-without-rewrite, card count equals list, KPI + overflow links, quiet card has no link, chip on filtered-empty, unknown values widen, region ships `filter_health` / `filter_app`, `all=1` stays inside each filter, health check + inspect + `InspectSiteAppHealthJob` stamp the columns, Fix App menu links, Turkish `Sağlıksız` / `App hatası var` / `Sorunlu siteleri gör`. No commit. No DOM harness (ADR-11 already decided skip).
- **Docs + ledger:** updated.
- **Notes:** finished ~02:50 Istanbul. Do not start a second verdict writer or a fleet scan. Do not land `tests/js/` tonight.

### ADR-11 · First `node --test` slice (tick 6 / ~02:50)

- **Operator-visible change:** none. Toolbar GET and widget poll still do the same thing; the helpers are no longer closed over inside the IIFEs.
- **How it works:** `public/js/ops-contracts.js` is a UMD (`globalThis.PlaneOpsContracts` / `module.exports`) loaded before the list and jobs scripts. `ops-list.js` calls `toolbarUrl`. `ops-jobs.js` calls `jobStateSignature`, `advanceBackoff`, `jobRowKey`, `diffJobRows`. `tests/js/ops-contracts.test.js` requires that file — no algorithm copy, no Playwright, no jsdom, no `package.json`. Remaining ADR-11 helpers (`__COUNT__` interpolate, `isTyping` / `confirmOpen`, `document.hidden`) stay later.
- **Left alone:** `SiteFilterVerdict`, the verdict migration, `InspectSiteAppHealthJob`, `HealthAppFilterTest`, `sites/index.blade.php` health/app selects — sibling ADR-10 (green; do not re-open).
- **Files:** `public/js/ops-contracts.js`, `public/js/ops-list.js`, `public/js/ops-jobs.js`, `resources/views/layouts/ops.blade.php`, `tests/js/ops-contracts.test.js`, `tests/Feature/Ops/KeyboardShortcutsTest.php` (docblock pointer only), `README.md`, `docs/README.md`, `docs/decisions/{README.md,adr-11-dom-test-harness.md}`, `docs/modules/{ops-sites,ops-list-async}.md`, `docs/plans/progress-ledger.md`, `docs/superpowers/plans/2026-09-12-overnight-plane-backlog.md`.
- **Tests:** `node --test tests/js/*.js` — **4 passed**, 0 failed (toolbar empties/`page`, signature on `queue_position`, backoff reset + 8-poll step, `12`/`"12"` row identity). `node --check` on `ops-contracts.js` `ops-list.js` `ops-jobs.js`: pass. Health/app Pest not re-run.
- **Docs + ledger:** updated.
- **Notes:** ~02:50 Istanbul. Orchestrator asked for this landing in the dirty tree; no commit, no PR, no push. Run: `node --test tests/js/*.js` (Windows Node 22 cannot load the directory as a module). Do not fill the 07:00 wake table.

### ADR-11 · Second `node --test` slice (tick 7 / ~02:55)

- **Operator-visible change:** none. Bulk confirms still interpolate the same tokens; `/` and `g` still stay off in a field; the confirm modal still owns the keyboard.
- **How it works:** `PlaneOpsContracts` gained `bulkScope`, `interpolateConfirm`, `isTyping`, `confirmOpen` (`CONFIRM_MODAL_SELECTOR`). `ops-ui.js` `setupBulkSelection` calls the first two (scope count + every `__COUNT__` / `__TARGET__` replace, including the summary line). `ops-shortcuts.js` and `ops-palette.js` call the guards — they were never in `ops-list.js`, so no list refactor. Duck-typed `isTyping` and a `querySelector` stand-in keep this slice off jsdom. No `package.json`. No Playwright. Turkish copy stays in Blade / Pest.
- **Left alone:** `SiteFilterVerdict`, `HealthAppFilterTest`, `document.hidden`, list `fetch` / `replaceState`.
- **Files:** `public/js/ops-contracts.js`, `public/js/ops-ui.js`, `public/js/ops-shortcuts.js`, `public/js/ops-palette.js`, `tests/js/ops-contracts.test.js`, `tests/Feature/Ops/KeyboardShortcutsTest.php` (docblock pointer only), `docs/README.md`, `docs/decisions/{README.md,adr-11-dom-test-harness.md}`, `docs/modules/{ops-sites,ops-list-async}.md`, `docs/plans/progress-ledger.md`, `docs/superpowers/plans/2026-09-12-overnight-plane-backlog.md`.
- **Tests:** `node --test tests/js/*.js` — **8 passed**, 0 failed. `node --check` on the four scripts: pass. Focused Pest: `KeyboardShortcutsTest` **6 passed** (47 assertions).
- **Docs + ledger:** updated.
- **Notes:** ~02:55 Istanbul. No commit, no PR, no push. Run: `node --test tests/js/*.js`. Do not fill the 07:00 wake table.

### Coolify test hints · operator Turkish, not `listServers` (~02:55)

- **Operator-visible change:** the Coolify connection overview hint and the next-action test hint now say **sunucu listesini sorgular**, not `listServers`. The Test button is unchanged.
- **How it works:** copy-only in `lang/{tr,en}/coolify.php`. The route is still `POST /coolify/{connection}/test` → `listServers` only.
- **Health / app verdicts:** Done already matches the tree — `SiteFilterVerdict`, the `2026_09_13_023500` migration, `HealthAppFilterTest` (18), ADR-10, and the ledger entry are present. No sparse fill. Those files were not opened.
- **Files:** `lang/{tr,en}/coolify.php`, `tests/Feature/Ops/CoolifyConnectionsTest.php`, `docs/modules/coolify-client.md`, `docs/plans/progress-ledger.md`.
- **Tests:** new case on `CoolifyConnectionsTest` — connection show under `locale=tr` sees `sunucu listesini` and must not contain `listServers`. Full suite + `node --test` this tick: see verification below.
- **Docs + ledger:** updated.
- **Notes:** ~02:55 Istanbul. Tiny leftover after the empty-state copy pass. No commit. Do not fill the 07:00 wake table.

### Jobs widget · Sadece hatalılar (~03:15)

- **Operator-visible change:** the jobs widget can now show **only failures**. After a bulk sweep the one `hata` is no longer buried under twenty `Bitti` rows. The header toggle **Sadece hatalılar** / **Failed only** polls `GET /jobs?failed=1`.
- **How it works:** only `failed=1` is the lane; `failed=yes` / junk widen to the mixed two-hour list. Jobs stay `actor = me`. Deployments are `status=failed` in the same window. The live Coolify queue is **not** merged — those rows are running work, and `widgetRows()` is a `GET /deployments` per connection (the P0-4 pressure). `queueStanding()` (SQL) still fills the header. `ops-jobs.js` persists the toggle in `sessionStorage` and builds the poll URL with `PlaneOpsContracts.jobsIndexUrl`. Client-side `isFailedStatus` hides non-failed rows already on screen. An empty failed-only list keeps the panel so the toggle stays reachable.
- **Files:** `app/Http/Controllers/Ops/OpsJobController.php`, `public/js/{ops-contracts,ops-jobs}.js`, `resources/views/ops/partials/jobs-widget.blade.php`, `public/css/ops-ui.css`, `lang/{tr,en}/ops.php`, `tests/Feature/Ops/JobsFailedFilterTest.php`, `tests/js/ops-contracts.test.js`, `docs/modules/ops-sites.md`, `docs/decisions/adr-11-dom-test-harness.md`, `docs/plans/progress-ledger.md`.
- **Tests:** new `JobsFailedFilterTest` — **5 passed** (this operator's failures only, unknown value widens, no Coolify queue read, widget declares the toggle, Turkish `Sadece hatalılar`). Neighbours `JobsPollPressureTest` (5) + `DeploymentPollWidgetTest` (12) green. `node --test tests/js/*.js` — **10 passed** (`isFailedStatus`, `jobsIndexUrl`).
- **Docs + ledger:** updated.
- **Notes:** ~03:15 Istanbul. ADR-11 third slice (still no jsdom / `package.json`). No commit, no PR, no push. Do not fill the 07:00 wake table.

### Site detail · Copy site ID and Coolify UUIDs (~03:30)

- **Operator-visible change:** site detail finally pastes the IDs operators already hunt for. The hero has **Site ID kopyala** and, when an app is attached, **Uygulama UUID kopyala**. Technical identifiers list the ULID plus Coolify app / server / project / environment UUIDs with a copy control on every filled value.
- **How it works:** `data-copy-value` on the shared `setupCopyButtons` primitive (`data-copy-target` for nameservers is unchanged). Missing Coolify UUIDs stay `—` and have no button, so a click cannot copy an empty string. Viewers get the same controls — these IDs are already on the page and in Geçmiş `?site=`.
- **Files:** `resources/views/ops/sites/show.blade.php`, `resources/views/components/ops/copy-id.blade.php`, `public/js/ops-ui.js`, `public/css/ops-ui.css`, `lang/{tr,en}/sites.php`, `tests/Feature/Ops/SiteDetailTest.php`, `docs/modules/ops-sites.md`, `docs/plans/progress-ledger.md`.
- **Tests:** `SiteDetailTest` — **9 passed** (was 6 before this tick; a sibling also added Coolify-link copy in the same hero): copy hooks + Turkish labels when UUIDs exist; no empty `data-copy-value` and no app-UUID button when they do not. Neighbours run with this tick below.
- **Docs + ledger:** updated.
- **Notes:** ~03:30 Istanbul. Not a second identity row. No commit. Do not fill the 07:00 wake table.

### Settings · Jump nav and search (~03:30)

- **Operator-visible change:** `/settings` is no longer Ctrl+F. A chip row jumps to env defaults / GitHub / customer defaults / Coolify. The search box filters those panels (and env keys such as `APP_KEY`). Ctrl/⌘+K **webhook** or **ortam** opens the matching hash.
- **How it works:** `SettingsJump` is the catalog. Hashes are ordinary links (no JS). The filter uses `PlaneOpsContracts.textMatches` (empty query keeps every section). A key substring also hides other env rows (`envRowVisible`); a section needle such as `ortam` does not blank the catalog. Palette adds a `settings` group only when `q` matches a section — empty query still lists only pages, never `#` URLs. Env-key haystack is the already-loaded catalog, not a second query.
- **Files:** `app/Support/Ops/SettingsJump.php`, `app/Http/Controllers/Ops/SettingsController.php`, `app/Services/Ops/PaletteSearch.php`, `resources/views/ops/settings/{index,partials/env-defaults,partials/env-default-row,partials/github}.blade.php`, `public/js/{ops-contracts,ops-ui}.js`, `public/css/ops.css`, `lang/{tr,en}/{settings,ops}.php`, `tests/Feature/Ops/SettingsJumpTest.php`, `tests/js/ops-contracts.test.js`, `docs/modules/{coolify-client,theme-catalog,ops-sites,ops-list-async}.md`, `docs/plans/progress-ledger.md`.
- **Tests:** new `SettingsJumpTest` — **7 passed**. `PaletteSearchTest` still 9 (empty query has no hashes). `SettingsEnvDefaultsTest` 4 (sibling added the env-row haystack case). `node --test` includes `textMatches` / `envRowVisible`.
- **Docs + ledger:** updated.
- **Notes:** ~03:30 Istanbul. Not a second Settings page. No commit.

### Domains · Unbound bulk says what bind will skip (~03:30)

- **Operator-visible change:** **Yalnızca bağlı olmayan** now confirms `N bağlı olmayan domain Coolify’e bağlansın mı?` A mixed list no longer sounds like a rewrite of hosts Coolify already has — the overlay and the bar hint say already-bound hosts are skipped and how many in the current filter are still unbound.
- **How it works:** copy only plus `$unboundInFilter` (`matchingListFilters($q, true)->count()` when the unbound box is off). Sweep behavior is unchanged: bound hosts stay a no-op. `all=1` still follows the toolbar filters.
- **Files:** `app/Http/Controllers/Ops/DomainController.php`, `resources/views/ops/domains/_region.blade.php`, `public/css/ops-ui.css`, `lang/{tr,en}/domains.php`, `tests/Feature/Ops/DomainBulkBindTest.php`, `docs/modules/ops-sites.md`, `docs/plans/progress-ledger.md`.
- **Tests:** `DomainBulkBindTest` — **11 passed** (was 10): unbound confirm + skip hint; mixed list Turkish skip count.
- **Docs + ledger:** updated.
- **Notes:** ~03:30 Istanbul. No commit.

### ADR-11 · Hidden-tab poll skip (~03:30)

- **Operator-visible change:** none. A background tab still costs Coolify nothing; the skip is no longer closed over inside the IIFE.
- **How it works:** `PlaneOpsContracts.pageIsHidden` / `shouldSchedulePoll`. `ops-jobs.js` `schedulePoll` / `startPoll` / `visibilitychange` call those. Tests pass `{ hidden: true }` — still no jsdom, no `package.json`.
- **Files:** `public/js/{ops-contracts,ops-jobs}.js`, `tests/js/ops-contracts.test.js`, `docs/decisions/{README.md,adr-11-dom-test-harness.md}`, `docs/README.md`, `docs/modules/{ops-sites,ops-list-async}.md`, `docs/plans/progress-ledger.md`.
- **Tests:** `node --test tests/js/*.js` — **13 passed** (was 10; + `textMatches`, `envRowVisible`, `pageIsHidden` / `shouldSchedulePoll`).
- **Docs + ledger:** updated.
- **Notes:** ~03:30 Istanbul. Still later: list `fetch` / `replaceState`. Do not fill the 07:00 wake table.

### Site detail · Coolify deep-link copy (~03:15)

- **Operator-visible change:** the Coolify UI URL is now pasteable next to Open in Coolify — hero pill, Technical identifiers, and the Deploy menu — so the operator does not have to reconstruct `/project/{…}/environment/{…}/application/{uuid}`.
- **How it works:** same `data-copy-value` primitive the sibling UUID-copy slice landed. The value is `$site->coolifyUiUrl()` (environment **uuid**, never the channel name). Missing deep link → no button. Viewers can copy; it is not a mutation.
- **Files:** `resources/views/ops/sites/{show,_header-actions}.blade.php`, `lang/{tr,en}/sites.php`, `tests/Feature/Ops/SiteDetailTest.php`, `docs/modules/ops-sites.md`, `docs/plans/progress-ledger.md`.
- **Tests:** `SiteDetailTest` deep-link case (Turkish `Coolify bağlantısını kopyala`, URL in `data-copy-value` and `href`). Neighbours in this tick below.
- **Docs + ledger:** updated.
- **Notes:** ~03:15 Istanbul. UUID copy was already in the tree. No commit.

### Domains · Unbound leftover bulk clear (~03:15)

- **Operator-visible change:** `/domains` can delete leftover unbound aliases in one danger confirm (`N domain Plane kaydından silinsin mi?`). Primaries, temporary hosts, and Coolify-bound rows stay.
- **How it works:** `POST /domains/bulk-clear` reuses `BulkDomainIdsRequest` / `all=1` + `matchingListFilters`. `DomainClearSweep` is sync Plane-only — no Coolify HTTP, not a job. Fetch `{ refresh_list: true }`. Only-protected selection is `422` / `empty_clear`.
- **Files:** `app/Models/SiteDomain.php`, new `app/Services/Domains/DomainClearSweep.php`, `app/Http/Controllers/Ops/DomainController.php`, `routes/ops/domains.php`, `resources/views/ops/domains/_region.blade.php`, `lang/{tr,en}/domains.php`, new `tests/Feature/Ops/DomainBulkClearTest.php`, `tests/Feature/Ops/{DomainBulkBindTest,ConfirmMatrixTest}.php`, `docs/modules/ops-sites.md`, `docs/plans/progress-ledger.md`.
- **Tests:** new `DomainBulkClearTest` — 7 cases. `ConfirmMatrixTest` pins bind = not-danger, clear = danger. `DomainBulkBindTest` viewer also hides the clear route.
- **Docs + ledger:** updated.
- **Notes:** ~03:15 Istanbul. No commit.

### Settings · Env-defaults row filter (list-fragment does not fit) (~03:15)

- **Operator-visible change:** typing `MYSQL` in the Settings jump box hides other env keys without a reload. Save still writes the whole catalog.
- **How it works:** ListFragment would swap the form and drop unsaved rows (or omit keys that Save would then delete). Same `data-ops-settings-search` + `data-env-search-text` + `envRowVisible`. A section needle (`ortam`) keeps every row. The matching pack tab is clicked when the current pack has no hits.
- **Files:** `resources/views/ops/settings/partials/{env-defaults,env-default-row}.blade.php`, `public/js/{ops-ui,ops-contracts}.js`, `public/css/ops.css`, `lang/{tr,en}/settings.php`, `tests/Feature/Ops/SettingsEnvDefaultsTest.php`, `docs/modules/{ops-list-async,coolify-client}.md`, `docs/plans/progress-ledger.md`.
- **Tests:** `SettingsEnvDefaultsTest` haystack + not-a-list-fragment. `node --test` already covers `textMatches` / `envRowVisible` (sibling ADR-11 / jump slice).
- **Docs + ledger:** updated.
- **Notes:** ~03:15 Istanbul. Completes the jump-search leftover. No commit.

### AgentSecretInjectTest · HTML-escaped confirm flake (~03:30)

- **Operator-visible change:** none. A Faker company name with an apostrophe (`Wilderman-O'Connell`) made the first full-suite run of this tick report **1 failed / 844 passed**. Blade `{{ }}` writes `&#039;` in `data-confirm`; the raw `__()` needle did not match.
- **How it works:** the rotate-confirm assertion now uses `e()`, same as `ConfirmMatrixTest` / `DomainBulkClearTest`. The same pattern was applied to two other factory-name `data-confirm` asserts on site show (`CoolifyDeploySettingsTest`, `CoolifySiteSyncTest`) so the next overnight run does not flake the same way.
- **Files:** `tests/Feature/Sites/{AgentSecretInjectTest,CoolifyDeploySettingsTest,CoolifySiteSyncTest}.php`.
- **Tests:** focused neighbours **67 passed** (582 assertions). Full suite after the fix: **845 passed**, 0 failed (4611 assertions). `node --test tests/js/*.js` **13 passed**. `vendor/bin/pint --dirty` clean.
- **Docs + ledger:** this report + ledger note. No product files.
- **Notes:** ~03:30 Istanbul. Not a product regression from the copy / clear / env-row slices. No commit.

### Activity · CSV export (~03:15)

- **Operator-visible change:** `/activity` has **CSV indir**. The file is the current filters — `outcome=failed` plus a site is the same set the table is showing — so a morning handoff can leave the browser.
- **How it works:** `GET /activity/export` reuses `ActivityFilters` + `ActivityFeed::export()`. UTF-8 BOM, locale labels for kind/outcome, title/detail already redacted, no payloads. Capped at `ops.activity.export_limit` (default 1000). Viewer can export. Guest goes to sign-in. Toolbar link sits outside the list region so a fragment swap cannot drop it.
- **Files:** `app/Http/Controllers/Ops/ActivityController.php`, `app/Support/Ops/{ActivityFeed,ActivityFilters}.php`, `routes/web.php`, `resources/views/ops/activity/index.blade.php`, `config/ops.php`, `lang/{tr,en}/ops.php`, `tests/Feature/Ops/ActivityFeedTest.php`, `docs/modules/ops-activity.md`, `docs/plans/progress-ledger.md`.
- **Tests:** `ActivityFeedTest` — **14 passed** (was 11): filters + redaction, cap + Viewer, guest 302. Focused neighbours in the same run as the widget slice: 36 Pest / 194 assertions.
- **Docs + ledger:** updated.
- **Notes:** ~03:15 Istanbul. Not a second history page. Palette still never offers `GET /jobs`. No commit.

### ADR-11 · List fetch / replaceState (~03:40)

- **Operator-visible change:** none. Search / filter still `replaceState`; sort / paging still `pushState`; a non-region answer still hands the URL to the browser. The contract is no longer closed over inside the IIFE.
- **How it works:** `PlaneOpsContracts.isSameList`, `listQueryChanged`, `shouldPatchListRegion`, `listFetchHeaders`, `listHistoryMode` / `listHistoryWrite`, `isListRequeryLink` / `shouldInterceptListHref`. `ops-list.js` calls those. Tests pass header objects and `{ origin, pathname }` — still no jsdom, no `package.json`. `PlaneUI.refresh()` after a region swap still waits for a fixture.
- **Files:** `public/js/{ops-contracts,ops-list}.js`, `tests/js/ops-contracts.test.js`, `docs/decisions/{README.md,adr-11-dom-test-harness.md}`, `docs/README.md`, `docs/modules/{ops-list-async,ops-sites}.md`, `docs/plans/progress-ledger.md`, `docs/superpowers/plans/2026-09-12-overnight-plane-backlog.md`.
- **Tests:** `node --test tests/js/*.js` — **18 passed** (was 13; + `isSameList`, `listQueryChanged`, `shouldPatchListRegion` / `listFetchHeaders`, `listHistoryMode` / `listHistoryWrite`, `shouldInterceptListHref`). `node --check` on the two scripts: pass. No Pest — PHP not touched. Domains / Settings / site-detail not opened.
- **Docs + ledger:** updated.
- **Notes:** ~03:40 Istanbul. Fifth ADR-11 slice. Do not fill the 07:00 wake table.

### Compose domains after first deploy (in the tree; no backlog id)

- **Operator-visible change:** a new compose app is created **without** `docker_compose_domains`. Coolify refuses that field until a deploy has loaded `docker_compose_raw` from git. Plane binds hosts after the first deploy finishes (`SiteProvisioner::markSucceeded` → `syncCoolifyDomains`). If Coolify still returns the English “cannot set docker_compose_domains without docker_compose_raw” body, the operator sees Turkish `coolify.errors.compose_domains_before_raw` instead of a raw dump.
- **How it works:** `CreateComposeAppRequest` / provision no longer send domains on create. `CoolifyApiException::isComposeDomainsBeforeRaw()` rewrites the 422. `DeploymentFailureText` uses the same predicate for remote + exception paths (`fromRemote` is in the unstaged hunk). Validation dumps use `coolify.errors.errors_heading`, not a hard-coded `Coolify errors:`.
- **Files:** `app/Services/Sites/{SiteProvisioner,DeploymentFailureText}.php`, `app/Services/Coolify/CoolifyApiException.php`, `lang/{tr,en}/coolify.php`, `docs/modules/{ops-sites,coolify-client}.md`, `docs/runbooks/provision-site.md`, `tests/Feature/Sites/{ProvisionSiteTest,SiteLandingFlowTest}.php`, `tests/Unit/{Coolify/CoolifyClientRateLimitTest,Sites/DeploymentFailureTextTest}.php`.
- **Tests:** included in the morning full suite (**847 passed**). The two extra Pest cases vs the ~03:30 **845** baseline are `test_compose_domains_before_raw_is_localized` and `test_compose_domains_before_raw_is_localized_without_english_dump`.
- **Docs + ledger:** module docs already describe bind-after-deploy. This Done entry is the morning-finalize write-up; the product was already in the dirty tree.
- **Notes:** several of those files still have **unstaged** hunks on top of the staged pile (including `SiteLandingFlowTest` and `provision-site.md`). Not a named P0/P1. No commit.

---

## In progress

> Started but not landed. Say exactly where it stopped and what the next concrete step is, so a
> different worker can pick it up cold.

_(append below)_

### Morning finalize (2026-09-14 ~01:00 Istanbul)

- **Nothing is mid-flight.** Every heading below is a close note from the night. Open leftovers are in **Operator handoff** at the top, not here.

### P1-9 · Keyboard shortcuts (closed this wave)

- **Landed.** See **Done**. The palette half that was missing at 01:25 is in the tree:
  `ops-palette.js`, `ops.partials.palette`, `GET /palette`, `PaletteSearchTest`.
- **Next concrete step:** none on this item. Do not start a second palette.

### P1-15 · Bulk defaults (closed this wave)

- **Landed.** See **Done**. The mid-flight note above from the Coolify worker is closed:
  tests, docs and ledger are in the tree. Do not start a second pin/auto-deploy control.

### P1-13 · Activity page (closed this wave)

- **Landed.** See **Done**. `ops.activity`, `ActivityFeed`, `ActivityFeedTest`. Do not
  start a second history page.

### P1-12 · Agent-secret fleet card (closed this wave)

- **Landed.** See **Done**. `AgentSecretFleetTest`, fleet card, `?agent=`, bulk inject
  that never rotates. Do not start a second card.

### P2-17 leftover · Coolify / mail / Themes confirms (closed this wave)

- **Landed.** See **Done**. `ConfirmMatrixTest` now walks those pages. Cloudflare
  deletes landed in the following tick — see **Done**.

### P2-17 leftover · Cloudflare deletes (closed this wave)

- **Landed.** See **Done**. Do not start a second Cloudflare confirm walk.

### P1-14 leftover · Fleet dashboard toolbar (closed this wave)

- **Landed.** See **Done**. `/` filters attention cards; it is not a second
  Sites list. Do not add pagination or a `health=` filter here.

### ADR-10 · Health / app filters (closed this wave as a decision)

- **Landed as an ADR, not as a filter.** Next concrete step is a column +
  `InspectSiteAppHealthJob` write, then SQL. Do not implement a fleet scan.

### P1-6 · Saved site views (closed this wave)

- **Landed.** See **Done**. `SiteSavedViews`, chips, default 302, `SiteSavedViewsTest`.
  Do not start a second saved-views copy.

### P1-7 leftovers · `health=` / `app=` persisted verdict (closed this wave)

- **Landed.** See **Done**. `SiteFilterVerdict`, SQL filters, fleet links,
  inspect/health stamp, `HealthAppFilterTest` 18. Do not start a second
  verdict column or a fleet scan.

### ADR-11 · DOM harness (closed this wave as a decision, other worker)

- **Landed as an ADR, not as a runner.** See
  [adr-11-dom-test-harness.md](../../decisions/adr-11-dom-test-harness.md).
  Playwright / Dusk rejected. `node --test` + jsdom is the later **M**. Do not
  start a second ADR-11 or add `package.json` tonight.

### ADR-11 · DOM harness (author close, tick 5)

- **Landed as an ADR.** Next concrete step is the **M** extract in a later
  tree: four helpers the IIFEs already close over, then `node --test`. Do not
  add `package.json` until a test needs `document`. Leave verdict files alone.

### ADR-11 · First `node --test` slice (closed this tick)

- **Landed.** See **Done**. `ops-contracts.js` + `tests/js`. No `package.json`.
  Next concrete step: `__COUNT__` interpolate / `isTyping` / `confirmOpen`, or
  jsdom only when a test needs `document.hidden`. Leave verdict files alone.

### ADR-11 · Second `node --test` slice (closed this tick)

- **Landed.** See **Done**. Interpolate + typing / confirm guards are in
  `ops-contracts.js`. Next concrete step: jsdom only when a test needs
  `document.hidden` or a list `fetch` fixture. Leave verdict files alone.

### Coolify test hints (closed this tick)

- **Landed.** See **Done**. Operator Coolify hints name the server list.
  Do not put `listServers` back in `lang/tr/coolify.php`. Leave verdict
  files alone.

### Jobs widget failed-only (closed this tick)

- **Landed.** See **Done**. `failed=1` + header toggle. Do not start a
  second widget filter. Leave verdict files alone.

### Activity CSV export (closed this tick)

- **Landed.** See **Done**. `GET /activity/export`. Do not start a second
  history page or an unpaginated dump.

### Site detail copy IDs (closed this tick)

- **Landed.** See **Done**. `data-copy-value` on site ID + Coolify UUIDs.
  Do not start a second identity card.

### Settings jump / search (closed this tick)

- **Landed.** See **Done**. `SettingsJump` + palette hashes. Do not start
  a second Settings page.

### Domains unbound bulk copy (closed this tick)

- **Landed.** See **Done**. Confirm + skip hint. Do not change the sweep.

### ADR-11 hidden-tab poll skip (closed this tick)

- **Landed.** See **Done**. `pageIsHidden` / `shouldSchedulePoll`. Still
  later: list `fetch` / `replaceState`. No jsdom. No `package.json`.

### ADR-11 list fetch / replaceState (closed this tick)

- **Landed.** See **Done**. `isSameList` / `shouldPatchListRegion` /
  `listHistoryMode`. Still later: jsdom only when a test needs
  `PlaneUI.refresh()` after a region swap, confirm focus-trap, or
  palette arrows. No `package.json`.

---

## Next

> Recommended order for the following wave, with any re-prioritisation the night's findings
> justify. If an item turned out to be bigger or smaller than its backlog estimate, correct the
> estimate here and say why.

_(append below)_

Morning finalize (2026-09-14 ~01:00 Istanbul) — the cycle is over. Next operator actions, not another overnight wave:

1. **Review, then commit** the dirty tree as one (or split only after reading overlaps). Do not push until that review. This finalize did not commit.
2. **Pint `line_ending`** on `app/Support/Ops/PaletteFilters.php` if you want `pint --dirty` clean.
3. **ADR-11 leftover** when you want JS proof of a region swap: jsdom for `PlaneUI.refresh()`. No Playwright, no `package.json` until a test needs `document`.
4. **CMS `git` in the runtime image** before live theme assign works.
5. Historical Next notes below are the night's re-prioritisations. **Do not re-dispatch** closed P0/P1/P2 items.

Recommended order after this cycle:

1. **Triage the 4 `BulkThrottleResilienceTest` failures first.** They are pre-existing, not from
   this wave, but they sit exactly on the deploy-queue accounting that **P0-3** and **P0-4** both
   build on. Fixing or re-baselining them is a prerequisite, not a parallel task. Estimate **S** if
   it is a stale test, **M** if `2ccf2ae`'s per-server concurrency gate really is losing a site.
2. **P0-4 · jobs widget backoff** (**M**, unchanged) before **P0-3 · queue position** (**M**).
   Both are widget work; doing backoff first reduces the Coolify pressure that makes P0-3's numbers
   move around while debugging. Consider adding a minimal DOM test harness in the same slice — see
   Risks.
3. **P1-7 · KPI and attention drill-down** moves up. P0-5 left two deliberate gaps — the card has no
   `Tümünü gör` and the `+N site daha` line is plain text — purely because the `deploy=failed` list
   filter does not exist yet. P1-7 creates it, so it closes P0-5's loose ends as a side effect.
4. **P2-18 for Domains / Coolify / mail servers / Themes** is now cheap: the chip primitive and the
   two-cause empty-state pattern already exist, so this is adoption, not design. Downgrade to
   **S per page**.
5. **P2-16** (separate destructive bulk actions) pairs naturally with P0-1's counted confirms and is
   now a small edit: the count is already in the confirm, so this is only markup plus a menu.

Re-prioritised after P0-3 / P0-4 landed (second worker, same cycle):

1. **NEW P0 · "a site waiting for the build slot is not a failure."** The
   `BulkThrottleResilienceTest` triage above found a genuine product bug, not a stale test: with
   `max_concurrent_per_server = 1`, every site in a bulk deploy after the first is eventually
   reported as **`hata`**. Root cause, proof and the recommended shape are in Risks. Estimate **M**.
   This now outranks the rest of P1 — it is fleet-visible on every bulk deploy, and it is the same
   family of lie P0-3 just fixed one layer up.
2. **P1-7 · KPI and attention drill-down** (**M**) stays next after that, for the reason P0-5 gave:
   it creates the `deploy=failed` filter that P0-5's `Tümünü gör` and `+N site daha` are waiting on.
   Note it must not reintroduce a fleet scan in the filter path (P0-2's note).
3. **A DOM test harness decision** (**S** to decide, **M** to land). Both widget items this cycle
   shipped with their browser-only acceptance lines verified by hand. That is now twice; the third
   time it should be a runner, not another note.

Re-prioritised again after the NEW P0 landed (wave-2 worker, same cycle):

1. **Nothing red is left.** The full suite finished the cycle at **673 passed, 0 failed**. The four
   `BulkThrottleResilienceTest` failures that shadowed the whole cycle are fixed at the production
   default `max_concurrent_per_server = 1`, and P1-7 / P1-8 closed on the other worker's side.
   The next wave starts from a green tree — the first time that has been true this cycle.
2. **P1-7 is done and needs no follow-up**, landed by the P1-8 worker and not by the NEW P0 slice:
   `deploy=failed` is a real list filter (`Site::DEPLOY_FILTERS`, allowlisted like `channel` /
   `status`), the chip label carries the failure window, and both of P0-5's loose ends are now
   links — `Tümünü gör` on the KPI card and `+N site daha` under the attention list.
3. **A DOM test harness decision** keeps its place from the list above, now for the third time.
4. **P2-18 for Domains / Coolify / mail servers / Themes**, then **P2-16**, unchanged.
5. **Commit this tree before adding anything to it.** Three slices from three workers are now
   interleaved across ~50 uncommitted files with no commit boundary between them. Reverting any one
   of them is currently impossible; that risk grows with every wave that stays dirty.

Re-prioritised again after P1-8 + P2-16 landed (loop tick 2, same cycle):

1. **P1-8 and P2-16 are both done** — see **Done**. `SiteFleetSearchTest` is green (10/10); the two
   failures the previous run recorded were that slice mid-flight, not a defect in it. Nothing is red
   in the tree: `php artisan test` is **673 passed, 0 failed**.
2. **P1-11 · Domains: bulk bind the unbound** (**M**) is now the highest-value P1 left. Two
   prerequisites it inherits, both landed this cycle: the P0-1 summary contract (adopt it, do not
   invent a second one) and the `waiting` / `sıra bekliyor` bucket, which the bind sweep must use
   for hosts the gate or the rate guard held back. It should also adopt the `sites.bulk_danger.*`
   grouping if it grows a destructive action.
3. **P2-18 for Domains** (**S**) pairs naturally with P1-11: the same view, the same chip primitive,
   one visit instead of two.
4. **P1-10 · stale-data badges** (**M**) is the next honesty item after the searches and counts:
   `_cell.blade.php` still prints raw `Y-m-d H:i` for health and hides freshness in `title`
   attributes, so a broken 4-hour-old poll looks exactly like a healthy site.
5. **A DOM test harness decision** keeps its place, now for the fourth consecutive slice that shipped
   browser-only behavior verified by reading code. Four notes is a decision nobody is making.
6. **P1-6 · saved views** (**L**) is now cheaper than its estimate: `activeListFilters()`,
   `DEPLOY_FILTERS`, the chip primitive and the wider search all exist, so a saved view is
   serialising filters that are already enumerated in one place.

Final list, written by the P1-7 worker with the whole tree green (673 passed, 0 failed):

1. **Decide how a health / app-issue filter is allowed to be computed.** This is the one real
   blocker left from P1-7, and it is a decision, not a coding task. `deploy=failed` was buildable
   because failure is a row in `deployments`; `health=unhealthy` and `app=issues` are PHP verdicts
   (`SiteHealthEvaluator`, `SiteAppHealthFixer::neededFixes()`) over payloads, so filtering on them
   means either **persisting the verdict** — a column plus an invalidation rule, most likely written
   by `InspectSiteAppHealthJob` — or a fleet scan in the filter path, which is precisely the cost
   P0-2 removed. Until that is settled, the unhealthy attention list's `+N site daha` must stay
   plain text; anything else is the "plausible-looking lie" P0-5 refused to ship. **S** to decide,
   **M** to land.
2. **A DOM test harness.** Third cycle in a row that browser-only acceptance lines shipped verified
   by hand (P0-1, P0-3/P0-4). It has stopped being a note.
3. **P2-18 for Domains / Coolify / mail servers / Themes**, then **P2-16**, unchanged.
4. **Worktree discipline before the next parallel wave.** Four workers shared one dirty tree this
   cycle and it held only because the slices happened to be disjoint — two of them were within
   seconds of writing the same third-outcome bucket into the same three files. The next parallel
   wave should hand each worker a `git worktree`, or assign items that provably do not share files.
   See the "third / fourth worker" notes below for how close this came.

Re-prioritised after P1-11 + P2-18 Domains landed (wave 3, same cycle):

1. **P1-9 is in flight on another worker.** `/` + `g` chords + `?` overlay are in the tree;
   the Ctrl/⌘+K palette is the remaining half. Do not start it in this worktree unless those
   files have been quiet for several minutes. Estimate leftover **S–M**.
2. **P1-10 · stale-data badges** (**M**) is now the highest-value untouched P1. It shares
   `_cell.blade.php` and `Site.php` with earlier slices, so take it only if those files are
   quiet. The freshness primitive is new.
3. **P2-18 leftover pages** (**S** each): Coolify inventory, mail servers, Themes. Mail-servers
   still overlaps P2-19; Themes is the cleanest of the three.
4. **P1-15 · bulk defaults** (**M**) is next on Sites once nobody else is in `_region.blade.php`.
5. **Health / app-issue filter decision** and the **DOM test harness** stay as written above.
   Neither is a coding task until someone decides.

Re-prioritised after P1-10, P2-18 Themes, and P1-14/P2-18 mail landed (wave 3, same cycle):

1. **P1-9 is still in flight.** Leave `ops-shortcuts.js` / overlay / `KeyboardShortcutsTest` alone.
2. **P2-18 leftover is Coolify inventory only** (**S**). Sites, Domains, Themes and mail servers
   now have chips + two-cause empty. The connection show inventory tab is four allowlists, not
   a first-class list URL — expect a design note if ListFragment does not fit the tabs.
3. **P1-14 leftover is Coolify inventory** (and the fleet dashboard). Mail servers is Done.
4. **P1-15 · bulk defaults** (**M**) once `sites/_region.blade.php` / `SiteController` are quiet.
5. **Health / app-issue filter decision** and the **DOM test harness** stay as written above.

Re-prioritised after P1-9 landed (wave 3, same cycle):

1. **P1-9 is done.** `/` + `g` chords + `?` overlay + `Ctrl/⌘+K` palette. Leave those files
   unless a follow-up bug shows up.
2. **P2-18 leftover is Coolify inventory only** (**S**). Same note as the mail-servers worker:
   the connection show inventory tab is four allowlists, not a first-class list URL.
3. **P1-14 leftover is Coolify inventory** (and the fleet dashboard).
4. **P1-15 · bulk defaults** (**M**) once `sites/_region.blade.php` / `SiteController` are quiet.
5. **Health / app-issue filter decision** and the **DOM test harness** stay as written above.
6. **P1-6 · saved views** (**L**) is cheaper than its estimate: chips, `DEPLOY_FILTERS` and
   the wider search already exist.

Re-prioritised after Coolify inventory + P1-10 leftover landed (wave 4, same cycle):

1. **P1-14 leftover and P2-18 leftover (Coolify) are done.** Fleet dashboard is the last
   page with no toolbar; leave it unless someone designs a first-class `/fleet` list.
2. **P1-15 is in flight on another worker.** Leave `sites/_region.blade.php`,
   `SiteController`, `SiteCoolifyOpsController`, `BulkAutoDeploySiteRequest`.
3. **P1-12 · agent-secret fleet card** (**M**) is the highest-value untouched P1.
4. **P2-17 · confirm matrix** (**M**) after P1-15 lands — it walks the same `data-confirm`
   attributes the bulk bar just changed.
5. **Health / app-issue filter decision** and the **DOM test harness** stay as written above.

Re-prioritised after P1-15 + P2-17 (Sites) landed (wave 4, same cycle):

1. **P1-15 and P2-17 (Sites) are done.** See **Done**. Coolify / mail / Themes confirms
   outside Sites are the leftover half of P2-17 — **S** now that the walker exists.
2. **P1-12 · agent-secret fleet card** (**M**) is the highest-value untouched P1.
   Leave `FleetDashboardKpis` / `attention.blade.php` if another worker is already there.
3. **P1-6 · saved views** (**L**, cheaper than the estimate): chips, `DEPLOY_FILTERS` and
   the wider search already exist. Built-in views alone would be **M**.
4. **P1-13 · activity page** stays **L** — `/jobs` is still JSON-only and `AuditLog` has
   no UI.
5. **Health / app-issue filter decision** and the **DOM test harness** stay as written above.
   `ConfirmMatrixTest` is a server-rendered attribute walker, not a JS runner.

Re-prioritised after P1-13 landed (wave 4 / tick 4, same cycle):

1. **P1-13 is done.** `/activity` is the fleet log. Leave those files unless a follow-up
   bug shows up. No second history page.
2. **P1-12 · agent-secret fleet card** (**M**) is in the tree as of tick 4
   (`SiteAgentSecretSweep.php`). Treat it as in flight — do not start a second copy.
3. **P1-6 · saved views** (**L**, cheaper): chips, `DEPLOY_FILTERS` and the wider search
   already exist. Built-in views alone would be **M**.
4. **P2-17 leftover** (**S**): Coolify / mail / Themes confirms, now that the Sites walker
   exists. Skip if someone is already on those pages.
5. **Health / app-issue filter decision** and the **DOM test harness** stay as written above.
6. **Fleet dashboard toolbar** is the last P1-14 leftover. Needs a design note first.

Re-prioritised after P1-12 + P2-17 leftover landed (~02:00 Istanbul, same cycle):

1. **P1-12 and P2-17 leftover are done.** See **Done**. Do not start a second
   agent-secret card or a second Coolify/mail/Themes confirm walk.
2. **P1-6 · saved views is in flight** on another worker (`SiteSavedViews`,
   `SiteController@index`, `ops/sites/_saved-views*.blade.php`). Leave those
   files. Built-in views + five operator saves; do not write a second copy.
3. **P1-13 / ActivityFeed files** stay closed. No second history page.
4. **Cloudflare confirms** (account / zone / DNS delete) are the only obvious
   confirm-matrix leftover, and they were never in P2-17's leftover list.
5. **Health / app-issue filter decision** and the **DOM test harness** stay as
   written above. Fleet dashboard still has no toolbar.
6. **Do not fill "Verification run at wake"** yet — this is not morning finalize.

Re-prioritised after P1-6 + this leftover wave (~02:30 Istanbul, same cycle):

1. **P1-6, P1-14 fleet toolbar, P2-17 Cloudflare, and ADR-10 are done.** See
   **Done**. Do not start a second saved-views copy or a second fleet list.
2. **Health / app-issue filters are decided, not built.** ADR-10: persist the
   verdict, then SQL. Do not scan the fleet. Estimate **M** when someone picks it.
3. **A DOM test harness** is the only recurring open from this cycle that is
   still a decision, not a slice.
4. **Do not fill "Verification run at wake"** yet — this is not morning finalize.

Re-prioritised after P1-6 landed (~02:15 Istanbul, same cycle):

1. **P1-6 is done.** Named views + built-ins + default landing. Leave
   `SiteSavedViews` / Sites toolbar unless a follow-up bug shows up.
2. **P1-12 and P2-17 leftover stay closed.** No second agent-secret card, no
   second Coolify/mail/Themes confirm walk.
3. **Health / app-issue filter decision** is still the one real product
   blocker left from P1-7 (`health=unhealthy` / `app=issues` need a persisted
   verdict or they reintroduce the P0-2 fleet scan). **S** to decide, **M** to
   land.
4. **A DOM test harness** — still a decision, now for saved-view chip clicks
   as well as the widget.
5. **Cloudflare confirms** (account / zone / DNS delete) if someone wants a
   third confirm-matrix pass. Fleet dashboard still has no toolbar (needs a
   design note).
6. **Do not fill "Verification run at wake"** yet — this is not morning finalize.

Re-prioritised after the P1-12 worker's full-suite confirm (wave 4, **790 passed, 0 failed**):

1. **Nothing red is left in this tree.** Coolify inventory, `updated` relative age, and the
   agent-secret fleet card are in **Done** with the rest of the night's slices.
2. **Health / app-issue filter decision** is still the one real product blocker from P1-7.
3. **A DOM test harness** — still a decision, not another note.
4. **Fleet dashboard toolbar** is the last P1-14 leftover and needs a design note (it is
   not a first-class list URL).
5. **Cloudflare confirms** if someone wants a third confirm-matrix pass.
6. **Commit this tree before the next parallel wave.** The worktree is one dirty pile
   across many workers; reverting any one slice is currently impossible.

Re-prioritised after P1-14 fleet toolbar + P2-17 Cloudflare + ADR-10 landed (~02:35 Istanbul, same cycle):

1. **P1-14 leftover (fleet) and P2-17 leftover (Cloudflare) are done.** See **Done**.
   Do not start a second `/fleet` site list — `/sites` stays that.
2. **ADR-10 is written.** `health=` / `app=` stay unbuilt until a persisted verdict
   column exists. Do not implement a fleet scan. Landing the column is **M**.
3. **A DOM test harness** is the last recurring decision (widget + list JS).
4. **Commit this tree before the next parallel wave.** Still one dirty pile.
5. **Do not fill "Verification run at wake"** yet — this is not morning finalize.

Re-prioritised after the ~02:45 tree audit (same cycle; this worker):

1. **P1-7 leftovers (`health=` / `app=`) are in flight** on another worker —
   migration, `SiteFilterVerdict`, toolbar selects, `HealthAppFilterTest` (15
   cases written). Do not touch those files. If they stall after 07:00 with
   tests/docs unfinished, that is the first wake pickup — tests/docs only.
2. **ADR-11 is decided.** Skip Playwright. Do not land `node --test` in this
   dirty tree. Landing is **M** after commit.
3. **Empty-state copy leftovers from this tick are done.** See **Done**.
4. **Platform-mail nav chip stays off the sidebar** (P2-19 cost). Page chips exist.
5. **Commit this tree before the next parallel wave.** Still one dirty pile.
6. **Do not fill "Verification run at wake"** yet — this is not morning finalize.
   The table at the top of that section is the 07:00 slot.

Re-prioritised after ADR-11 + focused dirty-area tests (tick 5 / ~02:40 Istanbul):

1. **ADR-11 is written by its author.** See **Done**. Skip Playwright. Do not
   land `tests/js/` or a `package.json` in this dirty tree. Landing is **M**
   after a commit boundary.
2. **P1-7 leftovers (`health=` / `app=`) stay the other worker's.**
   `HealthAppFilterTest` is **16 passed** as of this tick (was 15 in the
   ~02:45 note). Still do not touch `SiteFilterVerdict` / the verdict
   migration / `InspectSiteAppHealthJob`.
3. **The counts-age flake is closed.** `SiteListAppHealthCountCostTest` 6/6
   with a frozen clock. Do not re-open the fixer or the menu markup for it.
4. **Empty-state copy leftovers** stay with the polish worker. See **Done**.
5. **Commit this tree before the next parallel wave.** Still one dirty pile.
6. **Do not fill "Verification run at wake"** yet — this is not morning finalize.

Re-prioritised after P1-7 leftovers / ADR-10 landing (~02:50 Istanbul, same cycle):

1. **`health=unhealthy` and `app=issues` are built.** See **Done**. Do not add a
   fleet scan, a second verdict writer, or a `health=` filter on `/` (P1-14
   attention search stays in memory).
2. **ADR-11 stays a decision.** Skip Playwright. Do not land `tests/js/` or a
   `package.json` in this dirty tree. That **M** waits for a commit boundary.
3. **Empty-state copy leftovers** stay closed. See **Done**.
4. **Commit this tree before the next parallel wave.** Still one dirty pile.
5. **Do not fill "Verification run at wake"** yet — this is not morning finalize.

Re-prioritised after the inspect-job stamp close (same cycle):

1. **P1-7 leftovers are fully closed.** `InspectSiteAppHealthJob` stamps
   `app_has_issues` through `inspect()`. `HealthAppFilterTest` 18. See **Done**.
2. **ADR-11 stays a decision.** Do not land `tests/js/` tonight.
3. **Commit this tree before the next parallel wave.** Still one dirty pile.
4. **Do not fill "Verification run at wake"** yet — this is not morning finalize.

Re-prioritised after the ADR-11 first test slice (~02:50 Istanbul, same cycle):

1. **ADR-11 first slice is in the tree.** See **Done**. Run `node --test tests/js/*.js`.
   No Playwright. No `package.json`. Do not add jsdom until a test needs
   `document`.
2. **P1-7 leftovers stay closed.** Do not touch verdict files.
3. **Still later on ADR-11:** `__COUNT__` interpolate, `isTyping` / `confirmOpen`.
4. **Commit this tree before the next parallel wave.** Still one dirty pile.
5. **Do not fill "Verification run at wake"** yet — this is not morning finalize.

Re-prioritised after the ADR-11 second test slice (~02:55 Istanbul, same cycle):

1. **ADR-11 interpolate + typing / confirm guards are in the tree.** See **Done**.
   Run `node --test tests/js/*.js`. No Playwright. No `package.json`.
2. **P1-7 leftovers stay closed.** Do not touch verdict files.
3. **Still later on ADR-11:** jsdom only when a test needs `document.hidden` or
   list `fetch` / `replaceState`.
4. **Commit this tree before the next parallel wave.** Still one dirty pile.
5. **Do not fill "Verification run at wake"** yet — this is not morning finalize.

Re-prioritised after the ~02:55 full-suite confirm + Coolify hint polish (same cycle):

1. **Nothing red.** `php artisan test` is **817 passed** (4455 assertions) after the Coolify hint case; `node --test tests/js/*.js` is **8 passed**. See verification below. Do not fill the 07:00 wake table.
2. **P1-7 leftovers / health verdicts stay closed.** Done already names the columns, `SiteFilterVerdict`, inspect/health stamp, fleet links, and `HealthAppFilterTest` 18. Tree matches. Do not start a second verdict writer or a fleet scan.
3. **ADR-11 interpolate + typing / confirm guards stay closed.** Still later: jsdom only when a test needs `document.hidden` or list `fetch` / `replaceState`. No `package.json`.
4. **Coolify `listServers` leak is closed.** See **Done**. Leave verdict files and widget JS alone.
5. **Commit this tree before the next parallel wave.** Still one dirty pile.
6. **Do not fill "Verification run at wake"** yet — this is not morning finalize.

Re-prioritised after jobs failed-only + activity CSV (~03:15 Istanbul, same cycle):

1. **Sadece hatalılar and CSV indir are in the tree.** See **Done**. Do not start a second widget filter or a second export format.
2. **P1-7 leftovers / health verdicts stay closed.** Do not start a second verdict writer or a fleet scan.
3. **ADR-11:** `jobsIndexUrl` / `isFailedStatus` landed. Still later: jsdom only when a test needs `document.hidden` or list `fetch` / `replaceState`. No `package.json`.
4. **Still open and grounded, not started:** site-detail Coolify uuid / deep-link **copy** (Open in Coolify already exists; the uuid is still a `<code>` in collapsed technical details), Domains unbound **bulk delete** of leftover hosts, Settings env-defaults search.
5. **Commit this tree before the next parallel wave.** Still one dirty pile.
6. **Do not fill "Verification run at wake"** yet — this is not morning finalize.

Re-prioritised after Coolify deep-link copy + domain leftover clear + env-row filter (~03:15 Istanbul, same cycle):

1. **The three grounded leftovers are in the tree.** See **Done**. UUID copy / Settings jump / unbound-bind skip copy were sibling slices; this tick added the deep-link copy, **Kayıttan sil**, and env-row filter without ListFragment.
2. **P1-7 leftovers / health verdicts stay closed.** Do not start a second verdict writer or a fleet scan.
3. **ADR-11:** still later jsdom only when a test needs `document.hidden` list `fetch` / `replaceState`. No `package.json`.
4. **Commit this tree before the next parallel wave.** Still one dirty pile.
5. **Do not fill "Verification run at wake"** yet — this is not morning finalize.

Re-prioritised after copy IDs + Settings jump + Domains skip copy + hidden-tab contract (~03:30 Istanbul, same cycle):

1. **Those three operator slices and the ADR-11 `document.hidden` leftover are in the tree.** See **Done**. Do not start a second copy row, a second Settings page, or a Domains bulk-delete.
2. **P1-7 leftovers / health verdicts stay closed.** Do not start a second verdict writer or a fleet scan.
3. **ADR-11:** `pageIsHidden` / `shouldSchedulePoll` / `textMatches` landed. Still no jsdom (`{ hidden: true }`). Still later: list `fetch` / `replaceState`. No `package.json`.
4. **Domains leftover bulk clear landed on a sibling** (`DomainBulkClearTest` 7). Bind skip-copy is this tick. Do not start a second clearer.
5. **Commit this tree before the next parallel wave.** Still one dirty pile.

Re-prioritised after the assigned leftover clearer landed (~03:15 Istanbul, same cycle):

1. **Domains leftover bulk delete is in the tree** (`DomainClearSweep`, danger confirm). The ~03:30 note above said "not started" before this tick finished it. Do not start a second clearer.
2. **Coolify deep-link copy and env-row filter are also Done.** Do not add a second copy primitive or a Settings ListFragment.
3. **P1-7 leftovers / health verdicts stay closed.**
4. **Commit this tree before the next parallel wave.** Still one dirty pile.
5. **Do not fill "Verification run at wake"** yet — this is not morning finalize.
6. **Do not fill "Verification run at wake"** yet — this is not morning finalize.

Re-prioritised after the full-suite confirm + confirm-assert flake (~03:30 Istanbul, same cycle):

1. **The three assigned leftovers stay closed.** Deep-link copy, leftover **Kayıttan sil**, env-row filter. Do not start a second copy primitive, a second Settings ListFragment, or a second domain clearer.
2. **Full suite is green.** First run this tick: **1 failed / 844 passed** (`AgentSecretInjectTest` apostrophe vs `e()`). After the assert fix: **845 passed**, 0 failed (4611 assertions). `node --test` **13 passed**. Baseline was 825 Pest / 10 node; the extra tests are this cycle's new files, not a break.
3. **P1-7 leftovers / health verdicts stay closed.**
4. **ADR-11:** still later list `fetch` / `replaceState`. No jsdom, no `package.json`.
5. **Commit this tree before the next parallel wave.** Still one dirty pile.
6. **Do not fill "Verification run at wake"** yet — this is not morning finalize.

Re-prioritised after ADR-11 list `fetch` / `replaceState` (~03:40 Istanbul, same cycle):

1. **ADR-11 list fetch / replaceState is in the tree.** See **Done**. Do not start a second copy of `toolbarUrl` or a jsdom fixture tonight.
2. **P1-7 leftovers / health verdicts stay closed.** Do not start a second verdict writer or a fleet scan.
3. **Domains / Settings / site-detail copy stay closed** — another invent agent may still be on those files. Do not open them.
4. **Still later on ADR-11:** jsdom only when a test needs `PlaneUI.refresh()` after a region swap, confirm focus-trap, or palette arrows. No `package.json`.
5. **Commit this tree before the next parallel wave.** Still one dirty pile.
6. **Do not fill "Verification run at wake"** yet — this is not morning finalize.

---

## Risks and open questions

> Anything the operator must decide, anything that could bite in production, anything discovered
> that is not in the backlog.

Carried in from the backlog — restate only if the night changed the picture:

- Coolify request pressure is the recurring failure mode
  ([2026-09-11-coolify-throttle-cure-design.md](../specs/2026-09-11-coolify-throttle-cure-design.md)).
  Any new polling, sweep or dashboard query must be counted, paced and justified before it ships.
- `max_concurrent_per_server = 1` means every bulk deploy is a queue. Features that imply
  parallel builds will mislead the operator unless they surface queue depth.
- `all=1` bulk semantics act on the whole filtered set, not the visible page. Until P0-1 lands,
  every new bulk action inherits that ambiguity.
- The CMS runtime image has no `git`, so control-plane theme git stays broken on live. That is a
  CMS repo fix; do not work around it in Plane.

_(append below)_

Changed by this cycle:

- `all=1` ambiguity is **closed for Sites** (P0-1). Domains bulk (P1-11) must adopt the same
  summary contract when it lands, not invent a second one.
- **New:** `BulkThrottleResilienceTest` has **4 failing tests on a clean `HEAD`**, unrelated to this
  wave and reproduced by stashing every local change. Symptoms: a throttled bulk pin reports
  `Pin: 1 deploy triggered, 3 failed` where the test expects 4 sites accounted for, and one site
  ends with `0` deployment rows where `1` is expected. That is the exact surface the throttle-cure
  design covers, so it is either a regression in the last two commits
  (`2ccf2ae` limited Coolify builds to one per server connection — a prime suspect, since the new
  concurrency gate can legitimately refuse a deploy the test still counts as triggered) or a stale
  test. **Somebody must decide which before P0-3/P0-4 touch the queue**, because both of those
  items assume this area is trustworthy.
- **New:** the repo has no JavaScript test runner, so `ops-ui.js` / `ops-jobs.js` behavior is only
  ever asserted indirectly through rendered attributes. P0-1 already pushes that as far as it
  reasonably goes; P0-3 and P0-4 are almost entirely widget logic and would ship essentially
  untested by the current suite.

#### CLOSED as a decision (tick 5): ADR-11

The runner gap is **decided, not built.** Pest keeps the Blade / `data-*` contract.
`node --test` + optional jsdom is the later lane. Playwright / Dusk are out.
See [ADR-11](../../decisions/adr-11-dom-test-harness.md). The `SiteListAppHealthCountCostTest`
counts-age assertion was a `diffForHumans()` clock race (`0 seconds ago` vs `1 second ago`),
not a missing note — frozen in that test.

### RESOLVED (root cause) + ESCALATED (product bug): the 4 `BulkThrottleResilienceTest` failures

**Root cause is confirmed, and it is not a stale test.** All four failures are caused by
`ops.coolify.deploy.max_concurrent_per_server = 1` from `2ccf2ae`. Proof without touching the shared
worktree: running the same suite with only that cap raised —
`COOLIFY_MAX_CONCURRENT_PER_SERVER=99 php artisan test --filter=BulkThrottleResilienceTest` — gives
**8 passed, 0 failed**. No file in either worker's diff is on that code path
(`PacedFanout`, `OpsJobRunner`, `CoolifyDeploySettings`, `CoolifyDeployGate` are all untouched).

**They do not block P0-3 or P0-4**, both of which landed this cycle: neither reads deploy-gate
accounting, and both are covered by their own green tests.

**But the failures are telling the truth, and the product is not.** The mechanism:

1. `CoolifyDeployGate` refuses the second concurrent deploy on a connection with
   `CoolifyDeployBusyException`.
2. `PacedFanout::isTransient()` treats that as retryable, so the site goes to the back of the queue
   — correct.
3. After `ops.coolify.bulk.max_site_attempts` (6) short waits the slot is usually still taken,
   because a real Coolify build takes minutes, not seconds.
4. `isRateLimited()` is **false** for a busy gate (it is not a 429), so the site falls through to
   `$failed++` and the operator is told **`hata`** — for a site that was merely waiting its turn and
   was never asked to deploy at all.

So a 23-site bulk deploy against one connection can report roughly `1 deploy tetiklendi, 22 hata`.
That is the same class of lie as P0-3, one layer down, and it is worse: P0-3 withheld information,
this invents a failure. It also makes the `atlandı` vocabulary incomplete — there are now two
reasons a site was never told to deploy (rate limit, busy slot) and only one has copy.

**Recommended fix — the next P0, and a real slice, not a test edit.** A site the gate refused
should be reported as deferred/queued, not failed: give `PacedFanout` a third bucket with its own
Turkish reason (`atlandı (sıra beklemede)` or similar), and have the sweep's own re-run advice name
it. Deciding *whether* bulk fan-out should be gated at all is the alternative and needs an operator
call. **Do not "fix" this by editing the assertions** — the assertions describe the behavior the
operator needs.

#### CLOSED in wave 2 (same cycle)

Both halves landed, and the operator call turned out not to be needed: `2ccf2ae`'s own plan already
answered it (*"bulk path already serial; gate still protects overlapping single-site redeploys"*),
so bulk fan-out was never meant to be gated against itself. The sweep now holds the host slot for
its own duration (`CoolifyDeployGate::duringSweep()`) and `PacedFanout` gained the third bucket
(`waiting` → `sıra bekliyor`, note `ops.bulk.deploy_busy`). All four assertions pass unedited. See
the **NEW P0 (wave 2)** entry under **Done**.

---

## Verification run at wake

**Operator numbers are in the handoff at the top and in “Wake verification (filled 2026-09-14)” below.** The first table here is the historic wave-1 run (621 / 4 failed). Do not quote it as the morning suite.

| Check | Command | Result |
|-------|---------|--------|
| Full suite | `php artisan test` | **621 passed, 4 failed** — all four are `BulkThrottleResilienceTest` and **pre-existing**: verified by stashing the night's work and re-running on a clean `HEAD` (`2ccf2ae`), where the same four fail identically. Not caused by this wave; see Risks. |
| Style | `vendor/bin/pint --dirty` | clean **for this wave's files** (checked explicitly by path). A later `--dirty` run flags `tests/Feature/Ops/DeployQueueStandingTest.php` for `line_ending` — that file belongs to another worker's slice that appeared in the worktree afterwards (see below); it was left alone. |
| No outbound live calls in tests | new tests use factories only and never construct a Coolify client; the one `fix()` call in `SiteListAppHealthCountCostTest` is wrapped because its Coolify leg is expected to fail offline | pass |
| No new hard-coded UI strings | every new string added to both `lang/tr` and `lang/en`; the only literals introduced in Blade are `data-*` hooks and the `__COUNT__` / `__TARGET__` placeholders | pass |
| Git state | `git status` | **dirty, nothing committed** — 28 modified, 8 untracked. **Not all of it is this wave.** |

### Second verification run, after P0-3 + P0-4 landed

Run by the P0-3 / P0-4 worker at the end of the cycle, over both workers' changes together.

| Check | Command | Result |
|-------|---------|--------|
| Full suite | `php artisan test` | **638 passed, 4 failed** (3341 assertions). The 4 are `BulkThrottleResilienceTest`, root-caused this cycle — see Risks. Everything else in the tree is green, so the two slices do not conflict. |
| The 4 failures | `COOLIFY_MAX_CONCURRENT_PER_SERVER=99 php artisan test --filter=BulkThrottleResilienceTest` | **8 passed, 0 failed** — isolates the cause to the concurrency cap from `2ccf2ae`, with no stash and no edit to a shared file. |
| This slice's tests | `php artisan test --filter="DeployQueueStandingTest\|JobsPollPressureTest\|DeploymentPollWidgetTest\|CoolifyDeployGateTest\|OpsListFragmentTest"` | **42 passed** (174 assertions) |
| Style | `vendor/bin/pint` on the six files of this slice | clean (fixed `line_ending` on the two new test files) |
| JS syntax | `node --check public/js/ops-jobs.js` | pass. No JS runner exists, so this is the only automated check on the widget — see Risks. |
| No outbound live calls in tests | both new test classes call `Http::preventStrayRequests()`; `JobsPollPressureTest` fakes only `coolify.test` | pass |
| No new hard-coded UI strings | the three new `ops.jobs.queue_*` keys exist in `lang/tr` **and** `lang/en`; `DeployQueueStandingTest::test_queue_copy_reads_as_turkish` asserts the Turkish output directly | pass |
| Git state | `git status` | **dirty, nothing committed** |

### Third verification run, after the NEW P0 (waiting bucket) landed

Run by the wave-2 worker over all three slices in the tree.

| Check | Command | Result |
|-------|---------|--------|
| The 4 escalated failures | `php artisan test tests/Feature/Ops/BulkThrottleResilienceTest.php` | **8 passed, 0 failed**, with the test file **unmodified**. The `COOLIFY_MAX_CONCURRENT_PER_SERVER=99` workaround is no longer needed: the suite is green at the production default of `1`. |
| This slice's tests | `php artisan test tests/Feature/Ops/BulkDeployWaitingBucketTest.php tests/Feature/Coolify/CoolifyDeployGateTest.php` | **12 passed** |
| Full suite | `php artisan test` | **656 passed, 2 failed** (3410 assertions). The 2 are `SiteFleetSearchTest`, which is **another worker's P1-8 slice, still in flight** — see below. Every previously failing test is green. |
| Style | `vendor/bin/pint` on this slice's files | clean |
| No outbound live calls in tests | `BulkDeployWaitingBucketTest` uses `Http::preventStrayRequests()` and factories only; two of its cases assert `Http::assertNothingSent()` | pass |
| No new hard-coded UI strings | `ops.bulk.waiting` and `ops.bulk.deploy_busy` exist in `lang/tr` **and** `lang/en`; the test asserts the Turkish output under `App::setLocale('tr')` and checks the words `hata` and `atlandı` are absent | pass |
| Git state | `git status` | **dirty, nothing committed** |

### Fourth verification run, after P1-7 landed (whole tree, all four slices)

Run by the P1-7 worker over everything in the worktree — wave 1 (P0-1/2/5, P2-18, P0-3/4), the
wave-2 waiting bucket, P1-8 and P1-7 together.

| Check | Command | Result |
|-------|---------|--------|
| Full suite | `php artisan test` | **673 passed, 0 failed** (3484 assertions). **The tree is fully green for the first time this cycle:** the 4 escalated `BulkThrottleResilienceTest` failures and the 2 in-flight `SiteFleetSearchTest` failures are all resolved, and nothing regressed. |
| This slice's tests | `php artisan test tests/Feature/Ops/FailedDeployFilterTest.php` | **10 passed** (34 assertions) |
| Neighbours of the shared predicate | `--filter="FleetFailedDeployWindowTest\|FleetDashboardTest\|SiteListEmptyStatesTest\|SiteBulkActionsTest\|SiteBulkSelectionScopeTest\|OpsListFragmentTest\|SiteListAppHealthCountCostTest\|SiteAppHealthTest"` | **55 passed** (295 assertions) — the `+N site daha` assertions survive becoming a link, and the extra scope argument did not disturb any `all=1` resolver |
| Style | `vendor/bin/pint` on this slice's files | clean (fixed `line_ending` on the new test file) |
| No outbound live calls in tests | `FailedDeployFilterTest` calls `Http::preventStrayRequests()`, uses factories only, and exercises the bulk path through the JSON queue response rather than a real sweep | pass |
| No new hard-coded UI strings | `sites.filter_deploy`, `sites.all_deploys`, `sites.deploy_states.failed` and `fleet.attention.see_all` exist in `lang/tr` **and** `lang/en`; the test asserts the rendered Turkish, including the `:hours` interpolation | pass |
| Git state | `git status` | **dirty, nothing committed** |

### A third worker arrived mid-cycle (P1-7 + P1-8)

While the waiting-bucket slice was being written, a third set of changes appeared in the worktree:
`Site.php` (`DEPLOY_FILTERS`, alias/uuid search), `SiteController.php` (the `deploy` filter and its
chip), `Deployment::failedWindowHours()`, `ops/sites/_cell.blade.php`, `ops.css`,
`lang/{tr,en}/sites.php` (`deploy_states.*`, `search_match.*`), the `BulkSite*Request` classes, and
a new `tests/Feature/Sites/SiteFleetSearchTest.php`.

That is **P1-7 (done) plus P1-8 (in flight)** — not this slice. Two consequences for the reviewer:

- **P1-7 needs no further work.** It closed P0-5's two deliberate gaps as that item predicted:
  `kpis.blade.php` links `Tümünü gör` to `?deploy=failed` and `attention.blade.php` links
  `+N site daha` to the same place. The waiting-bucket worker deliberately did **not** touch it, to
  avoid a second implementation of the same filter in a shared tree.
- **P1-8 was red mid-cycle and is green now.** Its 2 failures (the alias match line, and a
  `site_domains` query-count assertion) were on files the waiting-bucket slice never opens, and
  their author closed them before the cycle ended. No stash was used this cycle either — three
  workers in one tree makes `git stash` strictly more dangerous than it already was.

### Confirmed from the P1-7 side: two workers nearly wrote the same slice

The "third worker" above is this P1-7 slice, and the near-miss is worth recording because nothing
in the process prevented it.

Both the waiting-bucket worker and this one were dispatched onto the **NEW P0** with the same
instructions. This one read the code, reached the same design — sweep-owned deploy slot plus a
third `waiting` bucket — and was part-way through its first edit when `StrReplace` failed on
`CoolifyDeployGate.php`: the file no longer matched what had been read 90 seconds earlier. The
other worker had just written `duringSweep()` into it. Mtimes on `PacedFanout.php` and
`BulkResultSummary.php` were 60–90 seconds old, `BulkThrottleResilienceTest` was already 8/8, and
the two designs differed only in bookkeeping (an id watermark versus per-host admission counts).

Nothing was lost, and only because the edit tool requires an exact match on what it replaces — a
whole-file write would have silently reverted the other worker's `duringSweep()` and left a tree
that still passed its tests for the wrong reason. This worker then moved to **P1-7**, the next
unclaimed item on its own list, whose files are disjoint from that slice.

Two rules for the next parallel wave, both cheap: give each worker a `git worktree`, and have a
worker **check file mtimes and `git status` before its first edit**, not only at dispatch. The
existing advice in this report — "prefer `git worktree` over `git stash`" — protects against losing
work to a stash. It does not protect against two workers implementing the same item, which is what
actually happened.

### Another worker is in the same worktree

Between the start and the end of this cycle, a second slice appeared in the working tree —
`OpsJobController.php`, `OpsCoolifyDeployQueue.php`, `public/js/ops-jobs.js`,
`resources/views/ops/partials/jobs-widget.blade.php` and a new
`tests/Feature/Ops/DeployQueueStandingTest.php`. That is **P0-3 / P0-4 territory, not mine**; none of
those files were edited or reverted here, and the morning reviewer should read the two sets of
changes as separate slices.

Confirmed from the other side: that is the P0-3 / P0-4 slice, now finished (see **Done**). The two
slices overlap in exactly three files and never on the same lines — `config/ops.php`
(`app_health` / `fleet` blocks vs. the new `jobs.poll` block and
`coolify.deploy.queue_cache_seconds`), `lang/{tr,en}/ops.php` (`filter.*` vs. `jobs.queue_*`) and
`public/css/ops-ui.css` (bulk bar / filter chips / `.fleet-attention-more` vs.
`.ops-jobs-summary-text` / `.ops-jobs-queue`). Reviewing them as one diff is safe; reverting one
without the other is not.

No further `git stash` was used this cycle. The clean-`HEAD` comparison the P0-3 worker needed was
done with an env override instead (`COOLIFY_MAX_CONCURRENT_PER_SERVER=99`), which touches no file
and cannot lose another worker's work. Recommend that technique — or `git worktree` — over stashing
whenever two workers share a tree.

### Fourth verification run, after P1-8 + P2-16 landed (loop tick 2)

Run over every slice in the tree — four workers' worth.

| Check | Command | Result |
|-------|---------|--------|
| Full suite | `php artisan test` | **673 passed, 0 failed** (3484 assertions). **Nothing in the tree is red.** The `SiteFleetSearchTest` failures the third run recorded were this slice mid-flight; they are closed, not re-baselined, and `BulkThrottleResilienceTest` stays green at the production default. |
| This slice's tests | `php artisan test --filter="SiteFleetSearchTest\|SiteBulkDangerMenuTest"` | **15 passed** (74 assertions) |
| Neighbours that could have broken | `php artisan test --filter="SiteBulkActionsTest\|SiteBulkSelectionScopeTest\|OpsListFragmentTest\|SiteCrudTest\|SiteListEmptyStatesTest\|SiteListAppHealthCountCostTest"` | **49 passed** (320 assertions) — the search scope and the bulk row are shared surfaces, so they were checked before the full run, not after |
| Style | `vendor/bin/pint` on this slice's files | clean (fixed `line_ending` on the two new test files, as every new file in this repo needs) |
| No outbound live calls in tests | both new classes use factories only and never construct a Coolify client; neither renders a path that reaches Coolify | pass |
| No new hard-coded UI strings | `sites.search_match.alias` / `.uuid` and `sites.bulk_danger.trigger` / `.label` exist in `lang/tr` **and** `lang/en`; `SiteFleetSearchTest` asserts the Turkish copy for a `locale = tr` operator and asserts the English fallback is absent | pass |
| Git state | `git status` | **dirty, nothing committed** — no commit, no PR, no push, as instructed |

Two notes for the reviewer on process, not code:

- **Turkish assertions must go through the request, not `App::setLocale()`.** `SetOpsLocale` reads
  `users.locale` on every request and overwrites whatever the test set beforehand, so a test that
  calls `App::setLocale('tr')` and then hits a route asserts **English** while looking Turkish. The
  reliable shape is a user with `locale = 'tr'` plus `trans(key, [], 'tr')`, which is what
  `SiteFleetSearchTest` does. Worth knowing before the next slice writes a locale assertion.
- **A query-count assertion must exclude subqueries.** Matching `from "site_domains"` counted the
  `whereHas` exists clause that lives *inside* the sites query, which made a correct eager load look
  like an N+1. Anchor such assertions with `select * from "<table>"`.

### Loop tick 2 shared no files with the busy-slot slice

While P1-8 / P2-16 were written, the sibling's slice was live in `PacedFanout.php` and
`CoolifyDeployGate.php`. **Neither was opened here**, and the overlap with everything else is
line-disjoint: `app/Models/Site.php` (the search legs and `searchMatchReason()` versus
`DEPLOY_FILTERS` and the `deploy` filter branch from the P1-7 worker — they coexist in one method
without touching each other's lines), `lang/{tr,en}/sites.php` (`search_match.*` / `bulk_danger.*`
versus `deploy_states.*`), `resources/views/ops/sites/_region.blade.php` (row `@php` block and the
bulk row versus nothing else), `public/css/ops.css` (`.site-search-match`) and
`public/css/ops-ui.css` (`.sites-bulk-danger`). Every edit was a targeted string replacement rather
than a file rewrite, precisely so a concurrent worker's lines could not be clobbered. No `git
stash`, no `git worktree`, no commit.

One thing the reviewer must know: verifying the pre-existing `BulkThrottleResilienceTest` failures
required a `git stash push` / `git stash pop` cycle. The stash listing at that moment contained only
this wave's files, and the pop restored all of them, so the other worker's changes arrived
afterwards and were never in the stash. Nothing was lost — but a second `stash` while two workers
share one worktree is a real hazard, and the next cycle should use `git worktree` instead if it
needs a clean-tree comparison.

### Fifth verification run, after P1-11 + P2-18 Domains landed (wave 3)

Run over this slice and the neighbours that share the Domains list or the bulk-bar contract.
P1-9 files were not opened.

| Check | Command | Result |
|-------|---------|--------|
| This slice | `php artisan test tests/Feature/Ops/DomainBulkBindTest.php tests/Feature/Ops/DomainListEmptyStatesTest.php` | **16 passed** (DomainBulkBindTest 10, DomainListEmptyStatesTest 6) |
| Neighbours | `TruthfulJobCompletionTest` `SiteDomainReconcileTest` `OpsListFragmentTest` `SiteBulkSelectionScopeTest` | **24 passed** — bind is not `Tetiklendi`, the domains index still lists rows, fragment contract holds, Sites `all=1` bar untouched |
| Combined | the six files above together | **40 passed** (212 assertions) |
| Style | `vendor/bin/pint` on this slice's PHP | clean (fixed `line_ending` on the new files) |
| No outbound live calls | `Http::preventStrayRequests()`; success cases fake `https://coolify.test/*`; no-op / unready assert `Http::assertNothingSent()` | pass |
| No new hard-coded UI strings | `domains.bulk.*` / `domains.empty.filtered_*` / `domains.empty.import` / `ops.jobs.bulk_bind` / `ops.jobs.subject_domains` exist in `lang/tr` **and** `lang/en`; locale assertions use a `locale=tr` user | pass |
| Git state | `git status` | **dirty, nothing committed** — no commit, no PR, no push |

A Windows PHP segfault showed up when `Http::fake` was a closure that returned 404 for
unmatched URLs under `preventStrayRequests()`. The passing shape is a host pattern
(`https://coolify.test/*`). Worth knowing before the next Coolify sweep test copies the
callback style that `BulkThrottleResilienceTest` uses — that file is fine on this machine
in isolation, but pairing it with `preventStrayRequests()` in a new class crashed the
process with exit `-1073741819`.

### Sixth verification run, after P1-9 palette landed (wave 3)

Run over the palette slice and the lists whose scopes it reuses. Mail-servers files were
not edited (pint touched `MailListEmptyStatesTest.php` line endings only).

| Check | Command | Result |
|-------|---------|--------|
| Full suite | `php artisan test` | **732 passed, 0 failed** (3789 assertions) |
| This slice + reused scopes | `PaletteSearchTest` `KeyboardShortcutsTest` `ThemeCatalogPageTest` `ThemeListEmptyStatesTest` `SiteFleetSearchTest` | **36 passed** (216 assertions) |
| Neighbours | `NavPagesTest` `OpsListFragmentTest` `SiteCrudTest` `MailListEmptyStatesTest` | **32 passed** |
| Style | `vendor/bin/pint --dirty` | clean on this slice; also fixed `line_ending` on `MailListEmptyStatesTest.php` (other worker) |
| JS syntax | `node --check public/js/ops-palette.js` `ops-shortcuts.js` | pass. No JS runner; browser chords verified by reading the script |
| No outbound live calls | `PaletteSearchTest` uses factories only and never constructs a Coolify client | pass |
| No new hard-coded UI strings | `ops.shortcuts.palette` and `ops.palette.*` exist in `lang/tr` **and** `lang/en`; locale assertions use a `locale=tr` user | pass |
| Git state | `git status` | **dirty, nothing committed** — no commit, no PR, no push |

P1-10 and P2-18 Themes were already in **Done** from the previous tick of this worker;
this tick only finished the palette half of P1-9.

### P1-14 leftover + P2-18 leftover (Coolify inventory) · List controls and two-cause empty

- **Operator-visible change:** `/coolify/{connection}` inventory is no longer Ctrl+F. Search, a kind filter and an active/inactive filter update the four allowlists in place; without JS they are ordinary GET controls and the page opens on the inventory tab. An empty connection offers **Sync** (a viewer is told the role is read-only). A filtered miss names the inventory size, one removable chip per filter, and **Filtreleri temizle**. A server/project/env/git detail with no linked sites now offers Sync + back, not a title alone.
- **How it works:** `CoolifyInventoryQuery` filters the already-loaded allowlists (`q` on name / uuid / IP / project / git kind, allowlisted `kind` and `status`). `ListFragment::respond` on `CoolifyConnectionController@show` swaps `ops.coolify._inventory-region` only. Defaults still read the unfiltered relations. Region requests skip `applyUnambiguousDefaults()` so typing cannot persist. Unknown `kind` / `status` values are dropped. `%` is literal (in-memory `str_contains`). Pagination is omitted: four collections cannot share one pager without becoming a different page.
- **Files:** `app/Support/Lists/CoolifyInventoryQuery.php`, `app/Http/Controllers/Ops/CoolifyConnectionController.php`, `resources/views/ops/coolify/{show,_inventory-region,inventory-show}.blade.php`, `lang/{tr,en}/coolify.php`, `docs/modules/{ops-list-async,coolify-client}.md`, `docs/plans/progress-ledger.md`.
- **Tests:** new `CoolifyInventoryListTest` — 10 cases (empty offers Sync, viewer read-only, search/kind/status, uuid + IP, `%` literal, filtered-empty chips + size, chip isolation, async region, Turkish copy, inventory-show empty offers Sync, unknown filters widen). `OpsListFragmentTest` now includes `/coolify/{connection}`. `CoolifyConnectionsTest` / `CoolifyInventoryDetailTest` unchanged.
- **Docs + ledger:** updated.
- **Notes:** P1-15 is in flight on another worker (`sites/_region.blade.php`, `BulkAutoDeploySiteRequest`, `bulkPinSuggestions`). Those files were not claimed here except a broken view-data key that was reverted, and test assertions that would have gone red (`confirm_auto_on` / `confirm_auto_off`, `enabled` on the existing auto-deploy posts). Fleet dashboard still has no toolbar.

### P1-10 leftover · `updated` column is a relative age, never **Eski**

- **Operator-visible change:** the optional Sites **Güncellendi** column no longer prints `Y-m-d H:i`. It shows the same relative age as health/live/publish (`4 sa önce`, absolute time in the tooltip) and never the **Eski** word — a site nobody edited for four hours is not a broken poll.
- **How it works:** `<x-ops.freshness>` gained `markStale` (default true). `OpsFreshness::describe($at, markStale: false)` keeps the relative label and forces `stale=false`. The `updated` cell is the only caller that turns it off.
- **Files:** `app/Support/OpsFreshness.php`, `resources/views/components/ops/freshness.blade.php`, `resources/views/ops/sites/_cell.blade.php`, `docs/modules/ops-sites.md`.
- **Tests:** `OpsFreshnessTest` — 5 passed (new `markStale=false` case). `SiteFreshnessBadgeTest` — 5 passed (updated column relative, never stale, Turkish).
- **Docs + ledger:** updated.
- **Notes:** do not fold this into the 2× poll window; `updated_at` answers a different question.

### Seventh verification run, after P1-12 + Coolify leftover + P1-10 leftover (wave 4)

Run by the P1-12 worker over the whole dirty tree (P1-6 / P1-13 / P1-15 / P2-17 also in it).

| Check | Command | Result |
|-------|---------|--------|
| Full suite | `php artisan test` | **790 passed, 0 failed** (4269 assertions) |
| This slice | `php artisan test tests/Feature/Ops/AgentSecretFleetTest.php` | **11 passed** |
| Neighbours | `FleetDashboardTest` `FleetFailedDeployWindowTest` `FailedDeployFilterTest` `ConfirmMatrixTest` `SiteBulkSelectionScopeTest` `SiteBulkDangerMenuTest` `TruthfulJobCompletionTest` | green — third attention list is capped at 8; failed-deploy drill-down is still only `?deploy=failed`; inject confirm is in the matrix |
| Style | `vendor/bin/pint --dirty` | clean after a `line_ending` fix on `AgentSecretFleetTest.php` |
| No outbound live calls | `Http::preventStrayRequests()`; inject cases fake `https://coolify.test/*`; mixed selection `Http::assertSentCount(1)` | pass |
| No new hard-coded UI strings | `fleet.kpis.agent_*` / `sites.agent_states.*` / `sites.agent.bulk*` / `ops.jobs.bulk_inject_agent_secret` exist in `lang/tr` **and** `lang/en`; locale assertion uses a `locale=tr` user | pass |
| Git state | `git status` | **dirty, nothing committed** — no commit, no PR, no push |

An earlier full-suite run in the same hour failed once on `SiteListEmptyStatesTest` (`clearUrl` / `view=all`) while P1-6 was still being written into `SiteController@index`. Re-run of that class and of the full suite after it landed: green.

### Eighth note — what this worker shipped vs what siblings closed

This worker's complete slices: **P1-14 leftover + P2-18 leftover (Coolify inventory)**, **P1-10 leftover (`updated` never Eski)**, **P1-12 (agent-secret fleet card)**. P1-15, P2-17, P1-13 and P1-6 landed on other workers in the same dirty tree; those files were not rewritten here except filter_agent plumbing and assertions that would have gone red.

### Ninth verification run, after empty-copy polish (~02:45 Istanbul)

This tick only. Health-verdict files were not opened. Not the 07:00 wake table.

| Check | Command | Result |
|-------|---------|--------|
| This slice | `php artisan test tests/Feature/Themes/ThemeListEmptyStatesTest.php tests/Feature/Ops/MailListEmptyStatesTest.php` | **13 passed** (73 assertions). ThemeListEmptyStatesTest 5, MailListEmptyStatesTest 8. |
| Style | not run (`pint --dirty` would walk the whole shared tree) | — |
| No outbound live calls | empty-state tests use factories only | pass |
| No new hard-coded UI strings | changed keys exist in `lang/tr` and matching `lang/en` where the EN string was also wrong | pass |
| Git state | `git status` | **dirty, nothing committed** — no commit, no PR, no push |
| Sibling in flight | `HealthAppFilterTest` (15 cases) + verdict migration present | left alone |

### Wake verification (filled 2026-09-14 ~01:00 Istanbul — past the 07:00 Sun target)

Operator handoff. Do not treat the ninth (~02:45) or nineteenth (~03:40) runs as the morning numbers. Same facts are at the top of this file.

| Check | Command | Result |
|-------|---------|--------|
| Full suite | `php artisan test` | **847 passed**, 0 failed (**4619** assertions), 209.25s |
| Health / app filters | inside the full suite (`HealthAppFilterTest`) | **18 passed** (sibling finished; ADR-10 in the tree) |
| Empty-copy neighbours | inside the full suite | `ThemeListEmptyStatesTest` **5**, `MailListEmptyStatesTest` **8**, `CoolifyInventoryListTest` **10** — all pass |
| Style | `php vendor/bin/pint --dirty --test` | **fail** — `PaletteFilters.php` `line_ending` only. `--test` wrote nothing. |
| JS syntax | `node --check` on `ops-jobs.js` `ops-ui.js` `ops-list.js` `ops-palette.js` `ops-shortcuts.js` `ops-contracts.js` | pass |
| JS contracts | `node --test tests/js/ops-contracts.test.js` | **18 passed**, 0 failed |
| No outbound live calls in tests | overnight tests use factories / `Http::fake` / `Http::preventStrayRequests()` | pass (no live Coolify host in this run) |
| No new hard-coded UI strings | overnight keys exist in `lang/tr` **and** `lang/en`; compose-order copy is `__('coolify.errors.*')` | pass for the overnight slices (not a fresh locale audit of every Blade file) |
| Git state | `git status` | **dirty, nothing committed** — staged overnight pile + unstaged compose-domain / report / ledger hunks. HEAD `2ccf2ae`. |
| ADR-10 columns | `2026_09_13_023500_add_health_app_verdicts_to_sites_table.php` present; `HealthAppFilterTest` 18 green | landed |
| ADR-11 | five `node --test` slices in `tests/js/ops-contracts.test.js` (**18**). No `package.json`. No jsdom. `PlaneUI.refresh()` still waits. | landed as far as duck-typed contracts; fixture leftover open |

### Tenth verification run, after ADR-11 + counts-age flake (tick 5 / ~02:40)

This tick only. Verdict files were not edited on purpose (pint `--dirty` did
touch their line endings). Not the 07:00 wake table.

| Check | Command | Result |
|-------|---------|--------|
| Focused overnight dirty set | 30 test files (ConfirmMatrix, saved views, fleet, activity, palette, bulk, domains, mail, Coolify inventory, waiting bucket, …) | **232 passed, 1 failed** — the 1 is `SiteListAppHealthCountCostTest` counts-age, a `diffForHumans()` tick |
| After the freeze | `php artisan test tests/Feature/Sites/SiteListAppHealthCountCostTest.php` | **6 passed** (20 assertions) |
| Sibling probe (not this slice) | `php artisan test tests/Feature/Ops/HealthAppFilterTest.php` | **16 passed** (57 assertions) |
| JS syntax | `node --check` on `ops-jobs.js` `ops-list.js` `ops-ui.js` `ops-confirm.js` `ops-palette.js` `ops-shortcuts.js` `ops-async.js` `ops-app-health.js` | pass |
| Style | `vendor/bin/pint` on the cost-test file | clean. A mistaken `pint --dirty` also fixed line endings on the in-flight verdict files |
| Git state | `git status` | **dirty, nothing committed** — no commit, no PR, no push |

### Eleventh verification run, after ADR-11 first `node --test` slice (~02:50)

This tick only. Verdict / `HealthAppFilterTest` files were not opened. Not the 07:00 wake table.

| Check | Command | Result |
|-------|---------|--------|
| This slice | `node --test tests/js/*.js` | **4 passed**, 0 failed |
| JS syntax | `node --check public/js/ops-contracts.js public/js/ops-list.js public/js/ops-jobs.js` | pass |
| Sibling health/app | not run — leave `HealthAppFilterTest` alone | skipped |
| Git state | `git status` | **dirty, nothing committed** — no commit, no PR, no push |

### Twelfth verification run, after P1-7 leftovers / inspect-job stamp (~02:50 close)

ADR-10 product slice only. Did not start ADR-11 `tests/js/`. Not the 07:00 wake table.

| Check | Command | Result |
|-------|---------|--------|
| This slice | `php artisan test tests/Feature/Ops/HealthAppFilterTest.php` | **18 passed** (68 assertions) |
| Neighbours | `FailedDeployFilterTest` `FleetDashboardTest` `FleetFailedDeployWindowTest` `FleetDashboardListTest` `SiteListAppHealthCountCostTest` `SiteAppHealthTest` `SiteAgentHealthTest` `SiteSavedViewsTest` `AgentSecretFleetTest` `SiteHealthEvaluatorTest` | **81 passed** (408 assertions) |
| Style | `vendor/bin/pint --dirty` | passed |
| Git state | `git status` | **dirty, nothing committed** — no commit, no PR, no push |

### Thirteenth verification run, after ADR-11 second `node --test` slice (~02:55)

This tick only. Verdict / `HealthAppFilterTest` files were not opened. Not the 07:00 wake table.

| Check | Command | Result |
|-------|---------|--------|
| This slice | `node --test tests/js/*.js` | **8 passed**, 0 failed (4 first-slice + bulkScope, interpolateConfirm, isTyping, confirmOpen) |
| JS syntax | `node --check public/js/ops-contracts.js public/js/ops-ui.js public/js/ops-shortcuts.js public/js/ops-palette.js` | pass |
| Focused Pest | `php artisan test tests/Feature/Ops/KeyboardShortcutsTest.php` | **6 passed** (47 assertions) |
| Sibling health/app | not run — leave `HealthAppFilterTest` alone | skipped |
| Git state | `git status` | **dirty, nothing committed** — no commit, no PR, no push |

### Fourteenth verification run, after full-suite confirm + Coolify hint polish (~02:55)

Not the 07:00 wake table. Verdict / `HealthAppFilterTest` files were not opened. Health Done already matches the tree (`SiteFilterVerdict`, `2026_09_13_023500`, `HealthAppFilterTest` 18, ADR-10, ledger).

| Check | Command | Result |
|-------|---------|--------|
| Full suite (before polish) | `php artisan test` | **816 passed**, 0 failed (4450 assertions) |
| This slice | `php artisan test tests/Feature/Ops/CoolifyConnectionsTest.php` | **13 passed** (115 assertions), including the new `listServers` leak case |
| Full suite (after polish) | `php artisan test` | **817 passed**, 0 failed (4455 assertions) |
| JS | `node --test tests/js/*.js` | **8 passed**, 0 failed |
| Style | `vendor/bin/pint --dirty` | passed |
| Git state | `git status` | **dirty, nothing committed** — no commit, no PR, no push |

### Fifteenth verification run, after jobs failed-only + activity CSV (~03:15)

This tick. Coolify hint files were not opened. Not the 07:00 wake table.

| Check | Command | Result |
|-------|---------|--------|
| Baseline before this tick | `php artisan test` | **816 passed**, 0 failed (4450 assertions) — sibling later recorded 817 after Coolify hint polish |
| This slice + neighbours | `JobsFailedFilterTest` `ActivityFeedTest` `JobsPollPressureTest` `DeploymentPollWidgetTest` | **36 passed** (194 assertions). JobsFailedFilterTest 5, ActivityFeedTest 14, JobsPollPressureTest 5, DeploymentPollWidgetTest 12 |
| JS | `node --test tests/js/*.js` | **10 passed**, 0 failed (was 8; + `isFailedStatus`, `jobsIndexUrl`) |
| JS syntax | `node --check public/js/ops-contracts.js public/js/ops-jobs.js` | pass |
| Style | `vendor/bin/pint --dirty` | clean (`line_ending` on `JobsFailedFilterTest.php`) |
| Full suite after this tick | `php artisan test` | **825 passed**, 0 failed (4491 assertions) — 817 after Coolify hint + 5 `JobsFailedFilterTest` + 3 `ActivityFeedTest` |
| Git state | `git status` | **dirty, nothing committed** — no commit, no PR, no push |

### Sixteenth verification run, after deep-link copy + leftover clear + env-row filter (~03:15)

Sibling tick in the same dirty tree (Coolify-link copy, `DomainClearSweep`, env-row haystack). Not the 07:00 wake table.

| Check | Command | Result |
|-------|---------|--------|
| Sibling + bind-copy neighbours | `SiteDetailTest` `DomainBulkClearTest` `DomainBulkBindTest` `SettingsEnvDefaultsTest` `ConfirmMatrixTest` `SettingsJumpTest` | **46 passed** (SiteDetailTest 9, DomainBulkClearTest 7, DomainBulkBindTest 11, SettingsEnvDefaultsTest 4, ConfirmMatrixTest 8, SettingsJumpTest 7) |
| Full suite (first run this tick) | `php artisan test` | **1 failed**, 844 passed (4608 assertions) — `AgentSecretInjectTest` rotate `data-confirm` vs Blade `&#039;` in a Faker apostrophe name. Not a product regression. |
| JS | `node --test tests/js/*.js` | **13 passed**, 0 failed |
| JS syntax | `node --check public/js/ops-contracts.js public/js/ops-ui.js` | pass |
| Style | `vendor/bin/pint --dirty` | clean |
| Git state | `git status` | **dirty, nothing committed** — no commit, no PR, no push |

### Seventeenth verification run, after copy IDs + Settings jump + Domains skip copy + hidden-tab contract (~03:30)

This tick. Not the 07:00 wake table.

| Check | Command | Result |
|-------|---------|--------|
| This slice + neighbours | `SiteDetailTest` `SettingsJumpTest` `DomainBulkBindTest` `PaletteSearchTest` `SettingsEnvDefaultsTest` `GithubSettingsTest` | **44 passed** (289 assertions) |
| Sibling surfaces that share files | `DomainBulkClearTest` `ConfirmMatrixTest` `KeyboardShortcutsTest` `JobsFailedFilterTest` | **26 passed** (296 assertions) |
| JS | `node --test tests/js/*.js` | **13 passed**, 0 failed |
| JS syntax | `node --check public/js/ops-contracts.js public/js/ops-ui.js public/js/ops-jobs.js` | pass |
| Style | `vendor/bin/pint --dirty` | passed (`line_ending` on this slice; also fixed sibling `DomainClearSweep` / `DomainBulkClearTest`) |
| Full suite | `php artisan test` | **845 passed**, 0 failed (4611 assertions) |
| Git state | `git status` | **dirty, nothing committed** — no commit, no PR, no push |

### Eighteenth verification run, after confirm-assert `e()` flake (~03:30)

This tick. Not the 07:00 wake table.

| Check | Command | Result |
|-------|---------|--------|
| Flake + leftover neighbours | `AgentSecretInjectTest` `CoolifyDeploySettingsTest` `CoolifySiteSyncTest` `SiteDetailTest` `DomainBulkClearTest` `DomainBulkBindTest` `SettingsEnvDefaultsTest` `ConfirmMatrixTest` `SettingsJumpTest` | **67 passed** (582 assertions) |
| JS | `node --test tests/js/*.js` | **13 passed**, 0 failed |
| JS syntax | `node --check public/js/ops-contracts.js public/js/ops-ui.js` | pass |
| Style | `vendor/bin/pint --dirty` | clean |
| Full suite after flake fix | `php artisan test` | **845 passed**, 0 failed (4611 assertions) — 825 baseline + this cycle's new files |
| Git state | `git status` | **dirty, nothing committed** — no commit, no PR, no push |

### Nineteenth verification run, after ADR-11 list fetch / replaceState (~03:40)

This tick. PHP / Domains / Settings / site-detail were not opened. Not the 07:00 wake table.

| Check | Command | Result |
|-------|---------|--------|
| This slice | `node --test tests/js/*.js` | **18 passed**, 0 failed (was 13; + `isSameList`, `listQueryChanged`, `shouldPatchListRegion`, `listHistoryMode`, `shouldInterceptListHref`) |
| JS syntax | `node --check public/js/ops-contracts.js public/js/ops-list.js` | pass |
| Focused Pest | not run — PHP not touched | skipped |
| Git state | `git status` | **dirty, nothing committed** — no commit, no PR, no push |

### Twentieth verification run — morning finalize (2026-09-14 ~01:00 Istanbul)

Past the Sun 07:00 target. Whole current worktree (staged overnight + unstaged compose-domain hunks). This is the operator suite. See the handoff at the top.

| Check | Command | Result |
|-------|---------|--------|
| Full suite | `php artisan test` | **847 passed**, 0 failed (**4619** assertions), 209.25s |
| JS contracts | `node --test tests/js/ops-contracts.test.js` | **18 passed**, 0 failed |
| JS syntax | `node --check` on `ops-jobs.js` `ops-ui.js` `ops-list.js` `ops-palette.js` `ops-shortcuts.js` `ops-contracts.js` | pass |
| Style | `php vendor/bin/pint --dirty --test` | **fail** — `PaletteFilters.php` `line_ending` only |
| Git state | `git status` | **dirty, nothing committed** — no add, no commit, no PR, no push. HEAD `2ccf2ae`. |
