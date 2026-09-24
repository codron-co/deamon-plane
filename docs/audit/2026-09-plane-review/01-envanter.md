# 01 — Envanter

**Tarih:** 2026-09-24 · **HEAD:** 8484d37 · **Hazırlayan:** S0

Kapsam: yalnızca `deamon-plane` (dal `alpha`). Tüm sayılar bu HEAD'de komutla üretildi. Önceki inceleme: [../../plans/2026-09-14-plane-review-and-proposals.md](../../plans/2026-09-14-plane-review-and-proposals.md) (HEAD `242ae15`).
Geçici dosyalar yalnız `%TEMP%` altına yazıldı; repo'da bu iki doküman dışında değişiklik yok.

---

## 1. Özet sayılar

| Ölçü | Değer | Komut |
|------|-------|-------|
| Route | **203** | `php artisan route:list --json` → `count()` |
| Controller | Ops 31 (+2 trait `Ops/Concerns`), Internal 1, Webhooks 2, taban 1 | `find app/Http/Controllers -name '*.php'` |
| Servis sınıfı | **126** dosya / 19 967 satır (12 alt dizin) | `find app/Services -name '*.php'` |
| Job | **18** | `ls app/Jobs` |
| Artisan komutu | **5** `ops:*` (+3 yardımcı sınıf `ImportCoolifyApps/`) | `grep '$signature' app/Console/Commands` |
| Schedule girişi | **4** | `routes/console.php` |
| Model | **28** | `ls app/Models` |
| Migration | **56** dosya | `ls database/migrations \| wc -l` |
| Tablo | 35 (`Schema::create`) + 5 spatie izin tablosu = **40** | `grep -ho "Schema::create('…'"` |
| Audit action (benzersiz literal) | **83** | §6'daki tokenizer betiği |
| PHPUnit | **1080 passed / 0 failed, 6128 assertion, 572 s** | `php artisan test --compact` |
| Node | **22 pass / 0 fail** | `node --test tests/js/ops-contracts.test.js` |
| Test dosyası | Feature 122 + Unit 33 = **155** (+1 JS) | `find tests/{Feature,Unit} -name '*Test.php'` |
| Blade view | **100** | `find resources/views -name '*.blade.php'` |
| Lang anahtarı | en 2286 = tr 2286 (15 dosya, eksik **0**) | PHP flatten karşılaştırması |
| Laravel | v12.66.0 | `composer.lock` |

---

## 2. Route'lar

Komut: `php artisan route:list --json` (DB/env hatası yok). `HEAD` sayılmadı.

### 2.1 Metot

| GET | POST | DELETE | PUT | PATCH |
|-----|------|--------|-----|-------|
| 59 | 110 | 18 | 13 | 3 |

### 2.2 Alan (ilk URI segmenti)

| Alan | Route | Not |
|------|-------|-----|
| Ops paneli (`web`+`auth`) | 184 | sites 76, cloudflare 20, coolify 19, themes 15, settings 11, account 10, jobs 7, mail-servers 7, domains 6, platform-mail 4 (+2 imzalı), activity 4, deskron 3, `/` 1, palette 1 |
| Auth (Fortify) | 6 | `login` GET/POST, `logout`, `user/confirm-password` ×2, `user/confirmed-password-status` |
| Webhooks | 2 | `webhooks/coolify`, `webhooks/github` |
| Internal (CMS → Plane) | 6 | `internal/site/v1/mail/*` |
| İmzalı public | 2 | `platform-mail/unsubscribe` GET/POST (`signed`) |
| Framework | 3 | `storage/{path}` GET+PUT (`config/filesystems.php:36` `'serve' => true`), `up` |
| **Toplam** | **203** | |

### 2.3 Middleware

| Middleware | Route | Kaynak |
|------------|-------|--------|
| `web` | 192 | `RestrictOpsByIp`, `SetOpsLocale`, `ConvertOpsAjaxRedirect` web grubuna eklenmiş (`bootstrap/app.php`, `$middleware->web(append: …)`) |
| `auth` | 184 | `routes/web.php:21` grup |
| `auth:web` / `guest:web` | 4 / 2 | Fortify |
| `throttle:60,1` | 8 | webhooks 2 + internal 6 |
| `EnsureSiteMailSignature` | 6 | `routes/internal-site.php` |
| `VerifyCoolifyWebhook` / `VerifyGitHubWebhook` | 1 / 1 | `routes/webhooks.php:10,14` |
| `signed` | 2 | `routes/web.php:16,19` |
| `throttle:login` | 1 | Fortify |
| `role:*` | **0** | Alias tanımlı ama route'ta kullanılmıyor; yetki controller'da `authorize()`/`can()` + `Gate::define('ops.write')` + 4 policy (`app/Providers/AppServiceProvider.php:35-39`) |
| IP allowlist | 192 (`web` grubu) | `app/Http/Middleware/RestrictOpsByIp.php:20-30` — liste boşsa herkese açık; webhooks/internal/storage/up kapsam dışı |

Route dosyaları: `routes/web.php` 78, `routes/ops/*.php` 7 dosya 218 satır (sites 100), `routes/webhooks.php` 15, `routes/internal-site.php` 14, `routes/console.php` 42.

---

## 3. Controller'lar

Komut: `find app/Http/Controllers -name '*.php' -exec wc -l {} + | sort -rn`. Toplam 7038 satır. Route sütunu route:list `action` alanından.

| # | Controller | Satır | Route |
|---|------------|-------|-------|
| 1 | Ops/SiteController | 1355 | 22 |
| 2 | Ops/SiteCoolifyOpsController | 549 | 22 |
| 3 | Ops/CloudflareOpsController | 435 | 20 |
| 4 | Ops/CoolifyConnectionController | 409 | 15 |
| 5 | Ops/DomainController | 293 | 6 |
| 6 | Ops/ThemeController | 259 | 6 |
| 7 | Ops/OpsJobController | 254 | 7 |
| 8 | Ops/MailServerOpsController | 254 | 7 |
| 9 | Internal/SiteMailProxyController | 254 | 6 |
| 10 | Ops/SiteAdminController | 253 | 7 |
| 11 | Ops/CoolifyInventoryController | 232 | 4 |
| 12 | Ops/SiteListPreferencesController | 207 | 5 |
| 13 | Ops/ActivityController | 204 | 4 |
| 14 | Ops/SiteDomainController | 203 | 3 |
| 15 | Ops/PlatformMailSettingsController | 202 | 4 |
| 16 | Ops/ThemeGitConnectionController | 178 | 9 |
| 17 | Ops/SiteThemeController | 169 | 7 |
| 18 | Ops/DeamonGitConnectionController | 139 | 5 |
| 19 | Ops/SiteCloudflareController | 132 | 2 |
| 20 | Ops/SiteAppHealthController | 130 | 3 |

