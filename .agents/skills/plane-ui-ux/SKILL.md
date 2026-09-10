# Plane UI/UX Skill

Use this skill for **Deamon Plane** admin/ops interface work only. This is a Plane-specific skill; do not import or copy the Deamon CMS UI packs.

## Goal

Build a compact, fast, predictable operations UI that feels like one coherent product across Fleet, Sites, Coolify, Themes, Settings and future Plane screens.

The interface must optimize for scanability, operational confidence and low-error workflows. Visual polish matters, but clarity and consistency come first.

## Read before changing UI

1. `AGENTS.md`
2. `resources/views/layouts/ops.blade.php`
3. `public/css/ops.css`
4. The Blade view(s) and vanilla JS touched by the task
5. `docs/plans/2026-09-10-ui-ux-remediation.md` when the task concerns the UI foundation

Do not pull CMS theme-authoring assumptions into Plane.

## Current frontend constraints

- Laravel Blade server-rendered UI.
- CSS lives under `public/css/`.
- Small progressive-enhancement scripts live under `public/js/`.
- Do not introduce React/Vue/Tailwind/a new build pipeline for a UI-only task unless the task explicitly requires a frontend migration.
- Prefer reusable Blade partials/components, CSS tokens and small framework-free JS primitives.

## Product rules

### 1. One shell, one width system

Every authenticated page uses the same application shell, topbar spacing and content grid.

Use semantic content modes rather than arbitrary per-page widths:

- **standard**: normal application pages and dashboards; fills useful workspace width.
- **form**: form/card content may be internally constrained for readability, while the outer page still uses the standard shell.
- **full**: dense data views may use the entire available main column.

Never create a narrow 500–900 px island simply because an old page used a local `max-width`. A narrow form is fine; a narrow page shell is not.

### 2. Sidebar must be viewport-stable

Desktop sidebar behavior:

- `height: 100dvh` / sticky to viewport.
- brand is fixed at top.
- navigation is the scrollable middle region when needed.
- user control is fixed to the bottom of the sidebar and must never be pushed below a long page.

Mobile/tablet must switch to a deliberate compact navigation treatment; do not rely on desktop overflow accidentally wrapping.

### 3. User menu is a menu, not a permanent logout block

The bottom sidebar user control is a single clickable row containing avatar/initials, name and role. Opening it exposes:

- Account / Profile
- Preferences
- Appearance: Light / Semi-dark / Dark
- Language
- Sign out

Sign out is never a permanently exposed full-width button under the user name.

Menus must close on outside click and Escape and remain keyboard reachable.

### 4. Appearance uses design tokens

All themes must be driven by CSS custom properties, not copied selectors with scattered hard-coded colors.

Required modes:

- `light`
- `semidark`
- `dark`

At minimum tokenise:

- page/base surfaces
- raised/overlay surfaces
- borders
- primary/muted/faint text
- accent + hover/focus
- success/warning/danger/info
- shadows

Persist the selected appearance. Local persistence is acceptable as a progressive enhancement; user-level persistence is preferred when preferences are available in the backend.

Use `color-scheme` appropriately and avoid a flash of the wrong theme during page load.

### 5. Internationalisation is mandatory for user-facing copy

Do not add new hard-coded English/Turkish UI strings to Blade templates.

Target initial locales:

- Turkish (`tr`)
- English (`en`)

Requirements:

- Laravel translation files are the source of truth.
- `<html lang>` follows the active locale.
- Locale can be changed from the user menu or preferences.
- Persist locale per user when possible; session fallback is acceptable.
- Buttons, headings, empty states, validation-facing copy, table headers, tooltips and confirmation text must be translatable.
- Internal enum/database values may stay English; convert them to translated labels at presentation time.

### 6. Tables always lead to detail pages

For every resource table:

- clicking a non-interactive area of a row opens that resource's **show/detail** route.
- Enter on a focused clickable row does the same.
- links, checkboxes, menus and action buttons inside the row must not trigger row navigation.
- row hover/focus indicates clickability.
- the row target must never be an edit route.

Editing is an explicit action from a detail page or an explicit `Edit` button/menu item.

If a resource has no show page, create one before making its table row clickable.

### 7. Detail pages are operational summaries

A detail page should answer, at a glance:

- What is this resource?
- Is it healthy / active / failing?
- What environment/branch/version is it on?
- What is connected to it?
- What happened recently?
- What can I do next?

Use a summary header plus grouped facts/status cards, then secondary operational sections. Put edit/destructive actions in explicit action areas; do not turn the whole detail view into an edit form.

### 8. Terminology reflects what the operator actually controls

Do not expose internal naming when it misleads users.

For Sites specifically:

- display `Repo branch` when the value represents the deployed Git branch (`main`, `beta`, `alpha`).
- keep an internal `channel` concept only where it truly represents release policy/orchestration.
- display the reported Deamon/app version beside the repo branch as a badge. If unavailable, render a deliberate `Unknown`/`—` state rather than silently omitting the field.
- Site identity marks load the favicon from the site's **primary domain origin** (`https://{host}/favicon.ico`, then `/apple-touch-icon.png`). Keep the first letter as the no-JS / failure fallback. Do not use a third-party icon CDN. Reuse `[data-favicon-host]` on Sites index if a mark is shown.

