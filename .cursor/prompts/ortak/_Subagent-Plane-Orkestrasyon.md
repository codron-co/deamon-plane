# Subagent-Driven Plane Orkestrasyonu

Deamon Plane **tek agent ile uçtan uca yazılmaz.** Ana agent koordine eder; iş **Task / subagent** dalgalarına bölünür.

Cursor: `Task` tool (`generalPurpose` / `explore` / `shell` / gerektiğinde `best-of-n-runner`). Her subagent’a HARD RULES + ownership gömülür.

**SoT:** `docs/plans/2026-08-13-deamon-plane.md`  
**Starter:** `../000_START-Plane-Orkestrasyon.md`  
**Kapsam:** `../referans/_Scope-Constraints.md`  
**Dosya sahipliği:** `../referans/_File-Map.md`  
**Kabul:** `../referans/_Acceptance-Criteria.md`

**Yasak:** “Hepsini ben yaptım” tek agent koşusu; Dalga 0 Go olmadan fleet; CMS kodunu plane reposuna yazmak; paylaşımlı MySQL/Redis; plane’de tema ZIP; müşteri self-serve; Mailcow build’i.

---

## Ortak kurallar

1. **Dalga 0 yalnızca spike + karar kilidi** — full Laravel scaffold Dalga 1.
2. Spike **Go** veya **Hybrid** olmadan Dalga 1+ başlamaz (No-Go → kullanıcıya rapor, dur).
3. Paralel subagent’lar **çakışmayan dosya alanları** alır (`_File-Map.md`).
4. Her subagent bitince ana agent birleştirir; `git status` / diff / test.
5. Dalga sonu: verification gate + rapor formatı (starter’daki şablon).
6. Deamon CMS Task 8/11 → **ayrı repo track**; plane’de client + handoff notu.
7. Commit yalnızca kullanıcı isterse (orkestratör varsayılanı: commit etme / kullanıcı kuralına uy).

---

## Dalga haritası ↔ plan Task 0–15

| Dalga | Faz | Task’lar | Mod |
|-------|-----|----------|-----|
| **0** | Faz 0 | Spike (plan §5 + §12) + §18 gates | Ana agent (+ shell); kod minimal PoC/script OK |
| **1** | Faz A çekirdek | **0** bootstrap, **1** schema/auth modelleri | 0 → 1 sıralı; alt parçalar paralel |
| **2** | Faz A fleet | **2** Coolify client, **3** Site CRUD, **4** provision, **5** channel, **6** webhooks/UI, **7** import | Client önce; sonra paralel |
| **3** | Faz A agent | **8** CMS health agent, **9** plane health client | İki repo paralel → merge entegrasyon |
| **4** | Faz B themes | **10** catalog, **11** CMS theme agent, **12** assign/rollout, **13** GH webhooks | 10∥11 → 12 → 13 |
| **5** | Faz C | **14** security/runbooks, **15** prod deploy plane | Sıralı ağırlıklı |

Detay brief’ler: `_Dalga-0` … `_Dalga-5`.

---

## Paralel vs sıralı (özet)

```text
Dalga 0 ──(Go/Hybrid)──► Dalga 1
                              │
                              ▼
                         Task 0 BOOTSTRAP
                              │
                              ▼
                         Task 1 SCHEMA
                              │
                              ▼
                         Dalga 2
                    ┌─────────┼─────────┐
                    │         │         │
              Task 2 CLIENT   Task 3 UI │
                    │         (draft)   │
                    └────┬────┘         │
                         ▼              │
              Task 4 PROVISION ◄────────┘
                         │
            ┌────────────┼────────────┐
            ▼            ▼            ▼
        Task 5       Task 6       Task 7
       CHANNEL     WEBHOOKS      IMPORT
            │            │            │
            └────────────┴────────────┘
                         ▼
                    Dalga 3
            ┌────────────┴────────────┐
            ▼                         ▼
     Task 8 (deamon)           Task 9 (plane)
            └────────────┬────────────┘
                         ▼
                    Dalga 4
            ┌────────────┴────────────┐
            ▼                         ▼
     Task 10 (plane)           Task 11 (deamon)
            └────────────┬────────────┘
                         ▼
                    Task 12 ASSIGN
                         ▼
                    Task 13 WEBHOOKS
                         ▼
                    Dalga 5: 14 → 15
```

---

## Subagent rol brief’leri

Her fırlatmada role adını kullan; prompt’a HARD RULES + exclusive paths ekle.

### BOOTSTRAP (Task 0)

- **Repo:** deamon-plane
- **İş:** Laravel 12 iskelet, auth + roller (`super_admin`/`operator`/`viewer`), denser layout shell, `config/ops.php`, `.env.example`, Coolify Compose uyumu, smoke `GET /login`
- **Owns:** kök app iskeleti (`app/`, `routes/`, `resources/views/layouts`, `config/ops.php`); Laravel’i **mevcut** `Dockerfile` / `docker-compose.coolify.yml` / `docker/` / `.env.production.example` ile bağla (`APP_URL` ← `SERVICE_URL_APP`); local `docker-compose.yml` zaten var — ezme
- **Yasak:** Coolify gerçek çağrı; tema; CMS clone; Nixpacks; compose’u silip Dockerfile-only yapmak; `composer create-project` ile docker/docs/.cursor silmek