Kalan Ops: SettingsController 121, PreferencesController 114, SitePublishStatusController 108, SiteDetailController 103, DeskronSettingsController 91, AccountController 83, FleetController 55, DeploymentDiagnosisController 37, DeploymentShowController 31, GithubSettingsController 28, OpsPaletteController 22, PlatformMailUnsubscribeController 18. Webhooks: CoolifyWebhookController, GitHubWebhookController.

Büyüme: `SiteController` 1051 → 1355, `SiteCoolifyOpsController` 518 → 549 (`git show 242ae15:… | wc -l`).
242ae15'ten beri yeni: `DeamonGitConnectionController`, `DeploymentDiagnosisController`, `DeskronSettingsController`.

---

## 4. `app/` katmanları

### 4.1 Services

Komut: `for d in app/Services/*/; do find $d -name '*.php' | wc -l; … | wc -l; done`

| Dizin | Sınıf | Satır | Başlıca sınıflar |
|-------|-------|-------|------------------|
| Sites | 32 | 5487 | SiteProvisioner, SiteLifecycle, ChannelSwitcher, ComposePackMigrator, CoolifyDeploySettings, SiteAppHealth{Inspector,Fixer}, SiteAgentSecret{Injector,Sweep}, SiteDomain{Reconciler,Sync}, SitePrimaryDomain, SiteLanding, SiteLiveProbe, SiteIdentityPusher, SitePublishStateUpdater, `Diagnosis/*` (3) |
| Coolify | 32 | 4218 | CoolifyClient, CoolifyApplicationService, CoolifyDeployGate, CoolifyRateGuard, CoolifyInventorySync, CoolifySiteSync, CoolifySiteTargetSync, CoolifyDeploymentSync, CoolifyAppEnvSync, CoolifyWebhookHandler, `Dto/*` (11), `EnvCatalog/*` (5) |
| Agent | 13 | 1909 | SiteAgentClient, SiteHealthChecker, SiteHealthEvaluator, CoreThemeHealer, ControlPlaneAgentContract, `*Result` DTO'ları, `Concerns/RetriesThrottledAgentRequests` |
| Ops | 6 | 1822 | OpsCoolifyDeployQueue, OpsJobManager, OpsJobRunner, PacedFanout, PaletteSearch, BulkResultSummary |
| Cloudflare | 11 | 1639 | CloudflareClient, CloudflareZoneService, CloudflareDnsTemplate, CloudflarePermissionProbe, PreviewHostname |
| Themes | 8 | 1451 | ThemeCatalogSync, ThemeRolloutService, ThemeSmokeCheck, ThemeVersionGate, ThemeVisibilityGate, ThemeGitConnectionService |
| Mail | 11 | 1370 | PlatformMail{Configurer,Resolver,State,Unsubscribe}, PlatformNotificationCatalog, PlatformOpsMailer, SiteHealthMailNotifier, SiteMailConfigurer, SiteMailOrderBinder |
| GitHub | 7 | 1103 | GitHubAppClient, GitHubAppJwt, GitHubWebhookHandler, DeamonGitConnectionService, ThemeManifest |
| Hostinger | 2 | 416 | HostingerMailClient |
| Fleet | 1 | 250 | FleetDashboardKpis |
| Domains | 2 | 196 | DomainBindSweep, DomainClearSweep |
| Deskron | 1 | 106 | DeskronConfigurer |
| **Toplam** | **126** | **19 967** | 242ae15'te 108 dosya (`git ls-tree -r 242ae15 app/Services`) |

### 4.2 Diğer katmanlar

Komut: `find app/<dir> -name '*.php' | wc -l` ve satır toplamı.

