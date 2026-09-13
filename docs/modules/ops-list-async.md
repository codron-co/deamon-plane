# Async list regions

Ops list pages update in place. Changing a search term, a filter, a sort, a page or
the saved column layout re-renders **only the table**, never the whole document.

Covered pages: **Sites** (`/sites` — `q` / `channel` / `status` / `publish` / `deploy` / `agent` / `health` / `app` / `pack`), **Domains** (`/domains`), **Themes** (`/themes`),
**Mail servers** (`/mail-servers`), **Coolify inventory** (the connection show tab,
`GET /coolify/{connection}`), **Activity** (`/activity`), **Fleet** (`/`).

Settings (`/settings`) is **not** a ListFragment page. Env-defaults is a write
form: swapping the table would drop unsaved rows, and a GET `q` that omitted
hidden keys would delete them on Save. The jump search (`data-ops-settings-search`)
filters sections and env rows in place via `PlaneOpsContracts.textMatches` /
`envRowVisible`. Save still posts the whole catalog.

`/` is not a second Sites list. The toolbar filters the four attention cards
already on the dashboard: `q` (site name / domain / slug, plus failed-deploy
error text) and `kind=unhealthy|failed|agent|dockerfile`. KPIs stay the
unfiltered snapshot and live outside `[data-ops-list-region]`, so a fragment
request skips `FleetDashboardKpis::snapshot()` (P0-2). Search runs **before**
the attention cap so a site in the `+N` overflow is still findable. Pagination
is omitted — the cards are already capped. Unknown `kind` values are dropped.
A clean fleet and a filtered miss are different empty states.

Coolify inventory is not a first-class list URL — it is four allowlists on a
tabbed detail page. The toolbar and `[data-ops-list-region]` still sit on that
same show route. A fragment response is only the four tables / empty states,
never the hero, overview or configuration form. `applyUnambiguousDefaults()`
does not run on a region request (it persists; a keystroke must not write).
When filters are active, the page opens the inventory tab (`data-initial-tab`)
so a no-JS GET does not dump the operator back on Overview. Pagination is
omitted on purpose: four independent collections cannot share one pager without
becoming a different page, and a connection's allowlists are small. Search +
kind + status replace Ctrl+F.

## Contract

One route, two representations:

| Request | Response |
|---------|----------|
| Normal visit | full page, `X-Ops-List-Region: 0` |
| `X-Ops-List-Fragment: region` | the region partial only, `X-Ops-List-Region: 1` |

`App\Support\Lists\ListFragment::respond($request, $page, $region, $data)` picks the
view and sets `Vary: X-Ops-List-Fragment`. Authorization, filters and sort run before
the split, so the region can never show something the page would not.

Views come in pairs: `ops/{sites,domains,themes,mail-servers}/index.blade.php` renders
the shell, the toolbar and `<div data-ops-list-region>`; `_region.blade.php` renders the
table, the bulk form, the empty states and the pager. Both get the same view data.
Coolify is the exception that still follows the contract: `ops/coolify/show.blade.php`
keeps the hero and tabs; `ops/coolify/_inventory-region.blade.php` is the swapped
region. Domains now ship the same two-cause empty state and a bulk bar (`domain_ids[]` /
`filter_q` / `filter_unbound`) that `ops-ui.js` treats like Sites (`name$="_ids[]"`).
Mail servers keep the platform-mail chip in the shell so a fragment swap never drops it.
Fleet keeps the KPI grid in the shell; `ops.dashboard.attention` is the swapped region.

## Markup

| Hook | Meaning |
|------|---------|
| `data-ops-list` | page wrapper holding one toolbar and one region |
| `data-ops-list-toolbar` | GET filter form; submits are intercepted |
| `data-ops-list-region` | the swapped element |
| `data-ops-list-filter` | select or checkbox that applies on change |
| `data-ops-list-clear` | "clear filters" link, shipped `hidden` while idle |
| `data-ops-list-view` | saved-view chip; intercepted like a region requery |
| `data-ops-list-refresh` | POST form whose success needs a fresh region (column picker, saved views) |
| `data-ops-list-focus` | focus target to restore after a swap (`sort:{column}`, `page:next`) |

`ops-list.js` intercepts toolbar submits and in-region links that re-query the same
path (including saved-view chips, `data-ops-list-view`), then swaps the region, syncs
the toolbar, the `filter_*` hidden inputs, the column picker and the save-view form
from the URL / region marker, and updates history — `replaceState` while typing or
filtering, `pushState` for sort and paging, so Back walks pages instead of keystrokes.
`PlaneUI.refresh()` re-arms the primitives that bind per element (action menus,
favicon marks, bulk selection, hints); row clicks, confirmations and selects are
delegated and survive. Sites saved-view chips live in the region so the active chip
re-renders with the table; the save form stays in the toolbar so a search keystroke
does not wipe a name being typed.

## Progressive enhancement

Every control stays a plain link or GET form. Without JavaScript the browser
navigates and the same server code renders the same result. When the response is not
a region — sign-in redirect, error page, stale deployment, network failure — the
script hands the URL to the browser instead of patching the DOM.

Pest (`OpsListFragmentTest`) is the source of truth for that JS-off path.
`ops-list.js` `toolbarUrl` (empty controls dropped, `page` stripped), same-list
requery, patch-vs-navigate (`shouldPatchListRegion` / `listFetchHeaders`),
`replaceState` vs `pushState` vs keep (`listHistoryMode` / `listHistoryWrite`),
bulk `__COUNT__` interpolate, shortcut `isTyping` / `confirmOpen`, widget
`pageIsHidden` / `shouldSchedulePoll`, and Settings `textMatches` are
`node --test tests/js/*.js` — [ADR-11](../decisions/adr-11-dom-test-harness.md).
`PlaneUI.refresh()` after a region swap still waits for a jsdom fixture.

Server writes that change what the list shows may return `refresh_list: true` in their
JSON and the region re-renders after the toast.
