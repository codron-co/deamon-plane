# Shared CSS / JS / layout requests — Site detail

Site detail markup now matches the operational tab reference. These shared files were **not** edited in `ui/rollout-site-detail`. Orchestrator (or the shared-primitive owner) should apply the following.

## Layout (`resources/views/layouts/ops.blade.php`)

Do **not** require a Site-only script tag in the layout if the tabs primitive is folded into `public/js/ops-ui.js`.

If the primitive stays page-local, Site detail already loads it from `@section('scripts')`:

```blade
<script src="{{ asset('js/ops-site-tabs.js') }}?v={{ filemtime(public_path('js/ops-site-tabs.js')) }}" defer></script>
```

## JS (`public/js/ops-ui.js`)

Fold `public/js/ops-site-tabs.js` into `ops-ui.js` as `setupOpsTabs()` (or keep the page-local file and delete the Site `@section('scripts')` include after the fold).

Required behavior (already implemented in `public/js/ops-site-tabs.js`):

- Root: `[data-site-tabs]` / proposed `[data-ops-tabs]`.
- Tabs: `[role="tab"]` with `aria-controls`, `aria-selected`, `href="#panel-id"`.
- Panels: `[data-site-panel]` / proposed `[data-ops-panel]` with matching `id`.
- Click on a tab: `preventDefault()` — **must not scroll**.
- Only the active panel gets `hidden`; others are hidden **only after JS runs**.
- Do **not** ship `hidden` on every panel in HTML (progressive enhancement: all panels readable without JS).
- `history.replaceState` writes `#panel-id`; reload restores that tab.
- Keyboard: `ArrowLeft`, `ArrowRight`, `Home`, `End`; move focus and activate.
- `tabIndex` 0 on the selected tab, `-1` on the others (JS only).
- In-page links to `#panel-id` (for example Next action → Deployments) must activate the tab without scrolling.
- Guard with `data-enhanced="true"` so a later layout include does not double-bind.
- Keep existing `[data-favicon-host]` behavior in `ops-ui.js` unchanged.

## CSS (`public/css/ops-ui.css`)

Promote Site-specific selectors to `ops-*` primitives when a second screen needs them. Until then, keep the `.site-*` hooks working.

### Tabs

```css
.site-section-nav a:focus-visible,
[data-ops-tabs] [role="tab"]:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}
```

Existing `.site-section[hidden] { display: none; }` must remain. Do not add a CSS rule that hides non-active panels without the `hidden` attribute (that would break no-JS readability).

### Hint primitive (hover + keyboard focus)

Current `.site-hint` only opens on `:hover` and `:focus-visible`. Add `:focus` so keyboard focus always reveals the tooltip:

```css
.site-hint:hover [role="tooltip"],
.site-hint:focus [role="tooltip"],
.site-hint:focus-visible [role="tooltip"] {
    opacity: 1;
    visibility: visible;
    transform: translate(-50%, 0);
}

@media (prefers-reduced-motion: reduce) {
    .site-hint [role="tooltip"] { transition: none; }
}
```

Promote to `.ops-hint` when reused.

### Next-action card (copy left, primary action right)

Markup: `.site-card.site-next-action` with a copy wrapper as the first child and the button/form as the second.

```css
.site-next-action,
.ops-next-action {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    background: color-mix(in srgb, var(--accent) 5%, var(--surface-raised));
}
.site-next-action > div,
.ops-next-action > div { min-width: 0; }
.site-next-action h3,
.ops-next-action h3 { margin: 7px 0 6px; }
.site-next-action p,
.ops-next-action p { margin: 3px 0 0; color: var(--text-muted); font-size: 12px; line-height: 1.55; }
.site-next-action .btn,
.site-next-action form,
.ops-next-action .btn,
.ops-next-action form { flex: 0 0 auto; margin-left: auto; }

@media (max-width: 768px) {
    .site-next-action,
    .ops-next-action { align-items: flex-start; flex-direction: column; }
    .site-next-action .btn,
    .site-next-action form,
    .ops-next-action .btn,
    .ops-next-action form { align-self: flex-start; margin-left: 0; }
}
```

Site detail temporarily also adds `.site-card-head` on the next-action card so the row layout works before this lands. Remove that extra class once the rules above exist.

### Operation cards (channel / auto-deploy / agent)

Markup: `.site-card.site-operation` with `.site-card-head` (title + status/actions on one row) and `.site-operation-line` (select + submit).

```css
.site-operation h3,
.ops-operation h3 { margin: 0; font-size: 13px; }
.site-operation > .field-hint,
.site-operation > .ops-alert,
.ops-operation > .field-hint,
.ops-operation > .ops-alert { margin: 10px 0 0; }
.site-operation-line,
.ops-operation-line {
    display: flex;
    align-items: end;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
}
.site-operation-line .field,
.ops-operation-line .field { flex: 1; min-width: 180px; margin: 0; }
.site-operation-line .btn,
.ops-operation-line .btn { flex: 0 0 auto; }
.site-pin-status,
.ops-pin-status { margin: 10px 0 0; color: var(--text-muted); font-size: 12px; }
.site-pin-status b,
.ops-pin-status b { color: var(--text); font-weight: 600; }
.site-card-head .branch-version { margin-left: auto; flex: 0 0 auto; gap: 8px; }

@media (max-width: 768px) {
    .site-card-head { align-items: flex-start; flex-wrap: wrap; }
    .site-card-head .branch-version { margin-left: 0; }
}
```

Also apply `.site-operations-main > .site-card` the same reset already used for `.ops-panel` children:

```css
.site-operations-main > .site-card { margin: 0; }
```

### Danger zone card

Markup: `#danger .site-card.danger-zone` (kicker + title + lede left, delete right). Neutralize the legacy `.danger-zone` max-width/border-top from `ops.css`:

```css
#danger .danger-zone,
.ops-danger {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    max-width: none;
    margin: 0;
    padding: 17px;
    border: 1px solid color-mix(in srgb, var(--danger) 28%, var(--border));
    background: color-mix(in srgb, var(--danger) 8%, var(--surface-raised));
}
#danger .danger-zone h2,
#danger .danger-zone h3,
.ops-danger h2,
.ops-danger h3 { margin: 3px 0 0; }
#danger .danger-zone p,
.ops-danger p { margin: 3px 0 0; color: var(--text-muted); font-size: 12px; }

@media (max-width: 768px) {
    #danger .danger-zone,
    .ops-danger { align-items: flex-start; flex-direction: column; }
}
```

## Do not change

- Favicon loader (`[data-favicon-host]`).
- Confirm modal / `ops-confirm.js`.
- Pin / follow-HEAD / auto-deploy POST contracts (`name="enabled"`, `name="ref"`, follow-head action).
- Mailcow / mailbox UI (not in backend).
