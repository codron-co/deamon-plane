# Deamon Plane Implementation Plan

I'm using the writing-plans skill to create the implementation plan.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Repo notu:** Bu repo (`codron-co/deamon-plane`) Deamon CMS'den ayridir. Deamon CMS tarafinda yalnizca ince bir **site agent** (imzali API) eklenir. Multi-tenant SaaS Deamon MVP kapsami disindadir; bu panel **operator (CodRon) internal ops** aracidir.

**Goal:** Coolify üzerindeki tüm Deamon müşteri sitelerini tek Laravel panelinden yönetmek: oluşturma, domain, `main`/`beta`/`alpha` kanal geçişi, deploy durumu; sonraki fazda `deamon-themes` org üzerinden git-bağlı tema kataloğu, site ataması ve otomatik tema güncellemesi.

**Architecture:** Thin control plane kendi MySQL’inde site/tema/deploy state tutar. Yaşam döngüsü **Coolify Public API** ile (uygulama oluştur, branch değiştir, domain bağla, redeploy, deploy log). Her müşteri sitesi mevcut model gibi **kendi `docker-compose.coolify.yml` stack’i** (app + **ayrı MySQL** + **ayrı Redis**) olarak kalır — paylaşımlı DB/Redis yok. Tema işlemleri Coolify SSH/exec ile değil; her Deamon instance’taki **HMAC/mTLS agent** üzerinden `theme:install|update|sync`. GitHub App / fine-grained token ile `deamon-themes` org ve webhook’lar.

**Tech Stack:** Laravel 12 (control plane), Coolify API v4, GitHub Apps API, Redis queue (yalnızca control plane job’ları), Blade denser admin UI, PHPUnit. Deamon CMS agent: Laravel route + middleware (imza doğrulama). Deploy hedef: Coolify’de ayrı uygulama.

**Related docs (Deamon CMS):** `codron-co/deamon` → `docs/modules/deployment.md`, `theme-repositories.md`, `theme-data-package.md`, `theme-system.md`.

---

## Global Constraints

- **Urun adi / repo:** Deamon Plane — `codron-co/deamon-plane` (bu repo).
- **Kullanıcı modeli (v1):** Yalnızca internal ops (CodRon). Müşteri self-service **yok**.
- **Deamon CMS:** Tek-site instance kalır; control plane Deamon’a multi-tenant eklemez.
- **Stack modeli:** Site başına Coolify application = **Docker Compose** build pack + `docker-compose.coolify.yml` → **app + mysql + redis** (izole volume’lar). Paylaşımlı MySQL/Redis **v1’de yasak**. Nixpacks / Dockerfile-only **yasak**.
- **Plane deploy:** Aynı Coolify modeli — bu repo `docker-compose.coolify.yml` (`plane_*` volumes; müşteri stack’i ile paylaşmaz). Detay: `docs/modules/deployment.md`.
- **Kanallar:** Git branch seti sabit: `main` | `beta` | `alpha` (config allowlist). Başka branch UI’dan seçilemez (override sadece Super Admin + audit).
- **Coolify env (müşteri app):** Yalnızca `APP_KEY` + `DEAMON_SITE_NAME` (+ Coolify’nin enjekte ettiği `SERVICE_URL_APP` / `SERVICE_FQDN_APP`). Diğerleri compose `environment:` bloğundan — Deamon CMS `docs/modules/deployment.md` ile uyum.
- **Tema SoT:** GitHub org `deamon-themes` (veya config’teki org); repo adı `deamon-theme-{theme_id}`. ZIP upload control plane’de **yok**.
- **Tema güven modeli:** Tema kodu trusted deploy (PHP/Blade); yalnızca org içi + review’lı repo. Müşteri rastgele repo bağlayamaz.
- **Gizli anahtarlar:** Coolify token, GitHub App private key, site agent shared secrets — control plane DB’de encrypted cast / vault; log’a yazılmaz; git’e commit edilmez.
- **Audit:** Site create, channel change, domain change, deploy trigger, theme assign/update — immutable audit log.
- **Onay:** Channel downgrade (`main`→`alpha`/`beta`) ve site destroy → confirm modal (Deamon admin confirm standardına benzer).
- **Version gate:** Channel switch öncesi hedef branch’in `DEAMON_VERSION` / migration uyumu kontrolü (agent `health` + opsiyonel semver policy); uyumsuzsa switch engellenir veya “force” + audit.
- **Aynı iş paketinde:** Control plane kendi `docs/` + README; Deamon CMS agent eklenirse `docs/modules/deployment.md` + `docs/modules/theme-repositories.md` + rules/skill kısa satır + CHANGELOG + version bump.

### Kapsam dışı (bilinçli)

| Madde | Not |
|-------|-----|
| Müşteri self-service panel | v2+ |
| Paylaşımlı MySQL/Redis | Operasyon kararı: site başına izole |
| Deamon içinde Modül Mağazası olarak control plane | Ayrı ürün |
| Tema ZIP yükleme (control plane) | Git-only |
| Plugin marketplace / faturalama / DNS registrar API | Sonraki ürünler |
| Creative-render / mobile-apk-builder otomasyonu | İsteğe bağlı v1.x |
| Nixpacks / Dockerfile-only Coolify pack | Plane ve müşteri siteleri Compose |

---

## 1. Problem ve mevcut durum

### Bugün

