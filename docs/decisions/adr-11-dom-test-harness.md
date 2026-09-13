# ADR-11 · DOM harness: Node tests + jsdom, not Playwright

**Status:** accepted (2026-09-13). Do not add Playwright, Dusk, Cypress, or a JS build pipeline to close this.

## Context

Eight overnight slices in a row shipped browser-only acceptance lines that Pest cannot execute:

- P0-1 — `__COUNT__` interpolation after a checkbox change (`ops-ui.js`)
- P0-3 / P0-4 — poll backoff, `visibilitychange`, in-place row patch (`ops-jobs.js`)
- P2-16 — danger-menu keyboard / upward popover (`ops-ui.js` + CSS)
- P1-9 — `/`, `g` chords, palette arrows, confirm-modal ownership (`ops-shortcuts.js`, `ops-palette.js`)
- P1-6 — saved-view chips survive an `ops-list.js` region swap

The current suite walks the **contract the browser consumes**: `data-*` attributes, fragment headers, translated copy, `ConfirmMatrixTest`. That is as far as PHP can go. `node --check` is the only automated check on the scripts themselves. `KeyboardShortcutsTest` says so in its docblock.

A third browser suite was proposed (Playwright, Pest Browser, Dusk). This repo has no `package.json`, no bundler, no CI browser lane, and a hard UI rule: Blade + vanilla files under `public/js/`, progressive enhancement, no frontend migration for a test gap.

## What Pest already proves (keep)

- Every `data-confirm` form carries title, label, and an explicit danger flag (`ConfirmMatrixTest`).
- Overlay / palette markup is the only place chords and copy are declared (`KeyboardShortcutsTest`, `PaletteSearchTest`).
- Widget poll *server* pressure and `data-poll-*` wiring (`JobsPollPressureTest`).
- List URLs answer twice (`OpsListFragmentTest`). JS-off is still a full navigation.
- Locale copy is requested through a `locale=tr` user, not `App::setLocale()`.

Pest is the source of truth for **progressive enhancement**. A JS test that is the only proof a page works is a regression of that rule.

## What Pest cannot prove (the harness)

Closed-over logic inside IIFEs:

| Script | Untestable-in-PHP behavior |
|--------|----------------------------|
| `ops-jobs.js` | `advanceBackoff()` tiers; `document.hidden` skips `schedulePoll()`; chained `setTimeout` (not `setInterval`); row patch vs rebuild |
| `ops-ui.js` | `scope()` / `syncConfirm()` (`__COUNT__` / `__TARGET__`); `PlaneUI.refresh()` re-arms after a region swap |
| `ops-list.js` | `toolbarUrl()` drops empties; `fetch` + `replaceState` vs handing the URL to the browser |
| `ops-confirm.js` | focus trap, Escape / outside click, submitter `requestSubmit` |
| `ops-shortcuts.js` / `ops-palette.js` | `isTyping`, `confirmOpen`, arrow / Enter, `Ctrl/⌘+K` from a field |

`ConfirmMatrixTest` is a server-rendered attribute walker. It is not a JS runner and must not be stretched into one.

## Decision

1. **Pest stays.** Do not replace Blade / `data-*` / fragment tests with browser tests.

2. **Land `node --test` under `tests/js/`.** First slice extracts the four contracts as importable functions the IIFEs call — they must not stay closed-over copies:

   - poll backoff: next tier given `(unchangedPolls, pollTier, slowAfter, tierCount)` and “signature changed → reset”
   - bulk scope + confirm interpolate: `(all, checked, filteredTotal)` → count; `__COUNT__` / `__TARGET__` replace
   - toolbar URL: empty controls dropped, `page` stripped
   - typing / confirm ownership: the predicates `isTyping` and `confirmOpen`

   Pure functions need only Node. No `package.json` until a test actually needs a DOM.

3. **jsdom (or happy-dom) is allowed as a test-only npm dependency** when a test needs `document`, `sessionStorage`, `visibilitychange`, or `fetch`. Dev-only. No Vite, no TypeScript rewrite, no React/Vue, no app bundler.

4. **Playwright, Cypress, Laravel Dusk, and `pestphp/pest-plugin-browser` are rejected** for Plane v1. They add browsers, a second CI personality, and a flake budget this ops panel does not earn. Revisit only if a bug is proven untestable in jsdom (we have no Shadow DOM and no layout-dependent widget).

