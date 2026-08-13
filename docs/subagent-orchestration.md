# Subagent orchestration (Plane)

Deamon Plane, Deamon CMS tema işlerindeki gibi **subagent-driven** inşa edilir: ana agent orkestre eder; iş dalgalara ve paralel Task/subagent’lara bölünür.

## Başlangıç

**Yapıştırılacak starter prompt:**

[.cursor/prompts/000_START-Plane-Orkestrasyon.md](../.cursor/prompts/000_START-Plane-Orkestrasyon.md)

İndeks: [.cursor/README.md](../.cursor/README.md)

## Ne okunur?

| Dosya | İçerik |
|-------|--------|
| [ortak/_Subagent-Plane-Orkestrasyon.md](../.cursor/prompts/ortak/_Subagent-Plane-Orkestrasyon.md) | Dalgalar, roller, merge, kapılar |
| [ortak/_Dalga-0-…md](../.cursor/prompts/ortak/_Dalga-0-Discovery-Spike.md) … [_Dalga-5_](../.cursor/prompts/ortak/_Dalga-5-Harden-Deploy.md) | Dalga brief’leri |
| [referans/_Scope-Constraints.md](../.cursor/prompts/referans/_Scope-Constraints.md) | ADR + yasaklar (Mailcow out of scope) |
| [plans/2026-08-13-deamon-plane.md](plans/2026-08-13-deamon-plane.md) | SoT uygulama planı |

## Dalga özeti

0 spike (Go/No-Go) → 1 bootstrap+şema → 2 Coolify fleet → 3 CMS agent health (ayrı repo) + plane client → 4 temalar → 5 güvenlik + plane prod deploy.

Laravel uygulaması henüz yoksa **Dalga 0 ile başla**; spike Go/Hybrid olmadan full scaffold’a geçme.

Coolify: Plane **Docker Compose** (`docker-compose.coolify.yml`). Nixpacks yok. Ayrıntı: [modules/deployment.md](modules/deployment.md).