- ~35 Deamon sitesi Coolify’de **aynı proje altında** ayrı servisler olarak duruyor.
- Kanallar branch bazlı: `main`, `beta`, `alpha`.
- Her servis compose ile kendi MySQL + Redis’ine sahip (doğru izolasyon).
- Tema paketleri ayrı git repolarında; production’da ZIP veya manuel clone (Deamon CMS `docs/modules/theme-repositories.md`).
- Operasyon: Coolify UI’da elle oluştur / branch değiştir / domain bağla — ölçekte hata ve tutarsızlık riski.

### İstenen

1. **Faz A — Fleet management:** Panelden site oluştur, domain ver, kanal seç, deploy izle, kanallar arası geçiş.
2. **Faz B — Theme store ops:** Tema kataloğu (`deamon-themes`), domain/site bazlı erişim, git-bağlı install/sync, repo push → etkilenen sitelerde otomatik update.

---

## 2. Hedef mimari

```text
┌─────────────────────────────────────────────────────────────┐
│  Deamon Control Plane (Laravel)                             │
│  - Sites / Domains / Channels / Deploys                     │
│  - Theme catalog / Assignments / Webhooks                   │
│  - Audit + Ops UI                                           │
│  - Jobs: CoolifyProvision, ChannelSwitch, ThemeRollout      │
└───────────────┬─────────────────────────────┬───────────────┘
                │ Coolify API                 │ GitHub App
                ▼                             ▼
┌───────────────────────────┐     ┌───────────────────────────┐
│ Coolify                   │     │ deamon-themes org         │
│ App per customer:         │     │ deamon-theme-{id}         │
│  app + mysql + redis      │     │ push webhook → plane      │
│  branch = channel         │     └─────────────┬─────────────┘
│  domain → Traefik         │                   │
└─────────────┬─────────────┘                   │
              │ HTTPS (private net / signed)      │
              ▼                                   ▼
┌───────────────────────────┐     clone/pull on instance
│ Deamon CMS instance       │◄────────────────────┘
│  /internal/control/*      │
│  Agent: health, themes    │
└───────────────────────────┘
```

### Bileşen sorumlulukları

| Bileşen | Sorumlu | Sorumlu değil |
|---------|---------|---------------|
| Control plane | Desired state, UI, orchestration, audit | Müşteri içeriği, CMS admin |
| Coolify | Container lifecycle, proxy, TLS, volumes | İş kuralı (hangi tema kime) |
| Deamon instance | Site runtime, tema sync motoru, agent | Kendi kendini provision etmek |
| GitHub | Tema SoT + webhook | Deploy |

---

## 3. Karar kaydı (ADR özeti)

| ID | Karar | Gerekçe |
|----|-------|---------|
| ADR-1 | Ayrı repo / ayrı Coolify app | Deamon MVP tek-site; blast radius; güvenlik |
| ADR-2 | Site başına MySQL+Redis | Kuyruk/cache karışması yok; blast radius; mevcut compose |
| ADR-3 | Kanal = Coolify git branch + redeploy | Mevcut Coolify düzeni (`main`/`beta`/`alpha`) |
| ADR-4 | Tema = git clone/pull via agent, ZIP yok | Trusted code path; org ile hizalı |
| ADR-5 | Internal ops only v1 | Auth/izolasyon basit; self-serve sonra |
| ADR-6 | Coolify API + agent; SSH primitif değil | Tekrarlanabilir, audit edilebilir |
| ADR-7 | Channel switch volume’ları korur | DB/medya/tema volume persist; yalnızca app image/kod değişir |

---

## 4. Domain modeli (control plane DB)

### 4.1 Tablolar

**`ops_users`** — Laravel auth (veya standart `users`); roller: `super_admin`, `operator`, `viewer`.

**`sites`**

| Kolon | Tip | Not |
|-------|-----|-----|
| `id` | ulid/uuid | |
| `slug` | string unique | örn. `izyem` |
| `name` | string | `DEAMON_SITE_NAME` |
| `primary_domain` | string unique | FQDN |
| `channel` | enum | `main`,`beta`,`alpha` |
| `desired_channel` | enum nullable | geçiş sırasında |
| `status` | enum | `draft`,`provisioning`,`active`,`deploying`,`error`,`archived` |
| `coolify_app_uuid` | string nullable | Coolify application id |
| `coolify_server_uuid` | string nullable | hedef sunucu |
| `git_repository` | string | default `codron-co/deamon` |
| `app_key_encrypted` | text | `base64:…` APP_KEY |
| `agent_secret_encrypted` | text | HMAC secret |
| `agent_base_url` | string nullable | `https://domain` veya internal |
| `notes` | text nullable | |
| `last_health_at` | timestamp nullable | |
| `last_health_payload` | json nullable | version, theme_id, … |
| `timestamps` / soft deletes | | |

**`site_domains`**

| Kolon | Not |
|-------|-----|
| `site_id`, `domain`, `is_primary`, `coolify_domain_id`, `verified_at` | v1’de verify opsiyonel |

**`deployments`**

| Kolon | Not |
|-------|-----|
| `site_id`, `channel`, `trigger` (`create`,`channel_switch`,`manual`,`theme_rollout`) | |
| `coolify_deployment_uuid`, `status` (`queued`,`in_progress`,`finished`,`failed`,`cancelled`) | |
| `commit_sha`, `started_at`, `finished_at`, `log_excerpt`, `error_message` | |
| `requested_by` | ops user id |

**`channel_switch_policies`** (config veya tablo)

- `main` → `beta`/`alpha`: uyarı + confirm
- `alpha`/`beta` → `main`: version gate zorunlu
- Force flag yalnızca `super_admin`

**`themes`** (Faz B)

