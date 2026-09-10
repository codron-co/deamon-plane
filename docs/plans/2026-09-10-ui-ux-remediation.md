# Deamon Plane UI/UX Remediation

Date: 2026-09-10

Target: `codron-co/deamon-plane` / Plane ops panel.

This plan is based on the current Blade/CSS UI and the screenshots of Fleet, Sites, Site edit and Coolify screens. It is intentionally Plane-specific and does not import the CMS UI skill packs.

## Problems confirmed

### User-reported

1. Sidebar account area can disappear below the viewport on long pages.
2. User identity and a permanent Sign out button are always exposed instead of a compact account menu.
3. Page width is inconsistent: some screens are narrow islands while others use more of the main canvas.
4. User-facing copy is mixed Turkish/English; i18n is missing.
5. Only dark appearance is available; Light / Semi-dark / Dark are required.
6. There is no proper personal account page for email, password and avatar/photo.
7. Resource tables have inconsistent navigation. Row click must always open a detail page; edit must be explicit.
8. `/sites` says `Channel` even though the displayed value is the deployed repo branch. It should say `Repo branch`, with the app/Deamon version shown as a neighboring badge.
9. `<select>` controls expose browser-default dropdown UI and should use a Plane-owned accessible select primitive.

### Additional UX problems found

10. The desktop shell is page-height-driven rather than viewport-stable. The sidebar needs a fixed viewport relationship, with only the nav region scrolling.
11. `ops-content` currently uses a small global max width and `ops-content-wide` uses another value. This creates arbitrary canvas differences instead of a deliberate content-width system.
12. The current Site route conflates show/detail and edit: `/sites/{site}` maps to the edit controller/view. This makes it impossible to establish the rule that tables always lead to detail.
13. Detail/edit screens mix summary, configuration, deployment actions, theme actions, agent actions and destructive actions into one long form flow. A dedicated overview/detail page should become the operational landing page; editing should be a secondary action.
14. The global topbar shows `main · beta · alpha` as static technical text. It consumes prime space without explaining whether it is the current branch, an allowlist or a switcher.
15. Status colors are useful but several warning/success surfaces are hard-coded dark colors. They will not scale cleanly to Light/Semi-dark without tokenization.
16. Forms are structurally consistent but too much content is placed in one uninterrupted vertical column on complex Site screens. Configuration should be grouped into clear sections.
17. Page-level actions and back navigation are inconsistent. Nested pages need a predictable breadcrumb/back hierarchy and action cluster.
18. List filtering lacks a shared filter primitive and relies on raw selects. Search/filter/clear behavior should be standardized across all list views.
19. Empty states exist, but loading/pending/stale states are not represented consistently. This matters for provisioning, deploys, health checks, catalog sync and Coolify calls.
20. There is no documented responsive acceptance baseline. The UI should be tested at 1280/1440/1920 and long-page conditions, not only one desktop size.
21. Account preferences, system settings and integration settings are conceptually mixed. `My account / Preferences` should be separated from administrator-level Plane/Coolify/GitHub settings.
22. Repeated UI primitives are implemented as page-specific markup/classes instead of a defined Plane component contract. This increases visual drift as the ops panel grows.

## UX direction

Plane should feel like a focused infrastructure/operations product: compact, calm, high-signal and predictable. It should not imitate a generic SaaS dashboard with excessive cards, gradients or decoration.

Primary principles:

- data first
- explicit state
- explicit actions
- consistent navigation
- low surprise
- keyboard usable
- progressive enhancement
- clear separation between overview and edit

## Phase 1 — UI foundation

Priority: immediate.

### Shell and spacing

- Make the desktop sidebar sticky/viewport-height (`100dvh`).
- Give the navigation region its own overflow behavior.
- Keep the account menu anchored at the bottom of the viewport.
- Replace arbitrary 960/1120 page max widths with one standard workspace width.
- Keep form controls internally constrained when readability benefits from it; do not constrain the entire page shell.

### Account menu

Replace the current user + permanent logout block with one clickable user control.

Menu contents:

- user name + email + role
- Account/Profile
- Preferences
- Appearance: Light / Semi-dark / Dark
- Language
- Sign out

Dropdown behavior:

- outside click closes
- Escape closes
- keyboard focus is visible
- menu never renders offscreen on long pages

### Design tokens and appearance

Extend the existing CSS variables into a complete theme token set.

Implement:

- Dark
- Semi-dark
- Light

Persist the preference. Avoid theme flash by applying the selected mode before normal stylesheet paint where practical.

### Plane custom select

Create one reusable progressive-enhancement select primitive for all standard single-select controls.

It must continue to submit the underlying native `<select>` value and must remain compatible with `ops-coolify-form.js`, including dynamically replaced `<option>` lists.

### Sites list semantics

- Replace visual copy `Channel` with `Repo branch`.
- Keep existing backend field/query naming transitional if changing storage semantics is unnecessary.
- Render `reportedDeamonVersion()` beside the branch as a version badge; show a deliberate unknown state if health has not reported a version yet.

## Phase 2 — Navigation and resource detail contract

Priority: high.

### Standard resource routes

For every table-backed resource use the conventional contract:

- index
- show/detail
- create (when relevant)
- edit (when relevant)

Tables target show/detail only.

For Sites specifically:

- `GET /sites/{site}` => show
- `GET /sites/{site}/edit` => edit

