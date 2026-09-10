# Deamon Plane — Dokümantasyon

## Başlangıç

- **[Subagent starter prompt](../.cursor/prompts/000_START-Plane-Orkestrasyon.md)** — yapıştır; orkestratör + dalgalar
- [Subagent orchestration (özet)](subagent-orchestration.md) · [.cursor/README.md](../.cursor/README.md)

## Ana plan

- **[Implementation plan](plans/2026-08-13-deamon-plane.md)** — fleet, Coolify, agent, tema mağazası, task checklist
- [Coolify spike notes (Dalga 0)](plans/2026-08-13-coolify-spike-notes.md) — API map; **Go**
- [Progress ledger](plans/progress-ledger.md)
- [Theme Git connections spec](superpowers/specs/2026-09-10-theme-git-connections-design.md) — one Plane GitHub App, many Themes installations (Settings paste retired)
- [Theme Git connections plan](plans/2026-09-10-theme-git-connections.md) — code-worker todos; CMS **1.2.7** git `repo` allowlist already landed in `deamon`

## Referans

- [Architecture](architecture.md)
- [Decisions (ADR)](decisions/README.md)
- [Security](security.md)
- [Related infra (Mailcow vb.)](related-infra.md) — Mailcow **coming soon**; Hostinger mail **in scope**
- [Ops Sites CRUD](modules/ops-sites.md) — draft desired state + provision POST + channel switch
- [Mail servers](modules/mail-servers.md) — Hostinger token in Plane; CMS HMAC proxy
- [Deployment (Coolify Compose)](modules/deployment.md)
- [Coolify HTTP adapter](modules/coolify-client.md) — N connections, allowlists, site dropdowns
- [Coolify deploy webhooks](modules/coolify-webhooks.md) — HMAC or query token + deployments UI + fleet KPI
- [Site agent client](modules/agent-client.md) — HMAC health poll + version gate + unhealthy KPI
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
