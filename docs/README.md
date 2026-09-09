# Deamon Plane — Dokümantasyon

## Başlangıç

- **[Subagent starter prompt](../.cursor/prompts/000_START-Plane-Orkestrasyon.md)** — yapıştır; orkestratör + dalgalar
- [Subagent orchestration (özet)](subagent-orchestration.md) · [.cursor/README.md](../.cursor/README.md)

## Ana plan

- **[Implementation plan](plans/2026-08-13-deamon-plane.md)** — fleet, Coolify, agent, tema mağazası, task checklist
- [Coolify spike notes (Dalga 0)](plans/2026-08-13-coolify-spike-notes.md) — API map; **Go**
- [Progress ledger](plans/progress-ledger.md)

## Referans

- [Architecture](architecture.md)
- [Decisions (ADR)](decisions/README.md)
- [Security](security.md)
- [Related infra (Mailcow vb.)](related-infra.md) — Mailcow plane v1 **out of scope**
- [Ops Sites CRUD](modules/ops-sites.md) — draft desired state; no Coolify calls
- [Deployment (Coolify Compose)](modules/deployment.md)
- [Coolify HTTP adapter](modules/coolify-client.md)
- [Runbooks](runbooks/README.md) — deploy-plane / provision / channel-switch / theme-rollout

## Sınır

| Bu repo | Deamon CMS (`codron-co/deamon`) |
|---------|----------------------------------|
| Ops panel, Coolify orkestrasyon, tema katalog state | Tek-site CMS, site agent endpoint’leri |
| Multi-tenant SaaS değil | Multi-tenant SaaS değil |
| Subagent build: `.cursor/prompts/` | Agent Task 8/11 ayrı track |