### SCHEMA (Task 1)

- **İş:** `sites`, `site_domains`, `deployments`, `audit_logs`, enums, factories, model testleri
- **Owns:** `database/migrations/*` (fleet), `app/Models/Site|Deployment|AuditLog.php`, enums, factories
- **Bekler:** BOOTSTRAP merge

### COOLIFY-CLIENT (Task 2)

- **İş:** `CoolifyClient` + DTO + Http::fake testler; Settings “Test connection”; spike imzalarına kilitle
- **Owns:** `app/Services/Coolify/*`, ilgili unit testler, settings partial (Coolify tab)
- **Bekler:** Dalga 0 API notları

### UI-SITES (Task 3)

- **İş:** Site CRUD Blade (draft desired state); policies; list toolbar
- **Owns:** `app/Http/Controllers/Ops/Site*`, `resources/views/ops/sites/*`, FormRequests, Site policies
- **Yasak:** provision job’ları (Task 4)

### PROVISION (Task 4)

- **İş:** `SiteProvisioner`, `ProvisionSiteJob`, `PollDeploymentJob`, status machine, audit
- **Owns:** `app/Services/Sites/SiteProvisioner.php`, `app/Jobs/Provision*`, `PollDeployment*`
- **Bekler:** COOLIFY-CLIENT

### CHANNEL (Task 5)

- **İş:** `ChannelSwitcher`, policy matrix, confirm UI, concurrent switch block
- **Owns:** `app/Services/Sites/ChannelSwitcher.php`, channel views/actions, related feature tests
- **Çakışma:** SiteController’a yalnızca channel action ekle; CRUD formunu UI-SITES’e bırak — ana agent merge

### WEBHOOKS-DEPLOY (Task 6)

- **İş:** Coolify webhook, deployments tab, fleet KPI dashboard
- **Owns:** `app/Http/Controllers/Webhooks/Coolify*`, `VerifyCoolifyWebhook`, `resources/views/ops/deployments*`, `ops/dashboard*`
- **Yasak:** GitHub theme webhooks (Task 13)

### IMPORT (Task 7)

- **İş:** `ops:import-coolify-apps` dry-run + upsert; runbook
- **Owns:** `app/Console/Commands/ImportCoolifyApps*`, import tests, `docs/runbooks/` import notları

### CMS-AGENT-HEALTH (Task 8) — ayrı repo

- **Repo:** `codron-co/deamon` (workspace ayrı)
- **İş:** HMAC middleware, `GET /internal/control/v1/health`, docs, CHANGELOG, version
- **Owns (CMS):** `routes/internal*`, `EnsureControlPlaneSignature`, Internal Controllers health, tests, deployment docs
- **Handoff:** plane’e imza algoritması + örnek header’lar + env key isimleri

### PLANE-AGENT-CLIENT (Task 9)

- **İş:** `SiteAgentClient`, `CheckSiteHealthJob`, health badge
- **Owns:** `app/Services/Agent/*`, health jobs, dashboard unhealthy chip
- **Bekler:** Task 8 imza sözleşmesi (stub ile başlanabilir; gerçek entegrasyon merge’de)

### THEME-CATALOG (Task 10)

- **İş:** theme schema, `GitHubAppClient`, `ThemeCatalogSync`, Themes index UI
- **Owns:** theme migrations/models, `app/Services/GitHub/*`, `ThemeCatalogSync`, `ops/themes/*` list

### CMS-THEME-AGENT (Task 11) — ayrı repo

- **Repo:** deamon
- **İş:** install/update/activate/sync; path traversal güvenliği; docs
- **Owns (CMS):** ThemeGitInstaller, theme internal controllers, tests

### THEME-ASSIGN (Task 12)

- **İş:** visibility, assign UI, `ThemeRolloutService`, install jobs
- **Owns:** `ThemeRolloutService`, ThemeInstall/Update jobs, site Themes tab

### THEME-WEBHOOKS (Task 13)

- **İş:** GitHub push webhook, fan-out, min version skip, concurrency
- **Owns:** `GitHubWebhookController`, related middleware/jobs flags

### SECURITY (Task 14)

- **İş:** audit completeness, redaction, IP allowlist optional, runbooks, security.md doldur
- **Owns:** `docs/security.md`, `docs/runbooks/*`, redaction helpers, audit gaps

### PROD-DEPLOY (Task 15)

- **İş:** Plane’i Coolify Compose ile **canlı** deploy + access restrict + import dry-run + staging smoke
- **Owns:** `docs/modules/deployment.md` / `docs/runbooks/deploy-plane.md` güncelle; **mevcut** `Dockerfile` + `docker-compose.coolify.yml` ezme (Dalga 0 sözleşmesi). Vite stage yalnızca Plane gerçekten Vite kullanırsa eklenir.