| Kolon | Not |
|-------|-----|
| `theme_id` | string unique (`beyazoglu`) |
| `repo_full_name` | `deamon-themes/deamon-theme-beyazoglu` |
| `default_ref` | `main` veya tag |
| `visibility` | `public_catalog`,`allowlist`,`private` |
| `minimum_deamon_version` | semver string nullable |
| `latest_sha`, `latest_tag`, `last_synced_at` | webhook/poll |

**`theme_site_access`** — allowlist: `theme_id` + `site_id`

**`site_theme_installations`**

| Kolon | Not |
|-------|-----|
| `site_id`, `theme_id`, `ref`, `pinned_sha`, `is_active` | |
| `status` (`pending`,`installing`,`active`,`error`,`updating`) | |
| `last_error`, `updated_from_webhook_at` | |

**`audit_logs`**

| Kolon | Not |
|-------|-----|
| `actor_user_id`, `action`, `subject_type`, `subject_id`, `before`, `after`, `ip`, `created_at` | append-only |

**`coolify_settings`** / `integration_settings` — encrypted token, base URL, default server/project uuid, webhook signing secret.

**`github_settings`** — App id, installation id, private key encrypted, org name, webhook secret.

### 4.2 State machine — `sites.status`

```text
draft → provisioning → active ⇄ deploying → active
                 ↘ error
active → archived
```

Deploy sırasında `deploying`; başarısızsa `error` + son deployment kaydı; retry ile `deploying`.

---

## 5. Coolify entegrasyonu

### 5.1 Gereken API yetenekleri (v4)

Implementasyon öncesi Coolify sürümünde doğrulanacak endpoint’ler (adapter arkasında):

1. Create application (Docker Compose build pack, repo URL, branch)
2. Set env vars (`APP_KEY`, `DEAMON_SITE_NAME`)
3. Set / sync domains
4. Trigger deployment
5. Get deployment status + logs
6. Update git branch (channel switch)
7. List servers / projects (bootstrap)

**Adapter:** `App\Services\Coolify\CoolifyClient` (HTTP) + `CoolifyApplicationService` (domain logic). Tüm ham JSON mock’lanabilir; PHPUnit’de Http::fake.

### 5.2 Provision akışı (yeni site)

1. Ops: slug, name, domain, channel, server seçimi.
2. Generate `APP_KEY` + `agent_secret`.
3. Control plane `sites` = `provisioning`.
4. Job `ProvisionSiteJob`:
   - Coolify’de application oluştur (repo `deamon`, branch = channel, compose file `docker-compose.coolify.yml`).
   - Env yaz.
   - Domain bağla.
   - Deploy tetikle.
   - `deployments` satırı oluştur; poll/webhook ile güncelle.
5. Deploy `finished` + `/up` 200 + agent `health` OK → `active`.
6. Hata → `error`, audit + UI banner.

### 5.3 Channel switch akışı

1. Ops: hedef channel seç + confirm.
2. Policy + version gate (agent’den mevcut `deamon_version`; opsiyonel olarak GitHub’dan hedef branch `config/deamon.php` version okuma — ağırsa agent’e bırak / manuel force).
3. `desired_channel` set; status `deploying`.
4. Coolify: update branch → redeploy.
5. Success: `channel = desired`; health check; audit.
6. **Veri:** MySQL/Redis/themes/storage volume’ları Coolify app’e bağlı kalır (branch değişimi volume silmez — compose volume adları app’e scoped olmalı; provision’da dokümante et).

### 5.4 Deploy görünürlüğü

- Site detay: son N deployment, status chip, süre, commit, “Coolify’de aç” linki.
- Webhook (Coolify → control plane) tercih; yoksa 15–30s poll job.
- Global “Fleet” dashboard: kaç site hangi kanalda, kaç deploy fail, unhealthy.

### 5.5 Mevcut 35 sitenin import’u

- One-shot artisan: `ops:import-coolify-apps` — Coolify list → `sites` upsert (slug heuristic: domain veya app name).
- Agent secret sonradan rotate + instance’a env olarak basma (Coolify env update + redeploy veya agent bootstrap token).
- Import dry-run zorunlu.

---

## 6. Deamon CMS — Site Agent (Faz A minimal, Faz B geniş)

Ana Deamon reposunda ince yüzey (ayrı plan task’ları):

### 6.1 Endpoint’ler

Base: `POST/GET /internal/control/v1/*`  
Middleware: `EnsureControlPlaneSignature` (HMAC-SHA256: `timestamp.nonce.body`; skew ±60s; replay cache).

| Method | Path | Amaç |
|--------|------|------|
| GET | `/health` | `deamon_version`, `channel_hint`, `active_theme_id`, `php`, `queue_ok` |
| POST | `/themes/install` | `{theme_id, repo, ref, sha?}` → clone into `themes/{id}` |
| POST | `/themes/update` | pull/fetch checkout sha → optional sync kick |
| POST | `/themes/activate` | `sites.active_theme_id` + cache clear |
| POST | `/themes/sync` | BackgroundTasks tema sync tetikle (capability seçimi body’de) |

**Güvenlik:**

- Yalnızca `CONTROL_PLANE_AGENT_SECRET` doluysa route register.
- Opsiyonel IP allowlist (`CONTROL_PLANE_IP_ALLOWLIST`).
- Response’ta secret yok; hata mesajları genel.
- Admin session ile paylaşılmaz.

### 6.2 Git clone temaları

- Deploy key veya GitHub App installation token (kısa ömürlü) control plane’den body’de **tek kullanımlık** clone token olarak verilebilir (log’lama).
- Alternatif: sunucuda org-wide read-only deploy key (daha az ideal).
- `themes/{id}` volume persist (`deamon_themes`).

