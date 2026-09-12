# Async list regions

Ops list pages update in place. Changing a search term, a filter, a sort, a page or
the saved column layout re-renders **only the table**, never the whole document.

Covered pages: **Sites** (`/sites`), **Domains** (`/domains`), **Themes** (`/themes`).
Coolify inventory, mail servers and the fleet dashboard have no list controls yet; they
pick this up for free once they render a toolbar plus a region.

## Contract

One route, two representations:

| Request | Response |
|---------|----------|
| Normal visit | full page, `X-Ops-List-Region: 0` |
| `X-Ops-List-Fragment: region` | the region partial only, `X-Ops-List-Region: 1` |

`App\Support\Lists\ListFragment::respond($request, $page, $region, $data)` picks the
view and sets `Vary: X-Ops-List-Fragment`. Authorization, filters and sort run before
the split, so the region can never show something the page would not.

Views come in pairs: `ops/{sites,domains,themes}/index.blade.php` renders the shell,
the toolbar and `<div data-ops-list-region>`; `_region.blade.php` renders the table,
the bulk form, the empty states and the pager. Both get the same view data.

## Markup

| Hook | Meaning |
|------|---------|
| `data-ops-list` | page wrapper holding one toolbar and one region |
| `data-ops-list-toolbar` | GET filter form; submits are intercepted |
| `data-ops-list-region` | the swapped element |
| `data-ops-list-filter` | select or checkbox that applies on change |
| `data-ops-list-clear` | "clear filters" link, shipped `hidden` while idle |
| `data-ops-list-refresh` | POST form whose success needs a fresh region (column picker) |
| `data-ops-list-focus` | focus target to restore after a swap (`sort:{column}`, `page:next`) |

`ops-list.js` intercepts toolbar submits and in-region links that re-query the same
path, then swaps the region, syncs the toolbar and the `filter_*` hidden inputs from
the URL, and updates history — `replaceState` while typing or filtering, `pushState`
for sort and paging, so Back walks pages instead of keystrokes. `PlaneUI.refresh()`
re-arms the primitives that bind per element (action menus, favicon marks, bulk
selection, hints); row clicks, confirmations and selects are delegated and survive.

## Progressive enhancement

Every control stays a plain link or GET form. Without JavaScript the browser
navigates and the same server code renders the same result. When the response is not
a region — sign-in redirect, error page, stale deployment, network failure — the
script hands the URL to the browser instead of patching the DOM.

Server writes that change what the list shows may return `refresh_list: true` in their
JSON and the region re-renders after the toast.
