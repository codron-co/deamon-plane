# Dalga 2 — Coolify Fleet (Task 2–7)

**Faz:** A (fleet MVP)  
**Önkoşul:** Dalga 1 yeşil; Dalga 0 API imzaları  
**Task’lar:** 2 client · 3 Site CRUD · 4 provision · 5 channel · 6 webhooks/dashboard · 7 import

---

## Amaç

Panelden site oluştur → Coolify stack → domain; kanal geçişi; deploy görünürlüğü; mevcut app import (dry-run).

---

## Paralel plan

```text
Phase 2a (paralel):
  COOLIFY-CLIENT (Task 2)
  UI-SITES (Task 3)     ← Coolify çağrısı yok; draft CRUD

Phase 2b (Client merge sonrası):
  PROVISION (Task 4)

Phase 2c (Provision merge sonrası, paralel):
  CHANNEL (Task 5)
  WEBHOOKS-DEPLOY (Task 6)
  IMPORT (Task 7)
```

Channel, SiteController’a action eklerse UI-SITES ile **ardışık** veya ana agent patch — aynı anda `SiteController` düzenletme.

---

## Rol brief’leri

### COOLIFY-CLIENT (Task 2)

- Spike imzalarına kilitle: `listApps`, `createComposeApp`, `updateEnvs`, `setDomains`, `updateBranch`, `deploy`, `getDeployment`, list servers/projects
- `CoolifyApiException`; Http::fake unit tests
- Settings: encrypted token + Test connection
- Hybrid: eksik method → `UnsupportedOperation` + manual checklist DTO

**Owns:** `app/Services/Coolify/*`, Coolify settings UI partial, unit tests

### UI-SITES (Task 3)

- List toolbar (search, channel, status)
- Create/edit: slug, name, domain, channel, server
- Policies: viewer read-only
- Feature tests CRUD (draft; provision butonu stub OK)

**Owns:** Ops Site controllers/requests/views (CRUD), policies

### PROVISION (Task 4)

- Generate `APP_KEY` + `agent_secret` (encrypted)
- Job: create app → env → domain → deploy
- Poll/webhook → `active`/`error`
- Audit entries
- Feature: happy + failure (Coolify fake)
- **Constraint:** compose = app+mysql+redis per site; shared stack önerme

**Owns:** `SiteProvisioner`, provision/poll jobs

### CHANNEL (Task 5)

- Policy: downgrade confirm; upgrade version gate (agent stub OK); force = super_admin
- Block if `deploying`
- Coolify updateBranch + deploy
- Volume koruma notunu UI/runbook’a bağla
- Tests

**Owns:** `ChannelSwitcher`, channel UI/actions/tests

### WEBHOOKS-DEPLOY (Task 6)

- Coolify → plane webhook + secret verify
- Map status → `deployments`
- Site detail Deployments tab
- Fleet dashboard KPI (≤5–6 metrik)
- Poll fallback 15–30s job (yoksa)

**Owns:** Coolify webhook stack, dashboard, deployments views

### IMPORT (Task 7)

- `ops:import-coolify-apps` dry-run zorunlu
- Upsert by coolify uuid / domain
- Slug heuristic dokümante
- Agent secret sonradan (Dalga 3/5 runbook) — import’ta secret uydurma

**Owns:** artisan command + tests + runbook import bölümü

---

## Hard gates (bu dalga)

- Paylaşımlı MySQL/Redis **yok**
- Channel allowlist dışı branch UI’dan seçilemez
- Secret log’a yazılmaz (redaction erken ekle)
- Production Coolify’de rastgele create yok — testler fake; canlı deneme staging + kullanıcı onayı

---

## Verification gate (Faz A kısmi)

```
[ ] CoolifyClient unit yeşil
[ ] Provision feature happy/fail yeşil
[ ] Channel switch tests yeşil
[ ] Webhook invalid signature 4xx
[ ] Import dry-run table output
[ ] Dashboard KPI render
[ ] Hybrid ise manual checklist UI’da görünür
```

Acceptance kalanı (canlı staging smoke) Dalga 5’te tamamlanabilir; fake ile MVP kanıtı burada.

---

## Rapor

```txt
## Dalga 2 raporu — Deamon Plane
Subagents: CLIENT / UI / PROVISION / CHANNEL / WEBHOOKS / IMPORT — durum
API mode: full | hybrid
Verify: <tests>
Sonraki: Dalga 3 (agent health; CMS track paralel)
```