---

## Merge / ownership kuralları

| Alan | Tek sahip (paralel sırada) |
|------|----------------------------|
| `config/ops.php` | BOOTSTRAP oluşturur; sonradan ana agent veya tek “config” patch |
| `routes/web.php` | Ana agent merge hub — subagent’lar `routes/ops/*.php` include tercih etsin |
| `resources/views/layouts/*` | BOOTSTRAP |
| `app/Services/Coolify/*` | COOLIFY-CLIENT |
| `app/Models/Site.php` | SCHEMA; sonra feature agent’lar ilişki eklerken **ana agent sıraya koyar** veya tek agent |
| `docker-compose.coolify.yml` / `Dockerfile` / `docker/` | Dalga 0 sözleşme — BOOTSTRAP bağlar, **silmez**; Task 15 ezmez |
| Webhook controllers | Coolify ≠ GitHub — ayrı dosyalar |
| CMS paths | Asla plane tree altında |

**Çakışma olursa:** ana agent durur, bir tarafı yeniden fırlatır veya kendisi merge eder. İki agent’a aynı model dosyasını aynı anda verme.

---

## Verification gates (dalga sonu)

### Dalga 0

- Spike raporu + API method listesi
- Volume koruma kanıtı veya risk notu
- Go/Hybrid/No-Go kararı yazılı
- §18 defaults uygulanmış veya kullanıcı onayı

### Dalga 1

- `php artisan test` (veya PHPUnit) smoke login
- Migration migrate fresh OK
- Role middleware smoke
- Layout nav: Fleet / Sites / Themes placeholder / Settings

### Dalga 2

- CoolifyClient unit (Http::fake) yeşil
- Provision happy + failure feature (fake)
- Channel switch policy tests
- Webhook signature reject invalid
- Import dry-run çıktısı

### Dalga 3

- CMS: valid/invalid HMAC tests yeşil (deamon CI)
- Plane: health client fake + badge
- Sözleşme dokümanı (headers) her iki tarafta uyumlu

### Dalga 4

- Catalog sync unit
- Assign → agent fake → installation active
- Webhook fan-out + min version skip
- UI’da ZIP upload yok (rg/assert)

### Dalga 5

- Secret log redaction unit
- Runbooks dolu (provision / channel / theme)
- Prod checklist; staging smoke notu

---

## §Göm — her plane subagent prompt’una

```txt
Bu Deamon Plane işi subagent-driven.
SoT: docs/plans/2026-08-13-deamon-plane.md + .cursor/prompts/referans/_Scope-Constraints.md
HARD RULES: ayrı repo; Coolify = Docker Compose build pack (Nixpacks yok);
site-per MySQL+Redis; plane itself = docker-compose.coolify.yml + own MySQL+Redis;
channels main|beta|alpha; themes git-only; internal ops only; no Mailcow;
no ZIP in plane; secrets encrypted. Ezme: mevcut Dockerfile / docker-compose.coolify.yml.
Ownership: YALNIZCA sana verilen path’ler. Başka agent alanına yazma.
CMS değişiklikleri deamon-plane’e değil codron-co/deamon’a (ayrı track).
Bitince: değişen dosyalar + test komutları/sonuç + blocker listesi raporla.
Commit: orkestratör / kullanıcı istemedikçe commit etme.
```

### §Göm — CMS track subagent

```txt
Bu Deamon CMS control-plane AGENT yüzeyi (ince).
Repo: codron-co/deamon — multi-tenant / plane UI YASAK.
HMAC timestamp+nonce; secret yoksa route register etme.
Tema: git clone under themes/; path traversal test zorunlu; ZIP plane işi değil.
Docs: deployment.md + theme-repositories.md + CHANGELOG + version bump.
Plane reposuna dosya yazma. Handoff: imza şeması + env key’leri orkestratöre raporla.
```

---

## CMS handoff şablonu (Task 8/11)

Orkestratör plane ↔ deamon arasında şunu taşır:

```txt
## CMS Agent Handoff
Branch (deamon): <name>
Endpoints: <list>
Auth: HMAC-SHA256 timestamp.nonce.body; skew; nonce TTL
Env: CONTROL_PLANE_AGENT_SECRET, CONTROL_PLANE_IP_ALLOWLIST
Health JSON fields: deamon_version, channel_hint, active_theme_id, ...
Theme body fields: theme_id, repo, ref, sha, one-time clone token rules
Tests: <paths>
Docs updated: <paths>
Plane follow-up: SiteAgentClient imza uyumu / ThemeRollout payload
```

---

## Self-check

```
[ ] Dalga 0 Go/Hybrid
[ ] En az bir paralel subagent dalgası kullanıldı (Dalga 2+)
[ ] File ownership çakışması yok
[ ] Verification gate geçti
[ ] CMS ayrı track
[ ] Scope ihlali yok (shared DB, ZIP, self-serve, Mailcow)
[ ] Dalga raporu yazıldı
```

*Tek agent ile Plane tamamlanmış sayılmaz.*