### 6.3 Doküman etkisi (Deamon CMS)

- `docs/modules/deployment.md` — Control Plane agent bölümü
- `docs/modules/theme-repositories.md` — git install via agent
- rules/skill — “control plane ayrı ürün; agent yüzeyleri”

---

## 7. Tema mağazası (Faz B)

> **2026-09-10:** Tek `GITHUB_ORG` + Settings PAT/PEM paste **superseded**. SoT: [../superpowers/specs/2026-09-10-theme-git-connections-design.md](../superpowers/specs/2026-09-10-theme-git-connections-design.md). Aşağıdaki 7.1 metni tarihsel Faz B kilididir.

### 7.1 Katalog

- GitHub App: org’daki `deamon-theme-*` repolarını senkronize et → `themes` tablosu.
- Manifest okuma: `theme.json` (`id`, `name`, `minimum_deamon_version`, …).
- Visibility:
  - `public_catalog` — tüm managed siteler kurabilir
  - `allowlist` — `theme_site_access`
  - `private` — yalnızca explicit assign

### 7.2 Site’e tema ata

1. Panel: tema seç → ref (branch/tag) → Install.
2. Job: agent `themes/install` → success → optional `activate` + `sync`.
3. UI: kurulum durumu, sha, son hata, “Sync now”, “Update to latest”.

### 7.3 Webhook otomatik güncelleme

1. GitHub `push` / `release` → control plane.
2. Eşleşen `themes.repo_full_name`.
3. `site_theme_installations` where theme + auto_update flag.
4. Fan-out `ThemeUpdateJob` per site (rate limit: N concurrent).
5. Semver: site `deamon_version` < tema `minimum_deamon_version` → skip + notify ops.

### 7.4 ZIP yasağı

Control plane UI’da tema ZIP yok. Acil kurtarma hâlâ Deamon admin `themes.import` (Super Admin) — control plane bunu replace etmez; dokümante et.

---

## 8. Güvenlik modeli

| Tehdit | Mitigasyon |
|--------|------------|
| Coolify token çalınması | Encrypted storage; panel host izolasyonu; 2FA; token rotation runbook; mümkünse least privilege |
| Control plane ele geçirilmesi | Az kullanıcı; VPN/IP allowlist; audit; ayrı DB; `APP_DEBUG=false` |
| Agent endpoint abuse | HMAC + timestamp + nonce; IP allowlist; secret per site |
| Tema RCE | Yalnızca org repoları; review process; pin sha; otomatik update opt-in |
| Channel downgrade data break | Version/migration gate; confirm; backup reminder |
| Domain hijack | Domain uniqueness; Coolify conflict errors; opsiyonel DNS TXT |
| Secret leakage in logs | Redaction middleware; job payload mask |
| Replay | Nonce store (Redis) TTL 2 dk |

**Trust boundary diyagramı:** Ops browser → Control plane → (Coolify API | GitHub | Site agent). Müşteri CMS admin’i control plane’e erişmez.

---

## 9. UI / UX (control plane)

Internal denser ops UI (Vercel/Linear tarzı):

- **Fleet dashboard:** KPI — total sites, by channel, unhealthy, failed deploys (max 5–6).
- **Sites list:** toolbar search/filter (channel, status); row: domain, channel chip, deploy status, theme.
- **Site detail tabs:** Overview · Domains · Channel · Deployments · Themes · Audit.
- **Themes (Faz B):** catalog grid/list; detail; access; installations.
- **Settings (historical Faz B):** Coolify / GitHub connection tests. **Current:** Settings is env defaults; theme GitHub is Themes Manifest; Coolify token lives under Coolify.
- Tehlikeli işlemler: confirm modal (channel switch, destroy, force deploy).
- UI skill kapısı: control plane kendi UI’ında UI UX Pro Max + UI Design Brain (ayrı app).

---

## 10. Config yüzeyleri (control plane)

```env
# .env.example
COOLIFY_BASE_URL=
COOLIFY_API_TOKEN=
COOLIFY_DEFAULT_PROJECT_UUID=
COOLIFY_DEFAULT_SERVER_UUID=
COOLIFY_WEBHOOK_SECRET=

GITHUB_APP_ID=
GITHUB_APP_INSTALLATION_ID=
GITHUB_APP_PRIVATE_KEY=   # path or sealed
GITHUB_ORG=deamon-themes
GITHUB_WEBHOOK_SECRET=

DEAMON_GIT_REPOSITORY=https://github.com/codron-co/deamon.git
DEAMON_COMPOSE_FILE=docker-compose.coolify.yml
DEAMON_CHANNELS=main,beta,alpha

CONTROL_PLANE_URL=https://ops.example.com
```

```php
// config/ops.php (özet)
return [
    'channels' => ['main', 'beta', 'alpha'],
    'deamon' => [
        'repository' => env('DEAMON_GIT_REPOSITORY'),
        'compose_file' => env('DEAMON_COMPOSE_FILE', 'docker-compose.coolify.yml'),
    ],
    'themes' => [
        'org' => env('GITHUB_ORG', 'deamon-themes'),
        'repo_prefix' => 'deamon-theme-',
    ],
    'agent' => [
        'skew_seconds' => 60,
    ],
];
```

---

## 11. Repo / dosya haritası

### 11.1 Bu repo: `deamon-plane`

