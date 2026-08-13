# Deamon Plane — Cursor Prompt İndeksi

**Bu repo:** `codron-co/deamon-plane` (Control Plane). Deamon CMS (`codron-co/deamon`) değildir.

## Başlangıç (zorunlu)

| Ne yapıyorsun? | Prompt |
|----------------|--------|
| **Ürünü subagent-driven inşa et** | **[000 — START Plane Orkestrasyon](prompts/000_START-Plane-Orkestrasyon.md)** ← bunu yapıştır |

İnsan okuması için kısa özet: [docs/subagent-orchestration.md](../docs/subagent-orchestration.md)

---

## Klasörler

| Klasör | Ne zaman |
|--------|----------|
| **[prompts/](prompts/)** | Starter + dalga/rol paketleri |
| **[prompts/ortak/](prompts/ortak/)** | Orkestrasyon, dalga brief’leri, sert kapılar |
| **[prompts/referans/](prompts/referans/)** | Scope, file map, acceptance |

---

## Hangi dosya?

| Dosya | Rol |
|-------|-----|
| [000_START-Plane-Orkestrasyon.md](prompts/000_START-Plane-Orkestrasyon.md) | Kullanıcının yapıştırdığı starter |
| [ortak/_Subagent-Plane-Orkestrasyon.md](prompts/ortak/_Subagent-Plane-Orkestrasyon.md) | Dalgalar, roller, merge, hard gates |
| [ortak/_Dalga-0-Discovery-Spike.md](prompts/ortak/_Dalga-0-Discovery-Spike.md) | Faz 0 Coolify API Go/No-Go |
| [ortak/_Dalga-1-Core-Schema-Auth.md](prompts/ortak/_Dalga-1-Core-Schema-Auth.md) | Task 0–1 bootstrap + şema |
| [ortak/_Dalga-2-Coolify-Fleet.md](prompts/ortak/_Dalga-2-Coolify-Fleet.md) | Task 2–7 fleet otomasyonu |
| [ortak/_Dalga-3-Agent-Health.md](prompts/ortak/_Dalga-3-Agent-Health.md) | Task 8–9 CMS agent + plane health |
| [ortak/_Dalga-4-Themes.md](prompts/ortak/_Dalga-4-Themes.md) | Task 10–13 tema ops |
| [ortak/_Dalga-5-Harden-Deploy.md](prompts/ortak/_Dalga-5-Harden-Deploy.md) | Task 14–15 güvenlik + prod |
| [referans/_Scope-Constraints.md](prompts/referans/_Scope-Constraints.md) | ADR + yasaklar + out-of-scope |
| [referans/_File-Map.md](prompts/referans/_File-Map.md) | Plan §11 ownership |
| [referans/_Acceptance-Criteria.md](prompts/referans/_Acceptance-Criteria.md) | Faz 0/A/B/C başarı |

---

## Minimal yapıştırma

```txt
@.cursor/prompts/000_START-Plane-Orkestrasyon.md uygula.

Repo: C:\workspace\deamon\deamon-plane
Branch: alpha (veya mevcut plane branch)
Mod: subagent-driven — Dalga 0 spike ile başla; Laravel’i spike Go olmadan tam kurma.
```

---

## SoT

Uygulama kapsamı: [docs/plans/2026-08-13-deamon-plane.md](../docs/plans/2026-08-13-deamon-plane.md)

**Yasak:** Bu ürünü Deamon CMS repo köküne koymak; paylaşımlı MySQL/Redis; Nixpacks; plane UI’da tema ZIP; müşteri self-serve v1; Mailcow’u bu build’e dahil etmek.

Plane Coolify: Docker Compose + `docker-compose.coolify.yml` — [docs/modules/deployment.md](../docs/modules/deployment.md).
