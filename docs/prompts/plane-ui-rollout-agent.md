# Deamon Plane UI rollout agent prompt

You are upgrading the UI/UX of `codron-co/deamon-plane`. Work from the current feature branch after the Site detail reference implementation has landed.

## Required context

Read, in order:

1. `AGENTS.md`
2. `.agents/skills/plane-ui-ux/SKILL.md`
3. `docs/plans/2026-09-10-ui-ux-remediation.md`
4. `resources/views/ops/sites/show.blade.php`
5. `public/css/ops.css`
6. `public/css/ops-ui.css`
7. the controller, Blade views, tests, and translation files for the screen you are changing

The new Site detail page is the golden reference for information hierarchy, spacing, density, responsive behavior, status presentation, section headings, operational actions, and technical-detail disclosure. Reuse its shared visual language; do not mechanically copy Site-specific markup into unrelated screens.

## Objective

Bring Fleet, Sites, Coolify, Themes, Settings, Account, and all resource detail/form screens into one coherent Plane operations interface. Optimize for scanability, operational confidence, predictable navigation, and safe actions. Preserve all backend behavior, authorization, routes, request payloads, and progressive enhancement.

## Non-negotiable rules

- Stay with Laravel Blade, shared CSS, and small vanilla JavaScript. Do not add React, Vue, Tailwind, a template dashboard, or a frontend build pipeline.
- Use translation files for every new user-facing string in Turkish and English.
- Use the standard Plane shell and content-width modes. Do not add arbitrary page-level `max-width` islands.
- Reuse or extract shared primitives before adding page-specific variants.
- Tables open the show/detail route from a non-interactive row click and Enter. Nested controls must not trigger row navigation.
- Detail pages answer: what is it, is it healthy, what is live, what is connected, what happened recently, and what action is next.
- Edit forms are separate from operational detail pages.
- Hide UUIDs and low-frequency infrastructure identifiers behind a clear technical-details disclosure unless they are required for the current task.
- Destructive actions live in a visibly separate danger zone and keep their confirmation behavior.
- Do not use gradients, glassmorphism, neon colors, oversized cards, decorative charts, or generic SaaS dashboard filler.
- Validate Light, Semi-dark, and Dark modes.
- Validate keyboard access, focus states, reduced motion, long pages, and responsive layouts.

## Execution order

Work screen by screen in this order:

1. Fleet dashboard
2. Sites index
3. Site create/edit
4. Coolify index/detail/inventory detail
5. Themes index/detail
6. Deployment detail
7. Settings and Cloudflare
8. Account and preferences
9. Login and remaining empty/error states

For each screen:

1. Audit the current information hierarchy and identify global versus local problems.
2. Write a short implementation note in the task log before editing.
3. Reuse the Site detail tokens and primitives where appropriate.
4. Implement the smallest coherent screen-level change.
5. Add or update feature tests for critical content, routes, permissions, and form behavior.
6. Run focused tests, then the complete test suite.
7. Visually verify at 1280, 1440, 1920, 768, and narrow mobile widths in all three appearance modes.
8. Commit that screen as one reviewable commit. Do not mix unrelated backend refactors.

## Required shared primitives

Consolidate these only when at least two screens need them:

- resource hero/header
- sticky section navigation
- summary metric row
- section heading and kicker
- operational two-column layout
- fact list
- technical-details disclosure
- status/branch/version badges
- action cluster
- empty/error/loading states
- danger zone
- responsive table wrapper

Use semantic `ops-*` names for genuinely global primitives. Keep `site-*` names only for Site-specific composition.

## Acceptance criteria

The rollout is complete only when:

- all authenticated screens share the same shell, spacing rhythm, typography, controls, and page-width system;
- the primary status and next action are visible without searching through a long form;
- no user-facing screen mixes hard-coded Turkish and English;
- every table-backed resource has a proper detail route and row navigation targets it;
- forms have consistent grouping, help text, validation, and action placement;
- all destructive operations remain authorized, confirmed, and separated;
- no existing backend capability or test coverage is removed to simplify the UI;
- focused and full automated tests pass;
- visual QA artifacts show acceptable results at the required viewport widths and appearance modes.

## Reporting format

After every screen, report:

- files changed;
- global primitives added or reused;
- behavior intentionally preserved;
- tests run and results;
- visual widths/themes checked;
- remaining known debt;
- commit SHA.

Stop and report instead of guessing if a UI change would require changing authorization, deployment semantics, Coolify mutation behavior, stored data, or a destructive workflow.