| Path | Role |
|------|------|
| `app/Models/Site.php` | Fleet site |
| `app/Models/Deployment.php` | Deploy kaydı |
| `app/Models/Theme.php` | Katalog |
| `app/Models/SiteThemeInstallation.php` | Kurulum |
| `app/Models/AuditLog.php` | Audit |
| `app/Services/Coolify/CoolifyClient.php` | HTTP API |
| `app/Services/Coolify/CoolifyApplicationService.php` | Provision / branch / domain |
| `app/Services/GitHub/GitHubAppClient.php` | App auth + repos |
| `app/Services/Agent/SiteAgentClient.php` | İmzalı HTTP client |
| `app/Services/Sites/SiteProvisioner.php` | Orkestrasyon |
| `app/Services/Sites/ChannelSwitcher.php` | Channel policy + switch |
| `app/Services/Themes/ThemeCatalogSync.php` | Org → DB |
| `app/Services/Themes/ThemeRolloutService.php` | Fan-out update |
| `app/Jobs/*` | Provision, DeployPoll, ChannelSwitch, ThemeInstall, ThemeUpdate |
| `app/Http/Controllers/Ops/*` | Web UI |
| `app/Http/Controllers/Webhooks/CoolifyWebhookController.php` | Deploy events |
| `app/Http/Controllers/Webhooks/GitHubWebhookController.php` | Theme push |
| `app/Http/Middleware/VerifyCoolifyWebhook.php` | |
| `app/Policies/*` | Role gates |
| `database/migrations/*` | Şema |
| `resources/views/ops/*` | Blade |
| `routes/web.php` / `routes/webhooks.php` | |
| `config/ops.php` | |
| `docs/README.md` | Control plane docs index |
| `docs/architecture.md` | Bu planın kısa canlı özeti |
| `docs/runbooks/provision-site.md` | |
| `docs/runbooks/channel-switch.md` | |
| `docs/runbooks/theme-rollout.md` | |
| `docs/security.md` | Token/agent |
| `tests/Feature/*` | |
| `tests/Unit/*` | |
| `docker-compose.yml` | Yerel plane (`APP_PORT` 8088) | BOOTSTRAP — ezme |
| `docker-compose.coolify.yml` | Coolify Compose (plane app+mysql+redis, `plane_*`) | Dalga 0 sözleşme; ezme |
| `Dockerfile` + `docker/` | Coolify image | Dalga 0 sözleşme; BOOTSTRAP Laravel’i bağlar |

### 11.2 Deamon CMS repo (sınırlı değişiklik — Faz A/B)

| Path | Role |
|------|------|
| `routes/internal.php` veya `routes/web.php` conditional | Agent routes |
| `app/Http/Middleware/EnsureControlPlaneSignature.php` | HMAC |
| `app/Http/Controllers/Internal/Control/*` | health, themes |
| `app/Services/ControlPlane/ThemeGitInstaller.php` | clone/pull safe path |
| `config/deamon.php` | `control_plane` config keys |
| `.env.example` / `.env.production.example` | `CONTROL_PLANE_*` opsiyonel |
| `docs/modules/deployment.md` | Agent bölümü |
| `docs/modules/theme-repositories.md` | Agent install |
| `tests/Feature/ControlPlane/*` | |
| `.cursor/rules/deamon-project.mdc` | Kısa sınır satırı |
| `.cursor/skills/deamon-mvp/SKILL.md` | Control plane ayrı ürün notu |
| `CHANGELOG.md` + version | Agent ship ile |

---

## 12. Fazlar ve başarı kriterleri

### Faz 0 — Discovery / spike (1–3 gün)

**Çıktı:** Coolify API ile gerçekten yapılabildiğinin kanıtı (script veya küçük PoC).

Spike notları (OpenAPI map + adapter imzaları + §18 kilit): [2026-08-13-coolify-spike-notes.md](2026-08-13-coolify-spike-notes.md). Script: `tools/coolify-spike/`.

- [x] Coolify Public API v4 OpenAPI: list / branch PATCH / deploy / status / domains / env bulk / servers / projects **dokümante** (canlı token yok)
- [x] Coolify API token ile list applications **(canlı)** — 43 apps; Susa `crxguq6nodorlzy88wf9x305`
- [x] Bir staging app’te branch değiştir + deploy tetikle + status oku **(canlı)** — Susa `alpha`→`beta`; deploy `mc4nhqssbfcn0o1ljpi38w11` `finished`; left on **beta**
- [x] Domain bind API doğrula **(canlı)** — OpenAPI `PATCH docker_compose_domains` mapped; **live PATCH skipped** (`SPIKE_PROBE_DOMAIN` unset, do not bind a random domain on Susa). Live GET: compose domains JSON string; `fqdn` null
- [x] Compose volume’ların branch switch sonrası korunduğunu doğrula **(canlı)** — 6 volume names unchanged (ADR-7)
- [x] Eksik/risk API → adapter “manual step” taslağı (create private-git; webhook register yok; DELETE `delete_volumes` default true)

**Go/No-Go (2026-08-13):** **Go**. Live list + updateBranch + deploy + getDeployment + volume OK. setDomains OpenAPI-proven (live domain PATCH skipped by design). `createComposeApp` not live-posted (optional). Dalga 1 Laravel scaffold **may start** (not started in this spike). Details: [2026-08-13-coolify-spike-notes.md](2026-08-13-coolify-spike-notes.md).

### Faz A — Fleet MVP

**Başarı:**

