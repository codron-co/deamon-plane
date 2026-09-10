# Scope & Constraints (Plane build)

SoT plan: `docs/plans/2026-08-13-deamon-plane.md`  
ADR özeti: `docs/decisions/README.md`

Bu dosya subagent’lara **göömülecek** küresel kısıtları çıkarır.

---

## Ürün kimliği

| | |
|--|--|
| Ad | **Deamon Plane** |
| Repo | `codron-co/deamon-plane` (bu workspace) |
| Tip | Internal ops control plane (Laravel) |
| Değil | Deamon CMS, multi-tenant SaaS, müşteri paneli |

**HARD:** Bu ürünü Deamon CMS repo köküne koyma; CMS’e multi-tenant ekleme.

---

## ADR kilidi (özet)

| ID | Karar |
|----|--------|
| ADR-1 | Ayrı repo / ayrı Coolify app |
| ADR-2 | Site başına MySQL + Redis |
| ADR-3 | Kanal = git branch + redeploy (`main`/`beta`/`alpha`) |
| ADR-4 | Tema = git via agent; ZIP plane’de yok |
| ADR-5 | Internal ops only v1 |
| ADR-6 | Coolify API + agent; SSH primitif değil |
| ADR-7 | Channel switch volume’ları korur |
| ADR-8 | Mailcow ortak (tek instance) — **infra kararı** |
| ADR-9 | Mailcow plane **v1 kapsam dışı** |

---

## Zorunlu (yap)

- Site stack: Coolify **Docker Compose** build pack + `docker-compose.coolify.yml` → **app + ayrı MySQL + ayrı Redis** (Nixpacks / Dockerfile-only yok)
- **Plane stack:** aynı — bu repo `docker-compose.coolify.yml` (`plane_*` volumes); müşteri siteleriyle DB/Redis paylaşmaz
- Channels allowlist: `main` | `beta` | `alpha`
- Müşteri Coolify env (app): `APP_KEY` + `DEAMON_SITE_NAME` (+ Coolify `SERVICE_*`)
- Tema SoT: Git-only catalog from Themes Git connections (one Plane GitHub App, many user/org installs). Legacy org+`deamon-theme-*` prefix is a migrated default, not a lock. Settings PAT/PEM paste is retired — [theme-git-connections spec](../../../docs/superpowers/specs/2026-09-10-theme-git-connections-design.md).
- Secrets: encrypted cast/vault; log/git yok
- Audit: create, channel, domain, deploy, theme assign/update
- Confirm: channel downgrade, destroy
- Version gate: channel switch öncesi (agent health)
- Plane kendi DB/Redis’i (müşteri ile paylaşmaz)

---

## Yasak (yapma)

| Yasak | Not |
|-------|-----|
| Paylaşımlı MySQL/Redis (müşteri siteleri) | Operasyon kararı; “optimizasyon” kabul edilmez |
| Plane UI’da tema ZIP upload | Acil ZIP = CMS Super Admin `themes.import` (plane replace etmez) |
| Müşteri self-service v1 | v2+ |
| Control plane’i Deamon Modül Mağazası yapmak | Ayrı ürün |
| Plugin marketplace / faturalama / DNS registrar API | Sonraki |
| Creative-render / mobile-apk-builder otomasyonu | İsteğe bağlı v1.x — bu build değil |
| **Mailcow / mailbox provision** | ADR-9 — related-infra; **bu orkestrasyon build’ine dahil etme** |
| CMS agent kodunu plane reposuna yazmak | Task 8/11 → `codron-co/deamon` |
| Coolify SSH/exec ile tema/deploy primitif | API + agent |
| Nixpacks / Dockerfile-only Coolify pack (müşteri veya plane) | Yalnızca Docker Compose + `docker-compose.coolify.yml` |

---

## İki-repo sınır

| deamon-plane | deamon (CMS) |
|--------------|--------------|
| Ops UI, Coolify orchestration, theme catalog state, webhooks | Tek-site CMS + `/internal/control/v1/*` agent |
| `SiteAgentClient` | HMAC middleware + health/themes |
| Import/provision jobs | Theme git installer on instance |

Handoff: imza şeması, env key’leri, JSON contract — kod kopyası değil.

---

## Decision gates (önerilen default)

Plan §18 — Dalga 0’da kilitle:

1. **Coolify project:** tek project + tag/slug  
2. **Theme org:** `GITHUB_ORG=deamon-themes` was the Dalga 0 lock; **superseded 2026-09-10** by Themes Manifest + N connections (legacy prefix kept on migrate).
3. **Agent network:** public HTTPS + HMAC (+ IP allowlist)  
4. **Rollback:** manuel v1  
5. **Theme auto-update:** default **off**  
6. **Agent secret inject:** import sonrası toplu Coolify env patch + redeploy  

Belirsizlik biriktirip tek batch sor; kritik adımı “TBD” bırakma.

---

## Güvenlik (kısa)

Trust: Ops browser → Plane → (Coolify | GitHub | Site agent).  
Müşteri CMS admin plane’e girmez. Detay: `docs/security.md`.
