# Deamon Plane — Dokümantasyon

## Başlangıç

- **[Subagent starter prompt](../.cursor/prompts/000_START-Plane-Orkestrasyon.md)** — yapıştır; orkestratör + dalgalar
- [Subagent orchestration (özet)](subagent-orchestration.md) · [.cursor/README.md](../.cursor/README.md)

## Ana plan

- **[Implementation plan](plans/2026-08-13-deamon-plane.md)** — fleet, Coolify, agent, tema mağazası, task checklist
- [Coolify spike notes (Dalga 0)](plans/2026-08-13-coolify-spike-notes.md) — API map; **Go**
- [Progress ledger](plans/progress-ledger.md)
- [Plane inceleme ve öneri raporu (2026-09-14)](plans/2026-09-14-plane-review-and-proposals.md) — envanter, boşluklar, otomasyon / özellik / güvenlik önerileri, öncelik dalgaları
- [Theme Git connections spec](superpowers/specs/2026-09-10-theme-git-connections-design.md) — one Plane GitHub App, many Themes installations (Settings paste retired)
- [Theme Git connections plan](plans/2026-09-10-theme-git-connections.md) — code-worker todos; CMS **1.2.7** git `repo` allowlist already landed in `deamon`
- [Site admin management spec](superpowers/specs/2026-09-11-site-admin-management-design.md) — HMAC CMS admins from site detail

## Referans

- [Architecture](architecture.md)
- [Decisions (ADR)](decisions/README.md) — [ADR-12](decisions/adr-12-site-config-from-plane.md): customer site env = 6 bootstrap keys; every other setting via signed agent → site DB; security baseline in CMS code; `APP_ENV` always `production` · [ADR-10](decisions/adr-10-health-app-filter-verdict.md): `health` / `app` filters are SQL over a persisted verdict · [ADR-11](decisions/adr-11-dom-test-harness.md): skip Playwright; `node --test tests/js/*.js` covers toolbar URL, poll backoff, bulk interpolate, `isTyping` / `confirmOpen`, `pageIsHidden` / `shouldSchedulePoll`, Settings `textMatches`, list `fetch` / `replaceState` (no jsdom — duck-typed `{ hidden: true }` and `{ origin, pathname }`)
- [Security](security.md)
- [Related infra (Mailcow vb.)](related-infra.md) — Mailcow **coming soon**; Hostinger mail **in scope**
- [Ops Sites CRUD](modules/ops-sites.md) — draft desired state + provision POST + channel switch
- [Async list regions](modules/ops-list-async.md) — search / filter / sort / paging / column prefs without a page reload
- [Activity](modules/ops-activity.md) — jobs + deploys + audit rows; fleet history the widget is not
- [Mail servers](modules/mail-servers.md) — Hostinger token in Plane; CMS HMAC proxy
- [Software mail](modules/platform-mail.md) — Deamon product SMTP + notification toggles (global + per-site)
- [Deployment (Coolify Compose)](modules/deployment.md)
- [Coolify HTTP adapter](modules/coolify-client.md) — N connections, allowlists, site dropdowns
- [Coolify deploy webhooks](modules/coolify-webhooks.md) — HMAC or query token + deployments UI + fleet KPI
- [Site agent client](modules/agent-client.md) — HMAC health poll + version gate + unhealthy KPI
- [Site admins](modules/site-admins.md) — CMS admin list/create/reset/deactivate/delete via agent
- [Theme catalog](modules/theme-catalog.md) — Git connections under Themes (Manifest + all/selected; no ZIP)
- [Theme agent client](modules/theme-agent-client.md) — assign via CMS `X-Deamon-*` HMAC
- [GitHub theme webhooks](modules/github-webhooks.md) — distinct from Coolify deploy webhooks
- [Cloudflare HTTP adapter](modules/cloudflare-client.md) — token, parent-zone A attach, preview wildcard, DNS template
- [Site detail UI reference](prototypes/site-detail-reference.html) — golden Site detail layout; identity mark loads the live domain favicon
- [Runbooks](runbooks/README.md) — deploy-plane / provision / import / channel-switch / agent-secret-inject / theme-rollout / token-rotation

## Sınır

| Bu repo | Deamon CMS (`codron-co/deamon`) |
|---------|----------------------------------|
| Ops panel, Coolify orkestrasyon, tema katalog state, Hostinger mail proxy | Tek-site CMS, site agent endpoint’leri |
| Multi-tenant SaaS değil | Multi-tenant SaaS değil |
| Subagent build: `.cursor/prompts/` | Agent Task 8/11 ayrı track |