1. Panelden yeni site → Coolify’de stack ayağa kalkar → domain yanıt verir.
2. Panelden `alpha`↔`beta`↔`main` geçişi → site aynı domain’de yeni kanal koduyla gelir; DB içeriği kaybolmaz.
3. Deploy listesi gerçek status gösterir.
4. Mevcut sitelerin çoğu import edilmiştir.
5. Agent `/health` çalışır (tema komutları stub olabilir).

### Faz B — Theme ops

**Başarı:**

1. Org temaları katalogda görünür.
2. Site’e git-bağlı tema kurulur + sync tetiklenir (ZIP yok).
3. Allowlist / public visibility çalışır.
4. Tema repo push → allowlist sitelerde update job (opt-in).
5. `minimum_deamon_version` ihlalinde skip + ops bildirimi.

### Faz C — Sertleştirme (paralel / sonrası)

- DNS verify, backup reminder before channel switch, Slack/email notify, metrics, SSO (opsiyonel).

---

## 13. Implementation tasks

> Her task kendi test döngüsüyle biter. Bu repo iskeleti mevcut; Task 0 Laravel uygulamasini buraya kurar.

### Task 0: Laravel bootstrap (bu repo)

**Files:**
- Create: Laravel 12 iskeleti, auth (Breeze/Fortify minimal), `config/ops.php` (mevcut Coolify Compose dosyalarını **ezme**)
- Modify: `APP_URL` ← `SERVICE_URL_APP`; entrypoint `migrate` Laravel sonrası çalışır

**Interfaces:**
- Produces: Çalışan boş ops app + login + `/ops` scaffold; Coolify Docker Compose ile kurulabilir

- [ ] **Step 1:** Laravel 12 (PHP 8.2+) — `docs/`, `.cursor/`, `docker-compose.coolify.yml`, `Dockerfile`, `docker/` koru
- [ ] **Step 2:** Auth + role middleware (`super_admin`, `operator`, `viewer`)
- [ ] **Step 3:** denser layout shell + nav (Fleet, Sites, Themes placeholder, Settings)
- [ ] **Step 4:** `.env.example` + `config/ops.php` (`DEAMON_COMPOSE_FILE=docker-compose.coolify.yml`)
- [ ] **Step 5:** `APP_URL` Coolify `SERVICE_URL_APP` fallback
- [ ] **Step 6:** PHPUnit smoke (`GET /login` 200)
- [ ] **Step 7:** Commit: `chore: bootstrap deamon-plane ops app`

---

### Task 1: Schema — sites, deployments, audit

**Files:**
- Create: migrations + models + factories
- Test: `tests/Unit/Models/SiteTest.php`

**Interfaces:**
- Produces: `Site`, `Deployment`, `AuditLog` Eloquent + enums `SiteStatus`, `Channel`

- [ ] **Step 1:** Migration yaz (bölüm 4.1)
- [ ] **Step 2:** Enums + model ilişkileri
- [ ] **Step 3:** Factory + feature test create site draft
- [ ] **Step 4:** Commit: `feat: sites deployments audit schema`

---

### Task 2: Coolify client adapter + fake

**Files:**
- Create: `CoolifyClient`, DTOs, `CoolifyApiException`
- Test: `tests/Unit/Coolify/CoolifyClientTest.php` (Http::fake)

**Interfaces:**
- Produces: `listApps()`, `createComposeApp(...)`, `updateEnvs(...)`, `setDomains(...)`, `updateBranch(...)`, `deploy(...)`, `getDeployment(...)`

- [ ] **Step 1:** Faz 0 spike bulgularına göre method imzalarını kilitle
- [ ] **Step 2:** Client + error mapping
- [ ] **Step 3:** Http::fake ile unit testler
- [ ] **Step 4:** Settings UI: token kaydet (encrypted) + “Test connection”
- [ ] **Step 5:** Commit: `feat: coolify api client`

---

### Task 3: Site CRUD UI + desired state

**Files:**
- Create: `Ops/SiteController`, FormRequests, views `ops/sites/*`
- Test: `tests/Feature/Ops/SiteCrudTest.php`

**Interfaces:**
- Consumes: Site model
- Produces: draft site kayıtları (henüz Coolify yok)

- [x] **Step 1:** List toolbar (search, channel/status filter)
- [x] **Step 2:** Create/edit form (slug, name, domain, channel, server)
- [x] **Step 3:** Policy (viewer read-only)
- [x] **Step 4:** Feature tests
- [ ] **Step 5:** Commit: `feat: ops site crud` (not committed — orchestrator / user)

---

### Task 4: Provision pipeline

**Files:**
- Create: `SiteProvisioner`, `ProvisionSiteJob`, `PollDeploymentJob`
- Modify: SiteController `@provision`
- Test: `tests/Feature/Sites/ProvisionSiteTest.php` (Coolify fake)

**Interfaces:**
- Consumes: CoolifyApplicationService
- Produces: `coolify_app_uuid`, deployment rows, status transitions

- [x] **Step 1:** Generate APP_KEY + agent secret (encrypted)
- [x] **Step 2:** Job: create app → env → domain → deploy
- [x] **Step 3:** Poll/webhook status → `active`/`error` (poll job; webhooks are Task 6)
- [x] **Step 4:** Audit log entries
- [x] **Step 5:** Feature test full happy path + failure path
- [ ] **Step 6:** Commit: `feat: site provision via coolify` (orchestrator / user)

---

### Task 5: Channel switch + policies

**Files:**
- Create: `ChannelSwitcher`, policy config, confirm UI
- Test: `tests/Feature/Sites/ChannelSwitchTest.php`

**Interfaces:**
- Consumes: Coolify `updateBranch` + `deploy`; Agent health (optional stub)
- Produces: channel update + deployment record

