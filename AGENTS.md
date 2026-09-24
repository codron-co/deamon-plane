# Deamon Plane — Agent entry

This repo is **deamon-plane** (control plane): ops panel, Coolify fleet, theme catalog state, signed site-agent calls.

It is **not** the CMS (`codron-co/deamon`). Do not treat this chat as a Deamon CMS session.

## Do not load

- CMS `.cursor/rules/deamon-project.mdc`
- CMS `deamon-mvp`, theme Docker hubs, theme subagent prompts
- Laravel / OWASP / Superpowers / skill `REFERENCE.md` dumps
- CMS UI skill packs (UI UX Pro Max, deamon-ui, etc.)
- Nested files named `AGENTS.md` — this repo has one router: this file

Plane chats stay small. Read only what the current Plane task needs.

## Read first (this repo)

- [docs/README.md](docs/README.md) — documentation index
- [docs/architecture.md](docs/architecture.md) — Plane vs CMS boundary
- [docs/subagent-orchestration.md](docs/subagent-orchestration.md) — Plane Task waves (not CMS theme waves)
- [.cursor/README.md](.cursor/README.md) — Plane prompt index
- [.cursor/prompts/000_START-Plane-Orkestrasyon.md](.cursor/prompts/000_START-Plane-Orkestrasyon.md) — orchestrator starter

## Task-specific local skills

- Plane UI/UX, Blade/CSS/vanilla JS, layout, tables, forms, themes, i18n and interaction primitives: [.agents/skills/plane-ui-ux/SKILL.md](.agents/skills/plane-ui-ux/SKILL.md)

This Plane-specific skill is the only UI skill routed from this repo. Do not copy CMS `.agents/skills/**` into Plane.

## Pointers (one line; do not paste specs)

- Plan / ledger: [docs/plans/2026-08-13-deamon-plane.md](docs/plans/2026-08-13-deamon-plane.md), [docs/plans/progress-ledger.md](docs/plans/progress-ledger.md)
- UI/UX remediation: [docs/plans/2026-09-10-ui-ux-remediation.md](docs/plans/2026-09-10-ui-ux-remediation.md)
- Scope: [.cursor/prompts/referans/_Scope-Constraints.md](.cursor/prompts/referans/_Scope-Constraints.md)
- Security: [docs/security.md](docs/security.md)
- Deploy: [docs/modules/deployment.md](docs/modules/deployment.md), [docs/runbooks/README.md](docs/runbooks/README.md)
- Sites / Coolify: [docs/modules/ops-sites.md](docs/modules/ops-sites.md), [docs/modules/coolify-client.md](docs/modules/coolify-client.md)
- Site agent: [docs/modules/agent-client.md](docs/modules/agent-client.md)
- Themes (catalog + assign, not CMS theme authoring): [docs/modules/theme-catalog.md](docs/modules/theme-catalog.md), [docs/modules/theme-agent-client.md](docs/modules/theme-agent-client.md)

## Site configuration rule (ADR-12)

Customer site env = bootstrap only (`APP_KEY`, `DB_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `CONTROL_PLANE_AGENT_SECRET`, `CONTROL_PLANE_HOST_ALLOWLIST`, `DEAMON_CHANNEL`). Env prune never deletes these. Every other per-site/agency setting is pushed over a signed agent endpoint and persisted in the site DB; the security baseline is fixed in CMS code. **Do not add catalog keys** and do not map channel → `APP_ENV` (live sites are always `production`). See [docs/decisions/adr-12-site-config-from-plane.md](docs/decisions/adr-12-site-config-from-plane.md).

## Hard limits

- Multi-tenant SaaS / marketplace / customer theme ZIP upload: out of scope
- Mailcow and other related-infra items: see [docs/related-infra.md](docs/related-infra.md) (plane v1 out of scope unless a task says otherwise)
- Never invent CMS modules, theme stacks, or admin Blade/Inertia work here
- Do not copy CMS `AGENTS.md` or CMS `.agents/skills/**` into this repo