The current edit route should no longer serve as the implicit detail page.

### Clickable row primitive

Implement generic row navigation:

- mouse click on non-interactive row region => show route
- keyboard Enter => show route
- action links/buttons/forms do not bubble into row navigation
- clear hover and focus styles

### Detail-page information architecture

Site overview should surface before any edit form:

- status / health
- domain
- repo branch + version
- git repository
- Coolify connection/application
- active theme
- last health check
- latest deployment
- recent activity/deployments

Primary actions belong in the page header or an explicit action area:

- Edit
- Deploy/Provision as appropriate
- Check health
- branch switch when allowed
- destructive actions isolated in danger zone

## Phase 3 — i18n

Priority: high, before adding much more UI copy.

Initial locales:

- `tr`
- `en`

Implementation:

- Laravel translation files grouped by ops domain.
- locale middleware/session preference.
- add persisted user locale when account preferences are introduced.
- `<html lang>` bound to active locale.
- locale switch in the user menu/preferences.
- translate navigation, page titles, tables, buttons, empty states, confirmations, validation-facing copy and status presentation labels.

Do not translate database enum values in storage; translate their presentation labels.

## Phase 4 — My Account and Preferences

Priority: medium/high.

Separate personal settings from system/integration settings.

### My Account

- display name
- email
- avatar/photo
- password change
- password confirmation and normal Fortify protections

### Preferences

- language
- appearance
- optional density preference later if useful

### System Settings

Keep Plane/Coolify/GitHub operational configuration under admin/system settings with authorization gates. Do not mix these with a normal user's profile.

## Phase 5 — Component consistency

Create/reuse Plane primitives for:

- app shell
- topbar
- breadcrumbs
- user menu/popover
- buttons
- fields
- custom select
- check/radio
- status/branch/version badges
- table/clickable row
- filter toolbar
- KPI card
- confirmation modal
- flash/toast
- empty state
- pagination
- loading/skeleton state

Avoid new one-off CSS for repeated patterns.

## Specific screen recommendations

### Fleet

- Use more of the available horizontal workspace.
- Keep KPI cards in a balanced responsive grid.
- The warning/attention panel should not occupy only a narrow left strip on wide monitors.
- Add clear links from problem rows to Site detail.
- Consider secondary operational sections (recent deploy failures, unhealthy sites, pending actions) once data exists; do not fill space with decorative cards.

### Sites

- Search + branch + status filters in one shared toolbar.
- Custom Plane selects for filters.
- Whole row opens Site detail.
- Branch + version appear together.
- Identity marks (if present) use the same domain-favicon primitive as Site detail.
- Keep explicit row overflow/actions only for secondary actions.
- Avoid putting destructive Delete directly in the dominant scan path if an overflow/detail danger zone can handle it safely.

### Site detail

- Become the operational overview.
- Do not start with a long editable form.
- Put branch/version/status/health/domain above the fold.
- The identity mark loads the site favicon from the primary domain (`https://{host}/favicon.ico`, then `/apple-touch-icon.png`). First letter is the fallback. No third-party icon CDN.
- Group Agent, Themes, Deployments and Coolify information into clear sections.
- Keep Edit explicit.

### Site edit/create

- Split form into logical sections: Identity, Placement/Coolify, Git/branch, Advanced, Notes.
- Keep advanced UUID entry collapsed and clearly hazardous.
- Use custom selects everywhere.
- Keep save action predictable; operational actions should not be mixed into the configuration form.

### Coolify

- Use the standard page width.
- Make connection rows fully navigable to detail.
- Keep Add connection as the primary page action.
- On detail, separate credentials/configuration, inventory/status, defaults and destructive actions.

### Themes

- Table/card rows should route to theme detail.
- Show identifier, repo, default ref/version, visibility and sync state consistently.
- Make installation/assignment actions contextual rather than mixing them into unrelated Site edit fields.

## Implementation cautions

- Preserve policy/authorization checks.
- Do not leak secrets into UI, logs, HTML attributes or translated strings.
- Do not break Coolify dynamic option population when enhancing selects.
- Do not rename internal `channel` fields solely for cosmetic copy unless the domain model is intentionally being migrated.
- Do not turn this remediation into a frontend framework rewrite.
- Keep all destructive actions confirmed.

## Test/acceptance matrix

For every UI foundation change validate:

- authenticated Super Admin / Operator / Viewer where behavior differs
- 1280, 1440 and 1920 desktop widths
- long Site page (>2 viewport heights)
- 768 px responsive layout
- keyboard: Tab, Shift+Tab, Enter, Space, Escape, Arrow keys for custom controls
- Light / Semi-dark / Dark contrast
- native form submission still carries select values
- dynamic Coolify selects update both native value and custom UI
- table actions do not accidentally trigger row navigation
- show routes obey policies
- no secrets are exposed

## Done criteria

The remediation is considered complete when:

- sidebar account control never disappears on long desktop pages
- logout lives inside the user menu
- all primary pages follow the same width/grid system
- Light/Semi-dark/Dark are supported
- TR/EN can be switched and user-facing copy is translation-backed
- account profile/password/avatar/preferences exist
- every table row opens a show/detail page
- Sites displays Repo branch + version badge
- raw browser-default single-select UX is eliminated from normal Plane screens
- the reusable rules in `.agents/skills/plane-ui-ux/SKILL.md` are followed by future Plane UI tasks