- [x] **Step 1:** Policy matrix (confirm + force)
- [x] **Step 2:** Switch job + UI
- [x] **Step 3:** Block concurrent switches (`deploying`)
- [x] **Step 4:** Tests
- [ ] **Step 5:** Commit: `feat: channel switch main beta alpha` (orchestrator / user)

---

### Task 6: Deployments UI + Coolify webhooks

**Files:**
- Create: webhook controller, deployment index partial
- Test: webhook signature + status update tests

- [x] **Step 1:** Webhook endpoint + secret verify
- [x] **Step 2:** Map Coolify payloads → `deployments.status`
- [x] **Step 3:** Site detail Deployments tab
- [x] **Step 4:** Fleet dashboard KPI
- [ ] **Step 5:** Commit: `feat: deployment status and webhooks`

---

### Task 7: Import existing Coolify apps

**Files:**
- Create: `ops:import-coolify-apps` command
- Test: unit with fake list payload

- [x] **Step 1:** Dry-run table output
- [x] **Step 2:** Upsert by coolify uuid / domain
- [x] **Step 3:** Runbook dokümanı
- [ ] **Step 4:** Commit: `feat: import coolify fleet`

---

### Task 8: Deamon CMS — Agent `/health` + HMAC (ana repo)

**Files:** (Deamon CMS)
- Create: middleware, controller, config, tests, docs

**Interfaces:**
- Produces: signed health JSON
- Control plane: `SiteAgentClient::health(Site): HealthDto`

- [ ] **Step 1:** HMAC middleware + nonce cache
- [ ] **Step 2:** `GET /internal/control/v1/health`
- [ ] **Step 3:** Feature tests (valid/invalid signature)
- [ ] **Step 4:** Docs + rules/skill + CHANGELOG + version bump
- [ ] **Step 5:** Commit on deamon: `feat: control plane agent health endpoint`

---

### Task 9: Control plane ↔ agent health integration

**Files:**
- Create: `SiteAgentClient`, `CheckSiteHealthJob`, UI health badge
- Test: agent fake

- [x] **Step 1:** Client signing
- [x] **Step 2:** Schedule health poll (5–15 dk) + on-demand button
- [x] **Step 3:** Unhealthy attention on dashboard
- [ ] **Step 4:** Commit: `feat: site agent health checks`

---

### Task 10: Theme schema + GitHub catalog sync (Faz B)

**Files:**
- Create: theme migrations/models, `GitHubAppClient`, `ThemeCatalogSync`
- Test: catalog sync unit/feature

- [x] **Step 1:** Schema `themes`, `theme_site_access`, `site_theme_installations`
- [x] **Step 2:** List org repos with prefix filter
- [x] **Step 3:** Parse `theme.json` from default branch
- [x] **Step 4:** Themes index UI
- [ ] **Step 5:** Commit: `feat: theme catalog from github org`

---

### Task 11: Deamon CMS — Theme git install/update agent

**Files:** (Deamon CMS)
- Create: `ThemeGitInstaller`, internal theme controllers, safe path checks (`realpath` under `themes/`)
- Test: uses temp dir fixture; no network in unit (Process facade fake)

- [ ] **Step 1:** install/update/activate endpoints
- [ ] **Step 2:** Path traversal tests
- [ ] **Step 3:** Docs theme-repositories + deployment
- [ ] **Step 4:** Commit on deamon: `feat: control plane theme git agent`

---

### Task 12: Theme assign + rollout jobs (Faz B)

**Files:**
- Create: `ThemeRolloutService`, jobs, site Themes tab UI
- Test: assign → agent fake → installation active

- [x] **Step 1:** Visibility checks (public/allowlist/private)
- [x] **Step 2:** Install + optional activate/sync
- [x] **Step 3:** Manual “Update now”
- [ ] **Step 4:** Commit: `feat: theme assign and install via agent`

---

### Task 13: GitHub webhooks → auto update

**Files:**
- Create: GitHub webhook controller, fan-out job, auto_update flag
- Test: signature + fan-out limits

- [x] **Step 1:** Verify webhook secret
- [x] **Step 2:** Match repo → theme → installations
- [x] **Step 3:** `minimum_deamon_version` skip
- [x] **Step 4:** Rate limit concurrency
- [ ] **Step 5:** Commit: `feat: theme push auto rollout`

---

### Task 14: Security hardening + runbooks

**Files:**
- Create: `docs/security.md`, runbooks, redaction helpers
- Test: secret not in logs (unit)

- [x] **Step 1:** Audit tüm mutasyonlarda
- [x] **Step 2:** IP allowlist opsiyonel middleware
- [x] **Step 3:** Token rotation runbook
- [x] **Step 4:** Backup reminder copy on channel switch
- [ ] **Step 5:** Commit: `docs: security and ops runbooks`

---

### Task 15: Production deploy control plane

**Files:**
- Modify: `docs/modules/deployment.md`, `docs/runbooks/deploy-plane.md` — **mevcut** `Dockerfile` / `docker-compose.coolify.yml` ezme

- [x] **Step 1:** Coolify’de Plane app: Docker Compose + `docker-compose.coolify.yml` (kendi MySQL+Redis) — compose contract complete; **live deploy skipped** (spike app unhealthy / high risk)
- [x] **Step 2:** Restrict access (SSO/VPN/IP) — docs + `OPS_IP_ALLOWLIST`
- [ ] **Step 3:** Import 35 sites dry-run → apply — operator on wake
- [ ] **Step 4:** Smoke: 1 staging CMS site provision + channel switch — operator on wake
- [ ] **Step 5:** Commit: `chore: production deploy docs for control plane`

