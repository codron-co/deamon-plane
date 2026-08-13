# Deamon Plane

CodRon **internal ops** paneli: Coolify üzerindeki Deamon müşteri sitelerini (fleet), kanal (`main` / `beta` / `alpha`), domain, deploy durumu ve (sonraki faz) git-bağlı tema mağazasını yönetir.

Bu repo **Deamon CMS değildir.** CMS: [`codron-co/deamon`](https://github.com/codron-co/deamon). Plane ayrı Laravel uygulaması olarak burada gelişir.

## Başlangıç: subagent-driven build

Ürünü orkestratör + paralel subagent’larla inşa etmek için starter prompt’u yapıştır:

**→ [.cursor/prompts/000_START-Plane-Orkestrasyon.md](.cursor/prompts/000_START-Plane-Orkestrasyon.md)**

İndeks: [.cursor/README.md](.cursor/README.md) · kısa özet: [docs/subagent-orchestration.md](docs/subagent-orchestration.md)

Dalga 0 = Coolify API spike (Go/No-Go). Spike olmadan Laravel full scaffold yok.

**Coolify kurulumu:** Build pack **Docker Compose** → `docker-compose.coolify.yml` (app + kendi MySQL + Redis). Adımlar: [docs/modules/deployment.md](docs/modules/deployment.md). İlk yeşil deploy Laravel (Dalga 1) sonrası.

## Dokümantasyon

| Dosya | İçerik |
|-------|--------|
| [docs/README.md](docs/README.md) | İndeks |
| [docs/plans/2026-08-13-deamon-plane.md](docs/plans/2026-08-13-deamon-plane.md) | **Tam uygulama planı** (mimari, şema, task’lar) |
| [docs/architecture.md](docs/architecture.md) | Kısa mimari özet |
| [docs/subagent-orchestration.md](docs/subagent-orchestration.md) | Subagent dalgaları (pointer) |
| [docs/decisions/README.md](docs/decisions/README.md) | ADR / karar kaydı |
| [docs/security.md](docs/security.md) | Güvenlik modeli |
| [docs/modules/deployment.md](docs/modules/deployment.md) | Coolify Docker Compose (Plane) |

## Durum

- Plan, kararlar ve **orkestrasyon prompt paketi**: hazır
- Dalga 0: OpenAPI map + spike script + **Coolify Compose sözleşmesi** (`docker-compose.coolify.yml`) hazır; canlı Go/No-Go token bekliyor — [spike notes](docs/plans/2026-08-13-coolify-spike-notes.md)
- Laravel uygulama iskeleti: henüz yok (Dalga 1 / Task 0 — Go/Hybrid sonrası)
- Kullanıcı modeli v1: yalnızca internal ops (müşteri self-service yok)

## İlişkili sistemler

- **Coolify** — Plane ve her müşteri sitesi ayrı Compose app (`docker-compose.coolify.yml`; paylaşımlı DB yok; Nixpacks yok)
- **Deamon CMS agent** — imzalı `/internal/control/v1/*` (CMS reposunda; ayrı track)
- **Tema org** — `deamon-themes` / `deamon-theme-{id}` (git; ZIP yok)
- **Mailcow** — ortak mail sunucusu (plane v1 build dışı; bkz. related-infra)

## Geliştirme

1. Starter: [.cursor/prompts/000_START-Plane-Orkestrasyon.md](.cursor/prompts/000_START-Plane-Orkestrasyon.md)
2. SoT: [docs/plans/2026-08-13-deamon-plane.md](docs/plans/2026-08-13-deamon-plane.md)