5. **Scope cap.** The six scripts in the table above. `theme-git.js`, `sites-cloudflare.js`, and the rest stay Pest-only until they grow a browser-only acceptance line.

6. **Do not rewrite production IIFEs into ESM** just to make them importable. The app loads classic `<script>` tags. Extraction is a small `public/js/ops-contracts.js` (or one file per contract) that both the IIFE and `node --test` can load — UMD / `globalThis.PlaneOpsContracts`, not a bundler.

7. **CI shape.** `node --test tests/js/*.js` sits next to `php artisan test` (the directory form is not a module on Windows Node 22). `node --check public/js/*.js` stays. Failure in either lane is red. There is no third “open Chrome” lane.

## Rejected alternatives

| Alternative | Why not |
|-------------|---------|
| Keep `node --check` only | Eight notes this cycle. The gap is behavior, not syntax. |
| Playwright / Pest Browser / Dusk | Browsers + a second toolchain for 15 vanilla files. |
| Duplicate the algorithms inside `tests/js` | Two sources. The next poll-tier edit will drift. |
| App-wide ESM + Vite so tests can `import` | Violates the Plane UI skill. Progressive enhancement does not need a build. |

## Landing (next implementer)

Estimate **M**, not S.

1. Extract the four helpers. Wire the existing IIFEs. Do not change operator-visible behavior.
2. `tests/js/` with `node --test` for those helpers (Turkish strings stay out — copy is already Pest).
3. Add jsdom only for the first test that needs `document.hidden` or a fixture of `[data-ops-jobs]`.
4. One sentence in [ops-sites.md](../modules/ops-sites.md) and [ops-list-async.md](../modules/ops-list-async.md) pointing here once the first file is green.
5. Leave `SiteFilterVerdict` / `health=` / `app=` alone — that is ADR-10, a different worker.

**First slice (2026-09-13 ~02:50).** `public/js/ops-contracts.js` (`globalThis.PlaneOpsContracts` / CommonJS) holds `toolbarUrl`, `jobStateSignature`, `advanceBackoff`, `jobRowKey`, `diffJobRows`. `ops-list.js` and `ops-jobs.js` call those. Run: `node --test tests/js/*.js`. No `package.json`, no jsdom.

**Second slice (2026-09-13 ~02:55).** Same file now also holds `bulkScope`, `interpolateConfirm`, `isTyping`, `confirmOpen` (`CONFIRM_MODAL_SELECTOR`). `ops-ui.js` interpolates `__COUNT__` / `__TARGET__`; `ops-shortcuts.js` and `ops-palette.js` use the typing / confirm guards. Still no jsdom — `isTyping` is duck-typed; `confirmOpen` takes a `querySelector` stand-in. Still later: anything that needs `document` (`document.hidden`, list `fetch` / `replaceState`).

**Third slice (2026-09-13 ~03:15).** `isFailedStatus` and `jobsIndexUrl` — the widget's **Sadece hatalılar** toggle. `ops-jobs.js` polls `?failed=1` and hides non-failed rows already on screen. Still no jsdom; `sessionStorage` persistence stays in the IIFE.

**Fourth slice (2026-09-13 ~03:30).** `pageIsHidden` / `shouldSchedulePoll` — the leftover `document.hidden` poll skip. `ops-jobs.js` `schedulePoll` / `startPoll` / `visibilitychange` call those. Still no jsdom: tests pass `{ hidden: true }`. `textMatches` / `envRowVisible` are the Settings jump filter (empty query or a section needle keeps every env row). Still later: list `fetch` / `replaceState`.

**Fifth slice (2026-09-13 ~03:40).** List `fetch` / `replaceState` — `isSameList`, `listQueryChanged`, `shouldPatchListRegion`, `listFetchHeaders`, `listHistoryMode` / `listHistoryWrite`, `isListRequeryLink` / `shouldInterceptListHref`. `ops-list.js` calls those instead of closed-over copies. Still no jsdom: headers and `{ origin, pathname }` ducks. `PlaneUI.refresh()` after a region swap still waits for a fixture.

## Consequences

- Honest: the next widget or list-JS slice has a place to put a failing test *before* the edit.
- Cost: one small global + a Node lane. No browsers in CI.
- `ConfirmMatrixTest` and `KeyboardShortcutsTest` stay. Their docblocks should point here once `tests/js` exists, not before.