---

## 14. Test stratejisi

| Katman | Ne |
|--------|----|
| Unit | CoolifyClient mapping, HMAC sign/verify, channel policy, theme visibility |
| Feature | CRUD, provision fake, switch, webhooks, agent auth |
| Integration (staging) | Gerçek Coolify staging server’da 1 app |
| Manual smoke | Domain HTTPS, `/up`, admin login, channel switch sonrası içerik |

Control plane CI: PHPUnit + pint. Deamon agent değişiklikleri mevcut Deamon CI’ya ek test dosyaları.

---

## 15. Operasyon runbook özetleri

### Yeni müşteri

1. Panel → Sites → Create  
2. Provision  
3. DNS A/CNAME → Coolify  
4. Health yeşil  
5. (Faz B) Tema ata + sync  
6. Müşteriye admin bilgisi (Deamon varsayılan admin + şifre değiştirme)

### Kanal geçişi

1. Backup notu / confirm  
2. Switch  
3. Deploy finished  
4. Health + kritik sayfa smoke  
5. Fail ise previous channel’a geri switch (manuel veya “rollback channel” butonu — v1.1)

### Tema güncelleme

1. PR merge tema repo  
2. Webhook veya Update now  
3. Installation sha güncellenir  
4. Gerekirse sync capability  

---

## 16. Riskler ve mitigasyon

| Risk | Etki | Mitigasyon |
|------|------|------------|
| Coolify API eksik/yetersiz | Otomasyon yarım | Faz 0 spike; hybrid UI |
| Branch switch volume kaybı | Veri kaybı | Spike’ta doğrula; volume adlarını sabitle |
| Migration uyumsuz channel | Site down | Version gate + staging channel önce |
| Agent secret sızıntısı | RCE-ish theme ops | Per-site secret; rotate; IP allowlist |
| Theme auto-update bozar | İçerik/regresyon | Opt-in; pin sha; canary site |
| Parallel builds on small VPS | Yavaş deploy | Coolify queue; panel’de global deploy concurrency limiti |
| Ops scope creep → müşteri self-serve | Güvenlik | Faz C öncesi bilinçli ADR |

---

## 17. Tahmini efor (tek kıdemli dev)

| Faz | Süre (takvim) |
|-----|----------------|
| Faz 0 spike | 1–3 gün |
| Faz A (Task 0–9) | 2–4 hafta |
| Faz B (Task 10–13) | 2–3 hafta |
| Faz C / prod harden (Task 14–15) | 1 hafta |

Paralel: Deamon agent (Task 8/11) ile control plane UI aynı anda gidebilir.

---

## 18. Açık sorular (uygulama öncesi kilitlenecek)

**Dalga 0 kilit (2026-08-13, önerilen default’lar uygulandı):**

1. **Coolify proje yapısı:** **Tek project + tag/slug** (`GET /applications?tag=`).
2. **GitHub org adı:** Config `GITHUB_ORG=deamon-themes`. Mevcut `codron-co/deamon-theme-*` taşıması **ayrı iş** (bu build değil). **Superseded 2026-09-10:** Themes Manifest + N user/org connections; `GITHUB_ORG` is not a catalog lock.
3. **Agent network:** **Public HTTPS + HMAC** + opsiyonel IP allowlist (v1). WireGuard sonra.
4. **Channel switch rollback:** **Manuel** v1; otomatik rollback v1.1.
5. **Theme auto-update:** varsayılan **off** (opt-in).
6. **Mevcut sitelerde agent secret:** Import sonrası **toplu Coolify env patch + redeploy** job/runbook.

Mailcow: ADR-9 — bu build dışı.

---

## 19. Dokümantasyon güncelleme checklist (ship sırasında)

### Bu repo (`deamon-plane`)

- [ ] `README.md`, `docs/architecture.md`, `docs/modules/deployment.md`, runbooks, `docs/security.md`

### `deamon` repo (agent ship ile)

- [ ] `docs/modules/deployment.md` — Control Plane Agent
- [ ] `docs/modules/theme-repositories.md` — git install via agent
- [ ] `docs/README.md` — link (bu plan + agent)
- [ ] `.cursor/rules/deamon-project.mdc` — control plane ayrı ürün; paylaşımlı DB yok kararı ops notu değil CMS kuralı
- [ ] `.cursor/skills/deamon-mvp/SKILL.md` — sınır notu
- [ ] `CHANGELOG.md` + `DEAMON_VERSION`

---

## 20. Özet karar (bu planın sabitledikleri)

1. Ayrı Laravel **Control Plane** — Deamon’a multi-tenant eklenmez.  
2. Site başına **ayrı MySQL + Redis** (mevcut compose) — paylaşımlı havuz yok.  
3. Kanal = **git branch** + Coolify redeploy.  
4. Tema = **`deamon-themes` git** + agent; ZIP panelden yok.  
5. v1 kullanıcı = **internal ops only**.  
6. Güvenlik = encrypted tokens + per-site agent HMAC + audit + confirm gates.  
7. Kurulum = Coolify **Docker Compose** (`docker-compose.coolify.yml`); Nixpacks yok.

Bu dosya uygulama sırasında living document’tır. Faz 0 OpenAPI map + §18 kilit: [2026-08-13-coolify-spike-notes.md](2026-08-13-coolify-spike-notes.md). Canlı Go/Hybrid sonrası §5 imzaları o nota göre dondurulur.