| Katman | Dosya | Satır | İçerik |
|--------|-------|-------|--------|
| Support | 23 | 2777 | ControlPlaneAgentSignature, CoolifyWebhookSignature, GitHubWebhookSignature, SecretRedactor, ProductionDebugGuard, RetryAfter, OpsFreshness, OpsAppearance, PublicAppUrl, IdentityMark, MysqlWallClockShift, ThemeGitOAuthState, `Lists/*` (6), `Ops/*` (5: ActivityFeed/Filters/Row, PaletteFilters, SettingsJump) |
| Models | 28 | 3986 | `Site.php` 1250 satır (242ae15'te 915) |
| Enums | 16 | 432 | OpsRole (`super_admin`/`operator`/`viewer`), SiteStatus, Channel, DeploymentStatus/Trigger, CmsPublishStatus, Theme* … |
| Http/Requests | 20 | 842 | Hepsi `Ops/`: Store/UpdateSite, SwitchSiteChannel, PinSite, Bulk* (6), *Domain* (3), UpdateAccount* (3), UpdatePreferences, SitePublishStatus + 2 concern |
| Http/Middleware | 6 | 311 | ConvertOpsAjaxRedirect, EnsureSiteMailSignature, RestrictOpsByIp, SetOpsLocale, VerifyCoolifyWebhook, VerifyGitHubWebhook |
| Policies | 4 | 191 | SitePolicy, ThemePolicy, ThemeGitConnectionPolicy, CoolifyConnectionPolicy |
| Mail | 2 | 69 | PlatformOpsMail, PlatformTestMail |
| Notifications | 0 | — | Dizin yok; `User` yalnız `Notifiable` trait'i taşıyor (`app/Models/User.php:18`) |
| Providers | 2 | 76 | AppServiceProvider, FortifyServiceProvider |
| Rules | 1 | 76 | NotHeldByArchivedSite |
| Console/Commands | 8 | 1277 | §4.4 |

### 4.3 Job'lar

Komut: `grep -nE 'public int \$(tries|timeout|uniqueFor)|function backoff|implements' app/Jobs/*.php`.
Hiçbir job'da `WithoutOverlapping` middleware'i ve `failed()` metodu yok. Worker: `docker/supervisor/supervisord.conf:27` `queue:work --sleep=1 --tries=3 --max-time=3600` (job'daki `$tries` worker bayrağını ezer). Varsayılan bağlantı `database`, `retry_after` varsayılanı 90 s (`config/queue.php:16,43`).

| Job | tries | backoff | timeout | Tekillik (uniqueFor s) |
|-----|-------|---------|---------|------------------------|
| CheckSiteHealthJob | 1 | — | 30 | ShouldBeUnique (240) |
| ConfigureSiteMailJob | 1 | — | 60 | UniqueUntilProcessing (120) |
| DiagnoseDeploymentJob | 1 | — | 90 | ShouldBeUnique (120) |
| DispatchDeskronPushJob | 1 | — | 60 | — |
| DispatchPlatformMailPushJob | 1 | — | 60 | — |
| DispatchSiteHealthChecksJob | 1 | — | 60 | — |
| InspectSiteAppHealthJob | 1 | — | 45 | ShouldBeUnique (240) |
| PollDeploymentJob | 1 | `RetryAfter::backoff()` ile kendini yeniden kuyruğa atar (`:144`) | 30 | — |
| ProcessOpsBackgroundJob | 1 | — | 300 | — |
| ProvisionSiteJob | 1 | — | 120 | ShouldBeUnique (3600) |
| PushDeskronJob | **4** | `backoff()` dizi (`:33`) | 30 | UniqueUntilProcessing (120) |
| PushPlatformMailJob | 1 | — | 30 | UniqueUntilProcessing (120) |
| ReconcileDeskronPushJob | 1 | — | **900** | — |
| SwitchSiteChannelJob | 1 | — | 120 | ShouldBeUnique (3600) |
| SyncCoolifyEnvCatalogJob | **3** | — | — (varsayılan) | — |
| ThemeInstallJob | 1 | — | 120 | ShouldBeUnique (300) |
| ThemeSyncAfterDeployJob | 1 | — | 120 | ShouldBeUnique (120) |
| ThemeUpdateJob | 1 | — | 120 | ShouldBeUnique (300) |

Dalga 1'e girdi (Doğrulanmadı): `ProcessOpsBackgroundJob` 300 s ve `ReconcileDeskronPushJob` 900 s timeout, `retry_after` 90 s varsayılanından büyük → `database` kuyruğunda uzun işin ikinci worker tarafından yeniden alınma riski. Doğrulama: canlı `DB_QUEUE_RETRY_AFTER` değeri (env; bu dokümana yazılmaz).

### 4.4 Artisan komutları

| İmza | Açıklama | Dosya |
|------|----------|-------|
| `ops:heal-throttled-deploys {--dry-run} {--limit=200}` | Rate limit yüzünden `failed` yazılan deploy'ları yeniden okur | `HealThrottledDeploysCommand.php:20` |
| `ops:import-coolify-apps {--apply} {--tag=}` | Coolify müşteri app'lerini `sites`'a alır (varsayılan dry-run) | `ImportCoolifyAppsCommand.php:14` |
| `ops:snapshot-fleet` | Günlük fleet özet sayıları (Sites trend) | `SnapshotFleetCommand.php:14` |
| `ops:sync-env-catalog {--channel=}` | CMS `.env.production.example`'dan kanal başına env kataloğu | `SyncCoolifyEnvCatalogCommand.php:12` |
| `ops:sync-theme-catalog` | Bağlı GitHub hesaplarından tema katalog senkronu | `SyncThemeCatalogCommand.php:12` |

Ayrıca `routes/console.php:9` iskelet `inspire` closure komutu.

### 4.5 Schedule

Komut: `cat routes/console.php`. Çalıştırıcı: `docker/supervisor/supervisord.conf:37` `schedule:work`.

| Ad | İş | Sıklık | withoutOverlapping | onOneServer |
|----|----|--------|--------------------|-------------|
| `ops-site-agent-health` | `DispatchSiteHealthChecksJob` | `*/N` dk, N=`ops.agent.poll_minutes` 5–15'e sıkıştırılmış (`:13-17`) | evet | **hayır** |
| `ops-sync-env-catalog` | `ops:sync-env-catalog` | saatlik (`:24`) | evet | hayır |
| `ops-deskron-push-reconcile` | `ReconcileDeskronPushJob` | saatlik (`:32`) | evet | hayır |
| `ops-snapshot-fleet` | `ops:snapshot-fleet` | her gün 23:50, `app.timezone` (`:39`) | evet | hayır |

Zamanlanmamış: Coolify envanter senkronu, tema katalog senkronu, live probe, `ops:heal-throttled-deploys`, deployment reconcile, prune.

### 4.6 Modeller ve tablolar

Komut: `ls app/Models`; `grep -nF 'protected $table'` (yalnız 2 açık tablo adı, diğerleri konvansiyon).
Hiçbir modelde `Prunable`/`MassPrunable` yok (`grep -rn Prunable app` boş).

| Model | Tablo | Model | Tablo |
|-------|-------|-------|-------|
| AuditLog | audit_logs | OpsBackgroundJob | ops_background_jobs |
| CloudflareDnsDefault | cloudflare_dns_defaults | PlatformMailSetting | platform_mail_settings |
| CloudflareSetting | cloudflare_settings | Site (SoftDeletes) | sites |
| CoolifyConnection | coolify_connections | SiteDomain | site_domains |
| CoolifyEnvCatalogSource | coolify_env_catalog_sources | SiteMailBinding | site_mail_bindings |
| CoolifyEnvDefault | coolify_env_defaults | SiteMailboxRequest | site_mailbox_requests |
| CoolifyEnvironment | coolify_environments | SiteThemeInstallation | site_theme_installations |
| CoolifyGitSource | coolify_git_sources | Theme | themes |
| CoolifyProjectRecord | coolify_projects (`:10`) | ThemeGitConnection | theme_git_connections |
| CoolifyServer | coolify_servers | ThemeGitConnectionRepo | theme_git_connection_repos |
| CoolifySetting | coolify_settings | ThemeSiteAccess | theme_site_access (`:10`) |
| Deployment | deployments | User (HasRoles) | users |
| DeskronSetting | deskron_settings | FleetDailySnapshot | fleet_daily_snapshots |
| GithubSetting | github_settings | MailServer | mail_servers |

Modelsiz tablolar: `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `sessions`, `password_reset_tokens` + spatie `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions` (`2026_08_13_040455_create_permission_tables.php`). `coolify_env_defaults_next/_prev` yalnız `2026_09_15_000001` içinde geçici.

### 4.7 Migration'lar

Komut: `ls database/migrations | wc -l` → 56 (242ae15'te 41). `git diff --name-status 242ae15..HEAD -- database/migrations` → 15 eklendi, **1 değiştirildi**: `2026_09_10_180000_create_coolify_env_defaults_table.php` (yayınlanmış migration düzenlenmiş). Doğrulanmadı: canlıda tekrar koşmaz; temiz kurulumda farklı şema üretip üretmediği `git diff 242ae15..HEAD -- <dosya>` ile Dalga 1 veri ajanınca incelenmeli.
Yeni tablolar: `deskron_settings`, `fleet_daily_snapshots` (env catalog kaynak tablosu `2026_09_15_000001` içinde).

Seeder: `DatabaseSeeder`, `OpsAdminSeeder`, `RoleSeeder`.

---

## 5. Dış entegrasyonlar

Komut: `grep -nE 'public function [a-zA-Z]+' <client>` (constructor hariç ad sayısı).

| Entegrasyon | Client | Public metot | Satır | Diğer sınıflar |
|-------------|--------|--------------|-------|----------------|
| Coolify | `Services/Coolify/CoolifyClient.php` | 27 (listApps, getApp, listServers/Projects/Environments, listGithubApps, listPrivateKeys, createComposeApp, patchApplication, updateEnvs/listEnvs/deleteEnv/upsertEnvOnService, setDomains, updateBranch, start/stop/restartApplication, getApplicationLogs, deploy, getDeployment, listAppDeployments, listRunningDeployments, cancelDeployment, deleteApplication, listStorages) | 697 | CoolifyApplicationService, RateGuard, DeployGate, `Dto/*` |
| Cloudflare | `Services/Cloudflare/CloudflareClient.php` | 14 (verifyToken, list/get/create/deleteZone, listAllZones, DNS CRUD, probeZoneCreate, probeDnsCreate, rawGet) | 239 | CloudflareZoneService (637 satır) |
| GitHub App | `Services/GitHub/GitHubAppClient.php` | 14 (convertAppManifest, getInstallation, listAppInstallations, list*Repos, fetchThemeManifest, fetchJsonFile, fetchTextFile, latestCommitSha, mintCloneToken, installationAccessTokenFor, themeIdFromRepo) | 539 | GitHubAppJwt, GitHubWebhookHandler, DeamonGitConnectionService |
| Hostinger Mail | `Services/Hostinger/HostingerMailClient.php` | 7 (listOrders, findOrder, findOrderByDomain, listMailboxes, createMailbox, changePassword, deleteMailbox) | 386 | — |
| Site agent (CMS) | `Services/Agent/SiteAgentClient.php` | 16 (health, setSiteStatus, setSiteName, listThemes, install/update/activateTheme, installThemeData, syncTheme, rollbackThemeSync, listAdmins, createAdmin, sendAdminPasswordInvite, resetAdminPassword, updateAdmin, deleteAdmin) | 693 | ControlPlaneAgentContract, `Support/ControlPlaneAgentSignature` |
| Site agent — mail | `Services/Mail/SiteMailConfigurer.php`, `PlatformMailConfigurer.php` | 4 / 3 | 221 / 161 | `/mail/configure`, `/platform-mail/configure` |
| Deskron (CMS agent üzerinden) | `Services/Deskron/DeskronConfigurer.php` | 1 | 106 | `/deskron/configure` |
| CMS → Plane (gelen) | `Internal/SiteMailProxyController` + `EnsureSiteMailSignature` | 6 route | 254 | Hostinger'a proxy |

Plane'in çağırdığı agent yolları (`grep -rhoE "'/internal/control/v1/[^']*" app`): `health`, `site/status`, `site/identity`, `admins`, `themes`, `themes/{install,update,activate,sync,sync-rollback,data-install}`, `mail/configure`, `platform-mail/configure`, `deskron/configure` = 14.

---

## 6. Audit action'ları

Yöntem (betik `%TEMP%/aud.php`, repo'ya yazılmadı): audit yazan dosyalarda (`auditLogs()->create`, `AuditLog::query()->create`, `->audit(` içerenler) PHP tokenizer ile `x.y` biçimli string literal'ler toplanır; lang anahtarları ve route adları elenir; ardından 10 yanlış pozitif (job tipleri `sites.bulk_*`, `themes.catalog_sync`, kolon adları `deployments.*`, `coolify.deployment` tip adı, `installations.site`, `sites.id`, `sites.theme_flash.*`) çıkarılır.

| Prefix | Benzersiz action | Örnek |
|--------|------------------|-------|
| `site.*` | 48 | `site.provision_*`, `site.channel_switch*`, `site.admin.*` (6), `site.domain_*`, `site.agent_secret_{injected,rotated}`, `site.deploy_auto_fix`, `site.core_theme_restart*`, `site.purged/restored` |
| `theme.*` | 22 | `theme.install_*`, `theme.update_*`, `theme.sync_*`, `theme.sync_rollback_*`, `theme.files_rollback_*`, `theme.smoke_failed`, `theme.access_*` |
| `mail.*` | 6 | `mail.mailbox_{created,deleted,password_reset,requested}`, `mail.mailbox_request_{fulfilled,rejected}` |
| `mail_server.*` | 4 | created / updated / deleted / tested |
| `platform_mail.*` | 2 | updated, site_override_updated |
| `deskron.*` | 1 | updated |
| **Toplam** | **83** | Aynı yöntem 242ae15'e (`git archive` → `%TEMP%`) uygulanınca **69** |

Audit yazan dosyalar: 25 (`grep -rlE "auditLogs\(\)->create|AuditLog::query\(\)->create|->audit\(" app`).
Audit izi bulunmayan yazma yüzeyleri (controller ve bu 25 dosya dışında): `CoolifyConnectionController`, `CoolifyInventoryController`, `CloudflareOpsController`, `SettingsController`, `GithubSettingsController`, `DeamonGitConnectionController`, `ThemeGitConnectionController`, `AccountController`, `PreferencesController`, `DomainController` (fleet bind/clear sweep), `OpsJobController` (Plane deploy cancel). Doğrulanmadı: servis zincirleri tek tek okunmadı; Dalga 1 güvenlik ajanı bu controller'ların çağırdığı servisleri okuyarak teyit etmeli.
`lang/en/ops.php` `activity.actions` altında yalnız 15 etiket var (`site.*` 11, `mail_server.*` 4); kalan action'lar `AuditLog::actionLabel()` geri dönüşüyle gösteriliyor (`app/Models/AuditLog.php:114`).

---

## 7. Testler

| Komut | Sonuç |
|-------|-------|
| `php artisan test --compact` | **1080 passed**, 6128 assertion, 572.01 s (duvar 9 dk 32 s). Başarısız: **yok** |
| `node --test tests/js/ops-contracts.test.js` | 22 test, 22 pass, 0 fail, 133 ms |

Test dosyaları (`find … -name '*Test.php'`); 242ae15'te 118:

| Dizin | Dosya | Dizin | Dosya |
|-------|-------|-------|-------|
| Feature/Ops | 64 | Feature/Coolify | 3 |
| Feature/Sites | 32 | Feature/Webhooks | 2 |
| Feature/Themes | 8 | Feature/Auth, Import, Security | 1'er |
| Feature/Mail | 5 | Feature (kök) | 1 (`HealthAndConfigTest`) |
| Feature/Agent | 4 | Unit | 33 (Coolify 9, Agent 4, Models 4, Support 4, Sites 3, diğer 9) |

Fixture: yalnız `tests/Fixtures/deamon/env-production.example`. Agent kontratı JSON fixture'ı yok. CI yok (`.github/` dizini yok).

---

## 8. Frontend

### 8.1 Blade (100 dosya)

Komut: `find resources/views -name '*.blade.php' | awk -F/ …`

| Alan | Dosya | Alan | Dosya |
|------|-------|------|-------|
| ops/sites | 29 | ops/settings | 5 |
| ops/coolify | 9 | ops/mail-servers | 5 |
| ops/cloudflare | 9 | ops/activity | 4 |
| components/ops | 8 | ops/dashboard | 3 |
| ops/partials | 7 | ops/{platform-mail, domains, deployments, account} | 2'şer |
| ops/themes | 6 | layouts, mail | 2'şer |
| ops/{fleet, deskron}, auth | 1'er | | |

### 8.2 public/css + public/js (11 652 satır, build adımı yok)

Komut: `find public/css public/js -type f -exec wc -l {} +`

| Dosya | Satır | Dosya | Satır |
|-------|-------|-------|-------|
| css/ops-ui.css | 2204 | js/ops-palette.js | 284 |
| css/plane-refresh.css | 2038 | js/sites-filters.js | 254 |
| css/ops.css | 1599 | js/ops-shortcuts.js | 250 |
| js/ops-ui.js | 1505 | js/ops-confirm.js | 195 |
| js/ops-jobs.js | 1054 | js/ops-coolify-form.js | 187 |
| js/ops-list.js | 484 | js/ops-async.js | 161 |
| js/ops-contracts.js | 480 | js/sites-cloudflare.js | 147 |
| js/ops-app-health.js | 355 | js/sites-admins.js | 121 |
| js/ops-site-tabs.js | 98 | js/sites-aliases.js, ops-lazy-panels.js | 86'şar |
| js/sites-landing.js | 35 | js/theme-git.js | 29 |

Üç CSS dosyası (5841 satır) — yükleme sırası/çakışması Dalga 1 UI ajanınca `layouts/ops.blade.php` üzerinden doğrulanmalı.

### 8.3 Lang

Komut: PHP ile iki dili flatten edip `array_diff_key`. 15 dosya her iki dilde; en 2286 = tr 2286; eksik 0 (iki yönde). En büyük: `sites.php` 776, `ops.php` 275, `themes.php` 189, `cloudflare.php` 167, `coolify.php` 166, `deploy_diagnosis.php` 157. Blade içi hardcoded metin taranmadı (Doğrulanmadı).

---

## 9. Config

Komut: bootstrap edilmiş uygulamada `array_keys(config('ops'))` (değer yazılmadı).

| `config/ops.php` grubu | Alt anahtarlar |
|------------------------|----------------|
| channels, locales | liste |
| channel_switch | main_minimum_version, downgrade_requires_confirm, upgrade_requires_version_gate |
| deamon | repository, compose_file |
| themes | org, repo_prefix, auto_update_default, fanout_concurrency, manifest_paths, smoke |
| github | api_base, web_base, app_name, timeout, token, app_id, installation_id, private_key, webhook_secret |
| access | ip_allowlist |
| app_health / fleet / jobs / activity | counts_ttl / failed_deploy_window_hours, attention_limit / poll / export_limit |
| agent | skew_seconds, timeout_seconds, poll_minutes, stale_after_minutes, core_theme_auto_restart, core_theme_restart_window_hours, retry, 7 yol anahtarı, mail |
| hostinger / cloudflare | api_base, timeout, webmail_url / api_base, timeout, default_origin_ipv4, wildcard_domain |
| coolify | base_url, api_token, default_project_uuid, default_server_uuid, webhook_secret, timeout, auto_rebind_domains, deploy, rate, retry, bulk |
| provision | environment_name, poll_*, log_excerpt_bytes |
| diagnosis | enabled, auto_fix, log_lines, container_logs_bytes, repeat_window_hours |
| import | customer_repo_needle, exclude_repo_needles, preferred_build_pack, allowed_build_packs |

Diğer: `config/fortify.php:162` `features => []`; `config/session.php:35` `lifetime` (env varsayılanı 120; `.env.example:36` aynı); `config/services.php` yalnız iskelet (postmark, resend, ses, slack); `config/permission.php`, `config/queue.php`.

---

## 10. Docker / deploy

| Dosya | Satır | İçerik |
|-------|-------|--------|
| `Dockerfile` | 103 | PHP-FPM + nginx imajı |
| `docker-compose.yml` | 90 | yerel |
| `docker-compose.coolify.yml` | 93 | servisler `app`, `mysql`, `redis`; volume `plane_storage`, `plane_mysql`, `plane_redis` |
| `docker/entrypoint.sh` | — | başlangıç |
| `docker/supervisor/supervisord.conf` | — | `queue:work --sleep=1 --tries=3 --max-time=3600` (`:27`), `schedule:work` (`:37`) |
| `docker/nginx/default.conf`, `docker/php/conf.d/plane.ini`, `docker/php-fpm/www.conf` | — | web katmanı |

Repo kökü: `_tmp/` gitignore'da (`.gitignore:13-14`) ve izlenmiyor (`git ls-files _tmp` → 0); `tools/coolify-spike/` 3 dosya izleniyor. `.gitattributes` `* text=auto eol=lf` var; çalışma ağacında 79 dosya CRLF (`git ls-files --eol | awk '$2 ~ /crlf/'`), index'te 0; `core.autocrlf=true`.

---

## 11. Modül → dosya haritası (Dalga 1 sahiplik referansı)

Kural: her dosyanın tek sahibi var; "paylaşımlı" notu diğer modülün yalnız okuyacağını belirtir. Kısaltma: `C/` = `app/Http/Controllers/Ops/`, `S/` = `app/Services/`, `V/` = `resources/views/`, `T/` = `tests/`.

### M1 — Sites (CRUD, liste, detay kabuğu, lifecycle)
- Controller: `C/SiteController`, `C/SiteDetailController`, `C/SiteListPreferencesController`, `C/Concerns/LoadsSiteOpsContext`
- Route: `routes/ops/sites.php`
- Servis: `S/Sites/{SiteLifecycle,SiteLifecycleException,SiteAttacher,SiteListSummary,SiteFilterVerdict}`, `app/Support/Lists/{SiteListColumns,SiteListView,SiteSavedViews}`, `app/Support/IdentityMark`
- Model/Request/Policy/Rule: `Site`; `app/Http/Requests/Ops/{Store,Update}SiteRequest`, `Bulk{SiteIds,PinSite,AutoDeploySite}Request`, `Concerns/ValidatesCoolifySiteTargets`; `app/Policies/SitePolicy`; `app/Rules/NotHeldByArchivedSite`
- View/JS/lang: `V/ops/sites/{index,show,create,edit,archived,_form,_region,_cell,_segment,_view-switch,_columns-picker,_saved-views-form,_sort-header,_filter-hidden,_header-actions}.blade.php`, `public/js/sites-filters.js`, `lang/*/sites.php`, `lang/*/site_ops.php`
- Test: `T/Feature/Sites/Site{Archive,Bulk*,DangerArchiveLink,DeletePending,FleetSearch,FormSubmit,FreshnessBadge,ListCellStates,MetricIcons,RowFixMenu,ListAppHealthCountCost}Test`, `T/Feature/Ops/{SiteCrud,SiteDetail,SiteList*,SiteSavedViews,SitesListQuickActions}Test`, `T/Unit/Models/SiteTest`, `T/Unit/Enums/SiteStatusTest`

### M2 — Coolify & Deploy (+ env kataloğu, Settings)
- Controller: `C/CoolifyConnectionController`, `C/CoolifyInventoryController`, `C/SiteCoolifyOpsController`, `C/DeploymentShowController`, `C/DeploymentDiagnosisController`, `C/SettingsController`, `C/DeamonGitConnectionController`, `app/Http/Controllers/Webhooks/CoolifyWebhookController`
- Route: `routes/ops/coolify.php`, `routes/webhooks.php` (coolify), `routes/web.php` settings bloğu
- Servis: `S/Coolify/**` (Dto, EnvCatalog dahil), `S/Sites/{SiteProvisioner,SiteProvisionException,ChannelSwitcher,ChannelSwitchException,ChannelEnvironmentMap,ComposePackMigrator,ComposePackException,CoolifyDeploySettings,DeploymentFailureText}`, `S/Sites/Diagnosis/*`, `S/Ops/OpsCoolifyDeployQueue`, `S/GitHub/DeamonGitConnectionService` (client M4'te)
- Job/Komut: `PollDeploymentJob`, `DiagnoseDeploymentJob`, `ProvisionSiteJob`, `SwitchSiteChannelJob`, `SyncCoolifyEnvCatalogJob`; `ops:import-coolify-apps` (+`app/Console/Commands/ImportCoolifyApps/*`), `ops:heal-throttled-deploys`, `ops:sync-env-catalog`
- Model/Policy/Middleware/Support/Request: `Coolify{Connection,EnvCatalogSource,EnvDefault,Environment,GitSource,ProjectRecord,Server,Setting}`, `Deployment`; `CoolifyConnectionPolicy`; `VerifyCoolifyWebhook`; `app/Support/{CoolifyWebhookSignature,RetryAfter,MysqlWallClockShift}`, `app/Support/Lists/CoolifyInventoryQuery`; `SwitchSiteChannelRequest`, `PinSiteRequest`
- View/JS/lang: `V/ops/coolify/**`, `V/ops/deployments/**`, `V/ops/settings/**` (github partial hariç), `V/ops/sites/{_coolify-ops,_coolify-ops-body,_channel-switch}`, `public/js/ops-coolify-form.js`, `lang/*/{coolify,deploy_diagnosis,settings}.php`
- Test: `T/Feature/Coolify/*`, `T/Feature/Import/*`, `T/Feature/Webhooks/CoolifyWebhookTest`, `T/Feature/Sites/{ChannelSwitch,ComposePackMigrate,CoolifyDeploySettings,CoolifySiteSync,DeploymentDiagnosis,PollDeploymentThrottle,ProvisionSite,SiteLifecycle}Test`, `T/Feature/Ops/{Coolify*,DeploymentShow,BulkDeployWaitingBucket,FailedDeployFilter,HealThrottledDeploysCommand,Settings*,DeamonGitConnection}Test`, `T/Unit/Coolify/*`, `T/Unit/Import/*`, `T/Unit/Sites/*`, `T/Unit/Models/{CoolifyApplicationUiUrl,DeploymentDurationLabel}Test`

### M3 — Agent & Health / Fleet
- Controller: `C/FleetController`, `C/SiteAppHealthController`, `C/SitePublishStatusController`, `C/SiteAdminController`
- Servis: `S/Agent/**`, `S/Fleet/FleetDashboardKpis`, `S/Sites/{SiteAppHealth*,SiteAgentSecretInjector,SiteAgentSecretSweep,SiteIdentityPusher,SiteLiveProbe,SitePublishStateUpdater,SitePublishException}`, `app/Support/{ControlPlaneAgentSignature,OpsFreshness}`, `app/Support/Lists/FleetAttentionQuery`
- Job/Komut: `CheckSiteHealthJob`, `DispatchSiteHealthChecksJob`, `InspectSiteAppHealthJob`; `ops:snapshot-fleet`
- Model/Request: `FleetDailySnapshot`; `Bulk{AppHealthFix,SitePublishStatus}Request`, `SitePublishStatusRequest`
- View/JS/lang: `V/ops/fleet/**`, `V/ops/dashboard/**`, `V/ops/sites/{_agent-health,_app-health,_admins,_admins_body,_publish-state}`, `V/components/ops/{metric,metrics,metric-trend}`, `public/js/{ops-app-health,sites-admins}.js`, `lang/*/fleet.php`
- Test: `T/Feature/Agent/*`, `T/Feature/Ops/{Fleet*,AgentSecretFleet,HealthAppFilter,SitePublishState}Test`, `T/Feature/Sites/{AgentSecretInject,SiteAppHealth,SiteAgentReasonLabel,SiteLiveSync,FleetSnapshotTrend}Test`, `T/Unit/Agent/*` (ThemeAgentResult hariç), `T/Unit/Services/SiteLiveProbeTest`

### M4 — Themes & GitHub
- Controller: `C/ThemeController`, `C/ThemeGitConnectionController`, `C/SiteThemeController`, `C/GithubSettingsController`, `app/Http/Controllers/Webhooks/GitHubWebhookController`
- Route: `routes/ops/themes.php`, `routes/webhooks.php` (github)
- Servis: `S/Themes/**`, `S/GitHub/**` (DeamonGitConnectionService hariç → M2), `S/Agent/{CoreThemeHealer,ThemeAgentResult}` (M3 ile paylaşımlı), `app/Support/{GitHubWebhookSignature,ThemeGitOAuthState}`
- Job/Komut: `ThemeInstallJob`, `ThemeUpdateJob`, `ThemeSyncAfterDeployJob`; `ops:sync-theme-catalog`
- Model/Policy/Middleware: `Theme`, `ThemeGitConnection`, `ThemeGitConnectionRepo`, `ThemeSiteAccess`, `SiteThemeInstallation`, `GithubSetting`; `ThemePolicy`, `ThemeGitConnectionPolicy`; `VerifyGitHubWebhook`
- View/JS/lang: `V/ops/themes/**`, `V/ops/sites/_themes`, `V/ops/settings/partials/github`, `V/ops/settings/deamon-git/manifest`, `public/js/theme-git.js`, `lang/*/themes.php`
- Test: `T/Feature/Themes/*`, `T/Feature/Webhooks/GitHubWebhookTest`, `T/Feature/Ops/{GithubSettings,SiteThemeFilter}Test`, `T/Unit/GitHub/*`, `T/Unit/Themes/*`, `T/Unit/Agent/ThemeAgentResultTest`

### M5 — Domains & Cloudflare
- Controller: `C/DomainController`, `C/CloudflareOpsController`, `C/SiteDomainController`, `C/SiteCloudflareController`
- Route: `routes/ops/domains.php`, `routes/ops/cloudflare.php`
- Servis: `S/Cloudflare/**`, `S/Domains/**`, `S/Sites/{SiteDomainReconciler,SiteDomainSync,SitePrimaryDomain,SiteLanding}`
- Model/Request: `SiteDomain`, `CloudflareSetting`, `CloudflareDnsDefault`; `{Store,Update}FleetDomainRequest`, `StoreSiteDomainRequest`, `BulkDomainIdsRequest`, `Concerns/ValidatesSiteDomains`
- View/JS/lang: `V/ops/domains/**`, `V/ops/cloudflare/**`, `V/ops/sites/{_domains,_cloudflare,_landing}`, `public/js/{sites-cloudflare,sites-aliases,sites-landing}.js`, `lang/*/{domains,cloudflare}.php`
- Test: `T/Feature/Ops/{Domain*,Cloudflare*}Test`, `T/Feature/Sites/{SiteCloudflareZone,SiteDomainReconcile,SiteLandingFlow,SiteAliasRows,ProvisionSiteCloudflare}Test`, `T/Unit/Cloudflare/*`

### M6 — Mail, Hostinger, Deskron, Platform mail
- Controller: `C/MailServerOpsController`, `C/PlatformMailSettingsController`, `C/PlatformMailUnsubscribeController`, `C/DeskronSettingsController`, `app/Http/Controllers/Internal/SiteMailProxyController`
- Route: `routes/ops/mail-servers.php` (platform-mail dahil), `routes/ops/deskron.php`, `routes/internal-site.php`, `routes/web.php:15-20` (unsubscribe)
- Servis: `S/Mail/**`, `S/Hostinger/**`, `S/Deskron/**`
- Job: `ConfigureSiteMailJob`, `DispatchPlatformMailPushJob`, `PushPlatformMailJob`, `DispatchDeskronPushJob`, `PushDeskronJob`, `ReconcileDeskronPushJob`
- Model/Middleware/Mail: `MailServer`, `SiteMailBinding`, `SiteMailboxRequest`, `PlatformMailSetting`, `DeskronSetting`; `EnsureSiteMailSignature`; `app/Mail/*`
- View/lang: `V/ops/mail-servers/**`, `V/ops/platform-mail/**`, `V/ops/deskron/**`, `V/mail/**`, `V/ops/sites/{_mail,_platform-mail}`, `V/ops/partials/platform-mail-chip`, `lang/*/{mail,platform_mail,deskron}.php`
- Test: `T/Feature/Mail/*`, `T/Feature/Ops/{MailServerOps,MailListEmptyStates,PlatformMail*,Deskron*,ReconcileDeskronPush}Test`

### M7 — Auth / Account / Güvenlik çatısı
- Controller: `C/AccountController`, `C/PreferencesController`; Fortify (`app/Providers/FortifyServiceProvider.php`, `config/fortify.php`)
- Middleware/Support: `RestrictOpsByIp`, `SetOpsLocale`; `app/Support/{ProductionDebugGuard,SecretRedactor,OpsAppearance,PublicAppUrl}`; `bootstrap/app.php`
- Model/Enum/Request/Seeder/Config: `User`; `OpsRole`, `Appearance`; `UpdateAccount{Avatar,Password,Profile}Request`, `UpdatePreferencesRequest`; `database/seeders/{OpsAdminSeeder,RoleSeeder}`; `config/{permission,session,auth}.php`
- View/lang: `V/auth/**`, `V/layouts/guest`, `V/ops/account/**`, `lang/*/{account,auth}.php`
- Test: `T/Feature/Auth/*`, `T/Feature/Security/*`, `T/Feature/HealthAndConfigTest`, `T/Feature/Ops/{AccountPage,PreferencesAppearance,RoleGate}Test`, `T/Unit/{OpsRoleTest,AppTimezoneTest}`, `T/Unit/Support/PublicAppUrlForAgentsTest`
- Not: Settings sayfası (`SettingsController`) M2'de; M7 yalnız erişim/oturum/hesap

### M8 — Activity / Jobs / Audit
- Controller: `C/ActivityController`, `C/OpsJobController`, `C/Concerns/QueuesOpsJob`
- Servis/Support: `S/Ops/{OpsJobManager,OpsJobRunner,BulkResultSummary,PacedFanout}`, `app/Support/Ops/Activity{Feed,Filters,Row}`
- Job/Model: `ProcessOpsBackgroundJob`; `AuditLog`, `OpsBackgroundJob`
- View/JS: `V/ops/activity/**`, `V/ops/partials/jobs-widget`, `public/js/ops-jobs.js`
- Test: `T/Feature/Ops/{ActivityFeed,OpsBackgroundJob,OpsBulkSerialization,TruthfulJobCompletion,JobsFailedFilter,JobsPollPressure,DeploymentPollWidget,BulkThrottleResilience,DeployQueueStanding}Test`, `T/Unit/Models/AuditLogTest`

### M9 — UI shell
- Controller/Middleware: `C/OpsPaletteController`; `ConvertOpsAjaxRedirect`
- Servis/Support: `S/Ops/PaletteSearch`, `app/Support/Ops/{PaletteFilters,SettingsJump}`, `app/Support/Lists/ListFragment`
- View: `V/layouts/ops`, `V/components/ops/{copy-id,freshness,git-icon,search,segment}`, `V/ops/partials/{confirm-modal,filter-chips,pagination,palette,shortcuts-overlay}`
- Asset/lang: `public/css/*` (3), `public/js/{ops-ui,ops-list,ops-contracts,ops-async,ops-confirm,ops-palette,ops-shortcuts,ops-lazy-panels,ops-site-tabs}.js`, `lang/*/ops.php`
- Test: `tests/js/ops-contracts.test.js`, `T/Feature/Ops/{ConfirmMatrix,KeyboardShortcuts,NavPages,PaletteSearch,OpsListFragment,AsyncRedirectErrorBag,WorkspacePattern,*EmptyStates}Test`, `T/Unit/Lang/*`, `T/Unit/Support/{IdentityMark,MysqlWallClockShift,OpsFreshness}Test`

---

## 12. Önceki raporla fark

| Ölçü | 2026-09-14 raporu | Bugün | Fark | Not |
|------|-------------------|-------|------|-----|
| Route | 184 | 203 | +19 | Deamon Git 5, Deskron 3, archive/restore, domain promote/remove vb. |
| Ops controller | 29 | 31 | +2 | `git ls-tree 242ae15` → 28 üst düzey dosya; yeni 3: DeamonGit, DeploymentDiagnosis, Deskron |
| Servis | ~90 | 126 | +36 | 242ae15'te gerçek sayı 108 (`ls-tree`); rapor eksik saymış |
| Job | 13 | 18 | +5 | 242ae15'te 12 dosya; yeni 6: ConfigureSiteMail, DiagnoseDeployment, DispatchDeskronPush, PushDeskron, ReconcileDeskronPush, SyncCoolifyEnvCatalog |
| Komut | 4 | 5 | +1 | `ops:snapshot-fleet` |
| Schedule | 1 | 4 | +3 | env catalog saatlik, deskron reconcile saatlik, fleet snapshot günlük |
| Audit action | ~138 | 83 | — | ~138 aynı yöntemle üretilemedi; tokenizer yöntemi 242ae15'te 69 → gerçek artış +14 |
| PHPUnit | 856 / 4658 | 1080 / 6128 | +224 / +1470 | yeşil |
| Node test | (sayı yok) | 22 | — | |
| Test dosyası | 118 | 155 | +37 | |
| Migration | 41 | 56 | +15 | 1 eski migration değiştirilmiş (§4.7) |

---

## 13. `git log 242ae15..HEAD`

Komut: `git log --format=%s 242ae15..HEAD | sed …`; `git diff --shortstat 242ae15..HEAD`. **66 commit** (1 merge), `290 files changed, 21639 insertions(+), 2108 deletions(-)`.

| Tür | Adet | Scope | Adet |
|-----|------|-------|------|
| fix | 31 | sites | 23 (feat 9, fix 10, docs 2, test 1, perf 1) |
| feat | 23 | ui | 14 (fix 11, feat 2, perf 1) |
| docs | 5 | themes | 8 (feat 4, fix 3, docs 1) |
| perf | 2 | mail | 6 (fix 4, revert 1, docs 1) |
| test / revert | 1 / 1 | deskron 2, plane 2; app-health, domains, deploy, deamon-git, coolify, agent 1'er | |
| türsüz | 3 | `5f6f45f` (merge), `d553413`, `9c4a115` | |

Öne çıkan yeni yetenekler:

| Yetenek | Commit |
|---------|--------|
| Kanal başına env kataloğu (CMS `.env.production.example`) + Settings → Deamon Git | `4095c20`, `9d36573` |
| Her apex'e domain, kendi Cloudflare zone'u, bind sonrası redeploy | `f256f80` |
| CMS mail configure kuyrukta, durum + yeniden gönder | `a868a01` |
| Deskron uygulaması Plane'de saklanıp agent ile push; saatlik reconcile | `2dc005b`, `aab3e73`, `9c4a115`, `d553413` |
| Arşiv sayfası (restore / hard delete), toplu hard delete arka plan job'u | `72ba11e`, `4a9723e` |
| Domain kaldır / alias'ı primary yap; purge ve alias düşürme Cloudflare kayıtlarını bırakır | `25a88d1`, `dd5ffa1`, `5a28966`, `03f50b3`, `3e6e28d` |
| Tema: rollback, sync site düzenlemelerini korur, özelleştirilmiş dosya uyarısı, storefront smoke + stale core tema iyileştirme | `bffdfc9`, `7084a8c`, `155c548`, `16adae4`, `baa9010` |
| Her başarısız deploy için teşhis + tek güvenli otomatik düzeltme | `d804395` |
| Site adını agent ile CMS'e push | `baeb253` |
| Sites çalışma alanı: yeni/sıralanabilir kolonlar, tema/CMS sürümü/auto-deploy/sunucu/stale filtreleri, list/compact/card görünüm, satır hızlı aksiyonları, günlük fleet snapshot trendi | `1c6738c`, `29bc2ee`, `8fbf4b9`, `758005c`, `13ea389`, `8dfec88` |
| Workspace deseni Fleet/Themes/Domains'e | `7a877f4` |
| Admins sekmesi ve Coolify kartı sayfa sonrası yüklenir | `1dc1e59`, `fd31a20` |
| Poll-timeout ve yarış kaynaklı zehirli deploy satırlarını iyileştirme | `6983901`, `e6a00c7` |

---

## 14. Doğrulanmayan noktalar

| Konu | Neden | Nasıl doğrulanır |
|------|-------|------------------|
| Audit yazmayan yüzeylerin kesin listesi | Servis zincirleri tek tek okunmadı | §6'daki controller'ları ve çağırdıkları servisleri oku |
| `retry_after` < job timeout riski | Canlı env okunmadı | Canlıda `DB_QUEUE_RETRY_AFTER` / `QUEUE_CONNECTION` |
| `storage/{path}` PUT route'u | Laravel 12 local serve; imzalı URL ile korunduğu varsayılıyor, kullanım bilinmiyor | `grep -rn temporaryUploadUrl app`; `config/filesystems.php:36` gerekli mi |
| Değiştirilmiş eski migration etkisi | Diff okunmadı | `git diff 242ae15..HEAD -- database/migrations/2026_09_10_180000_*` |
| Üç CSS dosyasının yükleme sırası | Layout okunmadı | `resources/views/layouts/ops.blade.php` |
| Blade'de hardcoded metin | Taranmadı | Blade'de `__(` dışı düz metin taraması |