### 9. Replace browser-default selects with the Plane select primitive

Do not ship raw browser-default dropdown UX for Plane forms/filters.

The Plane select must be progressively enhanced and preserve normal HTML form submission. It must support:

- branded trigger, chevron, menu surface and selected state
- disabled state
- long labels with ellipsis
- click outside to close
- Escape to close
- Arrow Up / Arrow Down navigation
- Home / End where practical
- Enter / Space selection
- visible focus state
- `aria-expanded`, `aria-haspopup`, `role=listbox` / `role=option` semantics or an equally accessible native strategy
- synchronization when JS dynamically replaces `<option>` elements
- dispatching the native `change` event so existing form scripts continue to work

Never break form submission or dynamic Coolify option loading just to style a select.

### 10. Forms use one rhythm

Each field follows:

`label -> optional help -> control -> validation/error`

Rules:

- consistent control height/radius/border/focus ring
- required state is understandable without relying on color alone
- related fields are grouped into titled sections
- advanced/dangerous fields are visually separated
- destructive actions never sit beside the primary Save action without separation
- server errors remain visible near the field and at the form level when useful

### 11. Status and version badges are semantic

Badges are for compact state, branch, version or environment metadata.

- Never communicate status by color alone.
- Keep shapes/spacing consistent.
- Branch and version badges should read as related metadata without looking like primary buttons.
- Unknown/loading/stale states must be explicit.

### 12. Empty, loading, success and error states are designed states

Do not leave large blank areas with no explanation.

Empty states should include:

- concise reason
- next action when one exists

Operational actions should provide immediate pending/working feedback and a clear success/failure message.

### 13. Topbar and navigation hierarchy

The topbar should provide:

- current page title
- breadcrumbs on nested/detail pages when helpful
- page-level actions on the right

Do not use the global topbar for unexplained static technical text. Environment/branch indicators must be actionable or clearly contextual.

### 14. Data density should feel intentional

Plane is an ops product; it may be dense, but it must not feel cramped.

- Keep vertical rhythm consistent.
- Prefer compact tables over oversized cards for repeated records.
- Use cards for summaries/KPIs, not as generic containers around everything.
- Do not waste half the screen on arbitrary `max-width` limits.
- Keep critical status/actions above the fold where practical.

### 15. Responsive behavior is part of the feature

Validate at least:

- ~1280 px desktop
- ~1440 px desktop
- ~1920 px wide desktop
- ~768 px tablet
- narrow mobile when the route is expected to be usable there
- long pages exceeding two viewport heights

A long page must never move the sidebar account control off-screen on desktop.

### 16. Accessibility baseline

Required:

- semantic buttons/links/forms
- keyboard access for all interactive controls
- `:focus-visible` treatment
- no color-only meaning
- adequate text/background contrast
- labels tied to form controls
- accessible names for icon-only actions
- reduced-motion preference respected
- minimum practical click/tap target around 32–40 px for compact desktop ops controls, larger where mobile requires it

## Reusable primitives to prefer

Before adding page-specific CSS/JS, check whether the need belongs in a reusable Plane primitive:

- app shell / sidebar / topbar
- user menu / popover
- button variants
- input / textarea
- custom select
- checkbox/radio
- badge/status pill
- table / clickable row
- toolbar/search/filter
- card/KPI
- tabs
- modal/confirmation
- toast/flash
- empty state
- breadcrumbs
- site identity mark (domain favicon)
- pagination
- skeleton/loading state

If two pages need the same behavior, build or extend the primitive instead of duplicating selectors and JS.

## Implementation workflow

1. Audit the target screen and its neighboring screens before editing.
2. Identify whether the problem belongs to a global primitive or a local screen.
3. Prefer the smallest shared fix that removes inconsistency across the product.
4. Preserve authorization and server-side behavior; UI cleanup must not bypass policies.
5. Keep progressive enhancement: core links/forms should remain valid HTML.
6. Add or update feature tests for route/permission/critical rendering behavior.
7. Manually verify keyboard interaction for menus/selects/clickable rows.
8. Compare at standard + wide viewport widths and on a long page.

## Acceptance checklist

A UI task is not done until all relevant answers are yes:

- Does the page use the standard Plane shell and spacing?
- Does it use the shared tokens/primitives rather than new one-off styles?
- Is every user-facing string translatable or explicitly documented as transitional debt?
- Is the sidebar user control always reachable?
- Are row clicks routed to a detail page, never edit?
- Do dropdowns use the Plane select behavior instead of browser-default UI?
- Are light, semi-dark and dark readable?
- Are focus states and keyboard flows usable?
- Are loading/empty/error/success states clear?
- Does it look intentional at 1280, 1440 and 1920 widths?
- Were existing ops permissions and destructive-action confirmations preserved?

## Avoid

- copying CMS UI skills/rules into Plane
- introducing a large frontend framework just for styling
- raw native-looking selects as the final UI
- page-specific magic widths
- permanent logout button blocks
- mixed Turkish/English hard-coded copy
- clickable rows that unexpectedly edit resources
- hidden destructive actions with no confirmation
- excessive gradients, glassmorphism, neon accents, decorative noise or dashboard-card spam
- styling that makes Plane look like a generic template instead of a focused operations product
