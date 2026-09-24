# 02 — Mimari ve kod sağlığı

**Tarih:** 2026-09-24 · **HEAD:** 8484d37 · **Hazırlayan:** S1 — Mimari ve kod sağlığı denetçisi (Dalga 1)
**Kapsam:** `app/` (Http/Controllers/Ops, Services, Support, Jobs, Models, Enums), `tests/`, `composer.json`/`composer.lock`, `Dockerfile`, `bootstrap/app.php`; katman sınırları, büyük sınıflar, tekrar, sorgu riskleri, hata yönetimi, test boşlukları, teknik borç, bağımlılık · **Kapsam dışı:** güvenlik açığı avı (S-güvenlik), kuyruk/zamanlayıcı ayrıntısı (S10), UI/CSS/JS kalitesi, CMS kodu, canlı sistemler

Kaynaklar: [01-envanter.md](01-envanter.md) (sayılar, §11 modül haritası), [15-onceki-inceleme-durum-takibi.md](15-onceki-inceleme-durum-takibi.md), önceki rapor [../../plans/2026-09-14-plane-review-and-proposals.md](../../plans/2026-09-14-plane-review-and-proposals.md).
Ölçüm betikleri yalnız `%TEMP%\s1-*` altına yazıldı; repo'da değişiklik yok. Tam test paketi koşulmadı; yalnız `php artisan test --filter=SiteCrudTest --profile` (21 test, 8.56 s).

---

## Özet

- **Controller → service sınırı büyük ölçüde korunuyor, ama iki "orkestrasyon controller'ı" var.** Hiçbir controller doğrudan `Http::` çağırmıyor (`grep -rn "Http::" app/Http` boş). Buna karşın `SiteController::update` (`SiteController.php:964-1084`) transaction'dan sonra 7 yan etkiyi (DNS bırakma, alias DNS, bind+redeploy, agent base URL, mail binding, kimlik push) kendisi sıralıyor; bu akış job/otomasyondan çağrılamaz. `SiteController` 1051 → **1355** satır (Önceki: E6, Yapılmadı/kötüleşti).
- **Toplu işlemler iki kez yazılmış.** JSON olmayan yol controller'da, JSON yolu `OpsJobRunner`'da; `bulkSync` ve `bulkChannel` controller döngüsü `PacedFanout` kullanmıyor (`SiteCoolifyOpsController.php:213-221`, `:376-390`) ve `sitesFromBulk` 4 controller'da farklılaşmış kopyalar halinde (S1-B03, S1-B04).
- **Sessiz yanlış durum riski: 41 `catch (Throwable)`, 11'i log/report olmadan yutuyor.** Örn. `SiteAppHealthInspector.php:330` env kataloğu okunamazsa beklenen anahtar listesini boşaltıp sağlığı "sorunsuz" gösterebilir; `ProcessOpsBackgroundJob.php:83-85` hatayı `report()` etmeden yalnız satıra yazıyor; hiçbir job'da `failed()` yok (S1-B08, S1-B09).
- **Kopyalanmış durum eşlemesi:** Coolify → `DeploymentStatus` eşlemesi 3 kopya (`CoolifyDeploymentSync.php:111`, `ChannelSwitcher.php:313`, `SiteProvisioner.php:371`), `markFailed` ~50 satırlık ikiz (`ChannelSwitcher.php:250` ↔ `SiteProvisioner.php:303`); imzalı agent POST deseni 9 kopya (S1-B05, S1-B07).
- **Otonomi için en değerli adımlar:** tek toplu-işlem yolu + sistem aktörü (`ChannelSwitcher::start` `User $actor` zorunlu, `ChannelSwitcher.php:49`; `OpsJobRunner::actor` aktörsüz job'da exception, `OpsJobRunner.php:400-407`), durum sahibi job'lara `failed()` + takılı satır süpürmesi, sessiz catch politikası (S1-O03, S1-O07, S1-O06, S1-O23).
- **Platform:** PHP 8.2 çalışma zamanı (`Dockerfile:41`), güvenlik desteği 2026-12-31'de bitiyor; statik analiz yok (lock'ta larastan/phpstan yok), CI yok (S1-B23).

---

## 1. Mevcut durum

### 1.1 Katman akışı

```mermaid
flowchart LR
  R[routes/ops/*.php] --> FR[FormRequest\n20 sınıf, authorize()]
  R --> C[Ops Controller\n31 + 2 trait]
  FR --> C
  C -->|expectsJson| Q[QueuesOpsJob → OpsJobManager::queue]
  Q --> PJ[ProcessOpsBackgroundJob\nCache::lock ops:coolify-bulk]
  PJ --> RUN[OpsJobRunner::run\n15 job tipi, match]
  C -->|form post| S[Services/*\n126 sınıf]
  RUN --> S
  S --> PF[PacedFanout / CoolifyRateGuard]
  S --> CL[Client'lar: CoolifyClient, SiteAgentClient,\nGitHubAppClient, CloudflareClient, HostingerMailClient]
  S -.doğrudan Http::.-> X[SiteMailConfigurer, PlatformMailConfigurer,\nDeskronConfigurer, SiteLiveProbe, ThemeSmokeCheck]
  S --> M[Models: Site 1250 satır]
  C -.auditLogs()->create 20 yer.-> M
  J[Jobs 18] --> S
```

- Yetki: route'ta `role:` yok; `authorize()`/FormRequest `authorize()` controller katmanında ([01 §2.3](01-envanter.md#23-middleware)). Servisler yetki kontrol etmez; iş kuralı korumaları (ör. kanal, kilit) serviste: `ChannelSwitcher.php:53-99` (`lockForUpdate`).
- Aktör sözleşmesi: servislerde 48 imza `?User $actor` + `?string $ip` alıyor (`grep -rnF '?User $actor' app/Services | wc -l`); istisna `ChannelSwitcher::start(User $actor)` (`:49`).
- Audit: 58 doğrudan `auditLogs()->create`/`AuditLog::query()->create` çağrısı, 25 dosyada; 4 ayrı özel `audit()` yardımcısı (`SiteMailProxyController.php:225`, `MailServerOpsController.php:244`, `SiteAdminController.php:241`, `CoolifyDeploySettings.php:298`).
- Global hata eşleme yok: `bootstrap/app.php:55-57` `withExceptions` boş.

### 1.2 Büyük sınıflar (top 20, controller + servis + job + model + support)

Komut: `find app -name '*.php' -exec wc -l {} + | sort -rn`; metot: `grep -cE '^\s*(public|protected|private)( static)? function'`.

| # | Dosya | Satır | Metot (public) | Sorumluluk | Test (doğrudan / dolaylı) |
|---|-------|-------|----------------|-----------|---------------------------|
| 1 | `Http/Controllers/Ops/SiteController.php` | 1355 | 37 (22) | Liste, CRUD, lifecycle, mail ×6, platform mail, agent secret, provision, kanal, health | Ad geçmiyor; `tests/Feature/Ops/SiteCrudTest.php`, `SiteList*`, `Feature/Sites/*` |
| 2 | `Models/Site.php` | 1250 | 66 (63) | İlişkiler + durum makinesi + sunum etiketleri + liste filtre scope'u + filo taraması | `tests/Unit/Models/SiteTest.php` |
| 3 | `Services/Themes/ThemeRolloutService.php` | 757 | 19 (10) | Tema install/update/sync/rollback | Ad geçmiyor; `Feature/Themes/*`, `GitHubWebhookTest` |
| 4 | `Services/Coolify/CoolifyClient.php` | 697 | 42 (28) | Coolify HTTP + retry + rate | `tests/Unit/Coolify/*` |
| 5 | `Services/Agent/SiteAgentClient.php` | 693 | 28 (17) | CMS agent 16 uç | `Feature/Agent/*` |
| 6 | `Services/Ops/OpsCoolifyDeployQueue.php` | 646 | 20 (10) | Coolify deploy kuyruğu widget'ı | `DeployQueueStandingTest` |
| 7 | `Services/Cloudflare/CloudflareZoneService.php` | 637 | 30 (15) | Zone/DNS orkestrasyonu | Ad geçmiyor; `Feature/Sites/SiteCloudflareZoneTest.php` |
| 8 | `Support/Lists/SiteSavedViews.php` | 583 | 24 (16) | Kayıtlı görünüm + filtre anahtarları | `SiteSavedViewsTest` |
| 9 | `Services/Sites/SiteProvisioner.php` | 576 | 22 (11) | Provision + poll + markFailed | `ProvisionSiteTest` |
| 10 | `Http/Controllers/Ops/SiteCoolifyOpsController.php` | 549 | 26 (22) | Coolify kartı, pin/deploy/auto-deploy, sync, live sync, bulk purge, bulk kanal | Ad geçmiyor; `SiteBulk*Test` |
| 11 | `Services/GitHub/GitHubAppClient.php` | 539 | — | GitHub App HTTP | `tests/Unit/GitHub/*` |
| 12 | `Console/Commands/ImportCoolifyApps/CoolifyFleetImporter.php` | 459 | — | Filo içe aktarma | `Feature/Import/*` |
| 13 | `Services/Sites/SiteAppHealthInspector.php` | 436 | — | App sağlık raporu | `SiteAppHealthTest` |
| 14 | `Http/Controllers/Ops/CloudflareOpsController.php` | 435 | 26 (20) | Cloudflare hesap/zone/DNS | Ad geçmiyor; `CloudflareDnsOpsTest`, `CloudflareSettingsTest` |
| 15 | `Services/Ops/OpsJobRunner.php` | 409 | 22 (1) | 15 job tipinin gövdesi | Ad geçmiyor; `OpsBackgroundJobTest`, `TruthfulJobCompletionTest` |
| 16 | `Http/Controllers/Ops/CoolifyConnectionController.php` | 409 | 21 (15) | Coolify bağlantı CRUD + test + sync | Ad geçmiyor; `Feature/Ops/Coolify*Test` |
| 17 | `Services/Hostinger/HostingerMailClient.php` | 386 | — | Hostinger HTTP | Ad geçmiyor; `Feature/Mail/*` |
| 18 | `Services/Sites/ChannelSwitcher.php` | 384 | — | Kanal geçişi + markFailed | `ChannelSwitchTest` |
| 19 | `Console/Commands/ImportCoolifyApps/CoolifyFleetClassifier.php` | 383 | — | Import sınıflandırma | `tests/Unit/Import/*` |
| 20 | `Support/Ops/ActivityFeed.php` | 377 | — | Activity birleşik akış | `ActivityFeedTest` |

Büyük view'lar: `ops/themes/show.blade.php` 550, `ops/sites/_region.blade.php` 435, `ops/coolify/show.blade.php` 411, `ops/sites/_form.blade.php` 410, `ops/cloudflare/show.blade.php` 387. En büyük job: `PollDeploymentJob.php` 250 (diğerleri ≤ 87 — job'lar ince, iş servislerde).

### 1.3 `SiteController` sorumluluk dökümü (1355 satır)

| Grup | Metotlar (satır) | ≈ Satır | Doğal hedef |
|------|------------------|---------|-------------|
| Liste | `index` (67-246), `activeListFilters` (256), `themeIdFilterOptions` (316), `cmsFilterOptions` (350), `serverFilterOptions` (369), `attachCoolifyServers` (402), `bulkPinSuggestions` (429) | 374 | `SiteListController` + `SiteListFilters` |
| Form / CRUD | `create` (442), `store` (476-546), `edit` (548), `update` (964-1084), `auditSnapshot` (1192), `defaultServerUuid` (1214), `coolifyTargetsFrom` (1282), `coolifyFormData` (1310-1355) | 330 | `SiteCreate/UpdateAction` |
| Mail | `refreshMailOrder` (739), `resendMailConfigure` (779), `assignMail` (803), `assignPlatformMail` (854-914), `fulfill/rejectMailboxRequest` (916, 940), `mailServerOptions`, `mailQueueSuffix`, `mailboxRequest*` (1229-1276) | 285 | `SiteMailController` |
| Lifecycle | `activate` (1086), `deactivate` (1099), `destroy` (1112), `archived` (1134), `restore` (1150), `purge` (1174) | 105 | `SiteLifecycleController` |
| Agent | `checkHealth` (647), `injectAgentSecret` (673), `bulkInjectAgentSecret` (694), `sitesFromBulk` (724) | 90 | `SiteAgentController` |
| Deploy | `switchChannel` (566), `provision` (608-645; hata metnini audit log'dan okuyor `:629-632`) | 80 | mevcut servisler yeterli |

`SiteCoolifyOpsController` (549): lazy kart `panel` (39), compose göçü (64, 77), auto-deploy (104, 120), pin/follow/deploy (150, 411, 424, 437-471), Coolify sync (163, 189), live sync (238, 298), **toplu purge** (272 — lifecycle işi burada), toplu kanal (341), 5 adet GET→redirect koruyucu (231, 263, 325, 332, 404), yardımcılar (477-549).

### 1.4 Test kapsamı (mimari açıdan)

| Testli | Kanıt |
|--------|-------|
| Sorgu sayısı / N+1 korumaları | `DB::listen`/query log kullanan 5 dosya: `tests/Feature/Ops/FleetFailedDeployWindowTest.php`, `SiteListOptionalColumnsTest.php`, `tests/Feature/Sites/SiteFleetSearchTest.php`, `tests/Feature/Themes/ThemeListSummaryTest.php`, `tests/Feature/Sites/SiteListAppHealthCountCostTest.php` |
| Throttle / fan-out dayanıklılığı | `Sleep::fake()` 5 dosyada (`BulkThrottleResilienceTest.php:36`, `BulkDeployWaitingBucketTest.php:43`, `DomainBulkBindTest.php:38`, `TruthfulJobCompletionTest.php:43`, `tests/Unit/Agent/RetriesThrottledAgentRequestsTest.php:18`) |
| Rol kapıları | `SiteBulkActionsTest.php:227` vb. (C5 kapsamı: [15 §C](15-onceki-inceleme-durum-takibi.md)) |

| Testsiz | Kanıt |
|---------|-------|
| 212 sınıftan 112'sinin adı `tests/` altında hiç geçmiyor | `for f in …; grep -rqw "$c" tests` (çoğu HTTP feature testiyle dolaylı kapsanıyor) |
| 203 route'tan 35'i test içinde ad/URI ile hiç geçmiyor; elle teyit edilen yazma route'ları S1-B18'de | `route:list --json` × `tests/**/*.php` metin eşleşmesi (betik `%TEMP%\s1-*`) |
| Mimari kurallar (katman ihlali, boş catch, literal metin) için test yok | `tests/Unit` altında arch test yok |

### 1.5 `catch (Throwable)` envanteri (41)

Komut: `grep -rnF Throwable app | grep -F "catch ("`; her gövde `sed -n` ile okundu.

| Davranış | Adet | Yerler |
|----------|------|--------|
| Agent HTTP: log/`recordOutcome` + Result döner | 9 | `SiteAgentClient.php` ×6, `SiteMailConfigurer.php:94`, `PlatformMailConfigurer.php:86`, `DeskronConfigurer.php:73` |
| Yeniden fırlatır / sınıflar / `report()` | 5 | `HostingerMailClient.php:275`, `OpsJobRunner.php:333`, `PacedFanout.php:94`, `SiteLifecycle.php:87`, `CoolifyEnvCatalogSync.php:69` |
| `Log::warning/info` | 6 | `PlatformOpsMailer.php:79`, `DeploymentDiagnoser.php:90,138`, `SiteIdentityPusher.php:67`, `SiteProvisioner.php:277,404` |
| Audit satırı yazar | 2 | `CoreThemeHealer.php:42`, `SiteProvisioner.php:113` |
| Satırı `failed` işaretler | 3 | `ProvisionSiteJob.php:43`, `SwitchSiteChannelJob.php:43` (`markFailed` log'lar), `ProcessOpsBackgroundJob.php:83` (**log yok**) |
| Bilinçli geri dönüş (sonuç = başarısız/boş) | 5 | `SiteCoolifyOpsController.php:48`, `SiteLiveProbe.php:53,107`, `ThemeSmokeCheck.php:76`, `Coolify/Dto/CoolifyDeployment.php:88` |
| **Sessiz yutma** (log/report/iz yok) | 11 | S1-B08 listesi |

### 1.6 Tekrar envanteri

| Desen | Kopya | Yerler | Bulgu |
|-------|-------|--------|-------|
| İmzalı agent isteği gövdesi | 9 | §S1-B05 | B05 |
| `mapRemoteStatus` | 3 | `CoolifyDeploymentSync.php:111`, `ChannelSwitcher.php:313`, `SiteProvisioner.php:371` | B07 |
| `markFailed` (~50 satır) | 2 | `ChannelSwitcher.php:250`, `SiteProvisioner.php:303` | B07 |
| `sitesFromBulk` | 4 | `SiteController.php:724`, `SiteCoolifyOpsController.php:510`, `SiteAppHealthController.php:116`, `SitePublishStatusController.php:94` | B04 |
| Toplu döngü (controller ↔ runner) | 2 akış | bulk sync, bulk channel | B03 |
| `fromCmsError` / fabrika kümesi | 4 (+1 kısmi) | `AdminAgentResult`, `ThemeAgentResult`, `SitePublishAgentResult`, `SiteIdentityAgentResult` (+`AgentHealthResult`) | B06 |
| Coolify hata → `ComposePackException` sarma | 8 throw | `CoolifyDeploySettings.php:75-250` | B10 |
| Özel `audit()` yardımcısı | 4 | §1.1 | B16 |
| Coolify base_url/token geri dönüşü | 4 | `CoolifyCredentials.php:43-49`, `CoolifyConnection.php:193-199,223-225`, `CoolifySetting.php:108` | B20 |

### 1.7 Bağımlılıklar (yalnız `composer.lock`)

| Paket | Kısıt | Kilitli | Yayın |
|-------|-------|---------|-------|
| php (runtime) | `^8.2` | 8.2 (`Dockerfile:41`) | güvenlik desteği 2026-12-31'e kadar |
| laravel/framework | `^12.0` | v12.66.0 | 2026-08-11 |
| laravel/fortify | `^1.38` | v1.38.0 | 2026-08-07 |
| spatie/laravel-permission | `^6.25` | 6.25.0 | 2026-03-17 |
| laravel/tinker | `^2.10.1` | v2.11.1 | 2026-02-06 |
| phpunit/phpunit (dev) | `^11.5.50` | 11.5.56 | 2026-07-06 |
| laravel/pint (dev) | `^1.24` | v1.30.4 | 2026-08-05 |
| mockery/mockery, fakerphp/faker (dev) | `^1.6`, `^1.23` | 1.6.12, v1.24.1 | 2024 |

Toplam 132 kilitli paket; üretim bağımlılığı 4 — yüzey küçük ve güncel. Eksik araçlar: statik analiz, paralel test (S1-B23, S1-B26).

---

## 2. Bulgular

| ID | Tür | Önem | Bulgu | Kanıt | Etki |
|----|-----|------|-------|-------|------|
| S1-B01 | Borç | Orta | `SiteController` 6 alanı tek sınıfta topluyor; büyüme sürüyor (1051 → 1355). Önceki: E6 (Yapılmadı, kötüleşti) | §1.3; `git show 242ae15:app/Http/Controllers/Ops/SiteController.php \| wc -l` ([01 §3](01-envanter.md#3-controllerlar)) | Her Sites özelliği aynı dosyada çakışır; paralel oturum/merge riski (MEMORY: paylaşımlı checkout) |
| S1-B02 | Borç | Orta | `update` akışı controller'da orkestrasyon: transaction sonrası 7 yan etki sırayla, kısmi başarı yalnız flash'a yazılıyor; job/otomasyon aynı akışı çağıramaz | `SiteController.php:964-1084` (`releaseHostDns` :1032, `applyAliasDns`+`bindAndRedeploy` :1043-1044, `followAgentBaseUrl` :1054, mail :1057-1067, `SiteIdentityPusher` :1072-1076); `store` benzer: `:476-546` | Aynı mantık başka yerden (API, otomasyon, import) gerekirse kopyalanacak |
| S1-B03 | Risk | Orta | Toplu işlemler iki kez uygulanmış: form yolu controller'da, JSON yolu `OpsJobRunner`'da. `bulkSync` ve `bulkChannel` form yolu `PacedFanout`/throttle erteleme kullanmıyor ve yalnız tek exception tipini yakalıyor | `SiteCoolifyOpsController.php:213-221` (`CoolifyApiException`), `:376-390` (`ChannelSwitchException`) ↔ `OpsJobRunner.php:83-95`, `:132-159` (`fanout`) | Form yolunda Coolify 429'unda erken başarısızlık; beklenmeyen exception'da süpürme yarıda 500 |
| S1-B04 | Borç | Orta | `sitesFromBulk` 4 kopya ve farklılaşmış: sıralama, eager load ve `all_dockerfile` desteği tutarsız | `SiteController.php:724` (orderBy), `SiteCoolifyOpsController.php:510` (all_dockerfile, `whereIn` sırasız), `SiteAppHealthController.php:116` (`with('latestDeployment')`), `SitePublishStatusController.php:94` | Aynı seçim farklı ekranlarda farklı sırada/kapsamda işlenir |
| S1-B05 | Borç | Orta | İmzalı agent isteği deseni (encode → `ControlPlaneAgentSignature::headers` → `Http::timeout` → `sendWithRetry` → iki catch) 9 kez kopyalanmış; yanıt içinde secret yankısı kontrolü yalnız 2'sinde | `SiteAgentClient.php:42,156,221,403,506,569`, `SiteMailConfigurer.php:76`, `PlatformMailConfigurer.php:68`, `DeskronConfigurer.php:57`; `payloadContainsSecret`: `SiteAgentClient.php:647`, `SiteMailConfigurer.php:183`. Önceki: E2 (kontrat fixture'ı, Yapılmadı) | Kontrat değişikliği (header, timeout, hata kodu) 4 dosyada elle yapılır |
| S1-B06 | Borç | Düşük | 5 agent Result DTO'su aynı fabrika kümesini (`success/failure/needsSecret/fromCmsPayload/fromCmsError`) kopyalıyor; `fromCmsError` gövdeleri yalnız lang anahtarıyla farklı | `SitePublishAgentResult.php:55-70` ↔ `SiteIdentityAgentResult.php:52-67`; `AdminAgentResult`, `ThemeAgentResult` aynı imzalar | 429/401/404 davranışı değişince 5 dosya |
| S1-B07 | Hata riski | Orta | Coolify → `DeploymentStatus` eşlemesi 3 birebir kopya; `markFailed` provision ve kanal geçişinde ~50 satırlık ikiz | `CoolifyDeploymentSync.php:111-121`, `ChannelSwitcher.php:313-323`, `SiteProvisioner.php:371-381`; `diff` `ChannelSwitcher.php:250-302` ↔ `SiteProvisioner.php:303-353` (yalnız trigger/action/log adı farklı) | Coolify yeni durum adı eklerse bir kopya güncellenip diğerleri `InProgress`'te kalır → sessiz yanlış durum |
| S1-B08 | Risk | **Yüksek** | 41 `catch (Throwable)`; 11'i log/report olmadan yutuyor. En riskli: env kataloğu okunamazsa beklenen env listesi boşalıyor, sağlık raporu eksik env'i göremiyor; envanter senkronunda site sessizce atlanıyor | Sessiz: `SiteAppHealthInspector.php:330`, `CoolifySiteTargetSync.php:29` (`continue`), `CoolifySiteSync.php:46,56,62`, `CoolifyDeploymentSync.php:199,214`, `SiteAttacher.php:130`, `SiteLanding.php:155`, `SiteDetailController.php:37`, `PlatformMailSettingsController.php:128`. Sayım: `grep -rF Throwable app \| grep -F "catch ("` → 41 | Operatör "sağlıklı/senkron" görür, gerçek hata log'da da yok |
| S1-B09 | Risk | **Yüksek** | Arka plan job hatası `report()` edilmeden ham mesajla satıra yazılıyor; 18 job'un hiçbirinde `failed()` yok → timeout/SIGKILL'de `catch` çalışmaz, `OpsBackgroundJob` `running`, site `provisioning` kalır. Takılı `running` satırını düzelten kod yok (widget 2 saat sonra yalnız gizliyor). Önceki: E1 (Yapılmadı) | `ProcessOpsBackgroundJob.php:83-85`; `ProvisionSiteJob.php:43-52`, `SwitchSiteChannelJob.php:43`; `grep -rn "function failed" app/Jobs` boş; `OpsJobController.php:28` (`subHours(2)`); `markFailed` çağıranları: 4 job/servis. Doğrulanmadı: 300 s timeout'un throttle altındaki büyük süpürmede aşılıp aşılmadığı (`config/ops.php:190-196` 6 deneme × 8 s'ye kadar gecikme) — S10 | Sessiz yanlış durum: "çalışıyor" görünen ölü iş; site yeniden provision edilemez (unique kilit 3600 s) |
| S1-B10 | Borç | Orta | Exception taksonomisi düz ve alan dışına taşmış: 15 sınıf doğrudan `RuntimeException`; ortak taban yok; global render yok. `SiteProvisionException` Cloudflare/Domain/agent secret akışlarında, `ComposePackException` redeploy/pin/auto-deploy'da kullanılıyor; sarma 5 kez kopya | `bootstrap/app.php:55-57`; `grep -rlw SiteProvisionException app` → 12 dosya (`CloudflareZoneService`, `SiteLanding`, `SiteAgentSecretInjector` …); `CoolifyDeploySettings.php:75,100-102,128-130,151-153,250` | Controller'lar hangi exception'ı yakalayacağını tahmin ediyor; yakalanmayan → 500 |
| S1-B11 | UX | Orta | i18n baypası: 12 Türkçe literal exception mesajı + 13 literal flash + İngilizce literal hata metinleri operatöre gidiyor; en dilindeki kullanıcı Türkçe görür (lang dosyaları eşit olsa da) | `SiteController.php:505`, `SiteProvisioner.php:442,448`, `ChannelSwitcher.php:158`, `SiteAgentSecretInjector.php:19,24,37`, `SiteAttacher.php:113,117`, `CoolifyProvisionPreflight.php:20,26,73`; flash: `CoolifyConnectionController.php` 10, `ThemeController.php` 3 (`grep -rnE "with\('(status\|error)', '"`); EN: `ChannelSwitcher.php:355`, `SiteProvisioner.php:188` | Dil tutarsızlığı; [01 §8.3](01-envanter.md#83-lang) "eksik 0" ölçüsü bu metinleri kapsamıyor |
| S1-B12 | Hata | Orta | `ChannelSwitcher` hazırlık kontrolü sitenin değil **varsayılan** bağlantının kimlik bilgisine bakıyor; asıl çağrı sitenin bağlantısıyla yapılıyor. `SiteProvisioner` doğru yapıyor | `ChannelSwitcher.php:88,350-356` (`CoolifyCredentials::resolve(CoolifySetting::current())`) ↔ `:133` (`CoolifyApplicationService::forSite`); doğru örnek `SiteProvisioner.php:434-439` | Çok bağlantıda yanlış engelleme veya yanlış geçiş; hata mesajı yanıltıcı |
| S1-B13 | Borç | Orta | `Services/Sites` her şeyin çöplüğüne dönüşmüş: 32 sınıf / 5487 satır; deploy (7), app-health (4), domain (4), agent (4), liste (2) karışık. Aynı anda `Services/Domains` ve `Services/Agent` var | `find app/Services/Sites -name '*.php' -exec wc -l {} +`; S0 haritası dizini M1/M2/M3/M5'e bölüyor ([01 §11](01-envanter.md#11-modül--dosya-haritası-dalga-1-sahiplik-referansı)) | Sahiplik belirsiz; yeni sınıf "Sites"e düşmeye devam eder |
| S1-B14 | Borç | Orta | `Site` modeli 1250 satır/66 metot: ilişkiler + durum makinesi + sunum (`liveHttpLabel/Tone`, `publishLabel/Tone`, `identityMarkLetter`) + 16 pozisyonel string parametreli filtre scope'u + filo çapında statik. Filtre tanımı 3 yerde | `Site.php:891-1040` (`scopeMatchingListFilters`), sabitler `:781-868`, `SiteSavedViews.php:23` (`FILTER_KEYS`), `SiteController.php:77-125` ve görünüm dizisi `:201-245` | Yeni filtre = ≥5 dosya; model 915 → 1250 büyüdü |
| S1-B15 | Perf | Düşük | `reportedDeamonVersions` tüm filoyu JSON payload ile PHP'ye çekiyor; her tam Sites sayfasında, `cms` filtresi aktifse istek başına 2 kez. Blade içinde 3 sorgu | `Site.php:1057`, çağıranlar `SiteController.php:352`, `Site.php:1091`; Blade: `ops/coolify/show.blade.php:30,32`, `ops/sites/_themes.blade.php:12` | Filo büyüdükçe O(n) bellek/CPU; view'da sorgu katman ihlali |
| S1-B16 | Borç | Orta | Merkezi audit yazıcısı yok: 58 doğrudan çağrı, 4 özel `audit()` kopyası; alan şekli (before/after/ip) serbest | §1.1; `grep -rnE "auditLogs\(\)->create\|AuditLog::query\(\)->create" app \| wc -l` → 58 | Action adı/şekil kayması; Önceki: C3 "neden" alanı eklemek 58 yer demek |
| S1-B17 | Eksik | Orta | Otonomi blokajları: kanal geçişi aktörsüz çağrılamıyor; runner aktörsüz job'u reddediyor; yetki yalnız HTTP katmanında; akış sonuçları flash string'e dönüşüyor | `ChannelSwitcher.php:49` (`User $actor`), `OpsJobRunner.php:400-407`; `SiteController.php:1079-1083` (sonuç = redirect+flash) | Zamanlayıcı/otomatik düzeltme ya sahte kullanıcı ister ya kod kopyalar |
| S1-B18 | Eksik | Orta | Testte hiç geçmeyen yazma route'ları (elle teyitli): `ops.jobs.coolify.cancel`, `ops.jobs.coolify.force-start`, `ops.domains.bind`, `ops.domains.update`, `ops.coolify.{projects,environments,git-sources}.toggle`, `ops.coolify.update`, `ops.cloudflare.{default,destroy,zones.store,zones.destroy}`, `ops.deskron.push`, `ops.platform-mail.push`, `ops.sites.themes.{activate,auto-update}`, `ops.themes.access.{store,destroy}`, `ops.sites.admins.{activate,password}`, `ops.mail-servers.{update,destroy}`, `ops.settings.deamon-git.{pat.clear,callback}`, `ops.sites.mail-order` | Betik: `route:list --json` + `tests` metin eşleşmesi → 35 aday; `grep -rlF "auto-update\|/toggle\|deskron/push\|platform-mail/push\|coolify-deployments" tests` boş | Rol/yetki regresyonu ve yan etki hataları yakalanmaz (C5 ile birleşir) |
| S1-B19 | Borç | Düşük | Ölü kod adayı: 33 public metot kaynakta yalnız tanımında geçiyor (10'u yalnız testte) | Örn. `FleetDashboardKpis.php:122` `agentSecretAttentionTotal`, `Site.php:441` `allowedThemes`, `HostingerMailClient.php:147,230` `findOrderByDomain/findOrder`, `GitHubAppClient.php:67,170`, `SiteAgentClient.php:278` `listThemes`, `CoolifyEnvCatalogSync.php:29` `syncAll`, `PlatformMailConfigurer.php:108` `syncAllSites`, `User.php:151` `requestedDeployments`. Doğrulanmadı: dinamik çağrı/Blade property olasılığı — her aday için `grep -rnw <ad> app resources routes` + ilgili feature testi | Okuma yükü; yanlış "bu kullanılıyor" varsayımı |
| S1-B20 | Borç | Düşük | Üç katmanlı legacy kimlik geri dönüşü (bağlantı → `coolify_settings` → env) 4 yerde tekrar; GitHub PAT/private key env geri dönüşü; migration model koduna bağımlı | `CoolifyCredentials.php:34-52`, `CoolifyConnection.php:191-202,221-225`, `CoolifySetting.php:108`; `GitHubAppClient.php:488,527`, `GithubSetting.php:103-125`; `database/migrations/2026_09_10_210000_create_theme_git_connections_tables.php:63` (`ThemeGitConnection::createFromLegacySettings`) | Hangi kimliğin kullanıldığı belirsiz; model değişince eski migration kırılır |
| S1-B21 | Borç | Düşük | Enum/string karışıklığı: `OpsBackgroundJob.status` enum'suz (52 literal); `channel` enum cast'li olduğu halde 35 yerde `instanceof Channel ? ->value : raw` savunması | `grep -rnE "'(queued\|running\|completed\|cancelled)'" app` → 52; `Site.php:108` cast; `grep -rn 'channel instanceof Channel' app resources` → 35 | Yazım hatası tipte yakalanmaz; gürültü |
| S1-B22 | Borç | Düşük | Bağımlılık çözümü karışık: 56 `app(X::class)` servis bulucu (16'sı `OpsJobRunner`), servis içinde `new CoolifyDeploymentSync`, statik fabrikalar | `OpsJobRunner.php:57,86,…`; `CoolifySiteSync.php:50`, `CoolifySiteTargetSync.php:35`; `CoolifyApplicationService.php:32-45` | Test ikamesi zor; yalnız `Http::fake` ile test edilebiliyor |
| S1-B23 | Risk | Orta | Platform ve araç: PHP 8.2 çalışma zamanı (güvenlik desteği 2026-12-31'de biter); statik analiz, pint yapılandırması ve CI yok. Paketler güncel (Laravel v12.66.0 2026-08-11, Fortify v1.38.0, spatie/permission 6.25.0, PHPUnit 11.5.56) | `Dockerfile:41` `php:8.2-fpm-bookworm`; `composer.json` `"php": "^8.2"`; lock'ta `larastan`/`phpstan/phpstan` yok (yalnız `phpstan/phpdoc-parser` bağımlılığı); `pint.json` yok; `.github/` yok (Önceki: E7) | 3 ay içinde güvenlik yaması olmayan runtime; tip hataları yalnız testle yakalanır |
| S1-B24 | Borç | Düşük | Çalışma ağacında 79 CRLF dosya (index 0), `core.autocrlf=true`; `app/Services` 9, `resources/views` 20, `database/migrations` 6. Önceki: E4 (Geçersiz-kısmen) | `git ls-files --eol \| awk '$2 ~ /crlf/' \| wc -l` → 79 | Pint/diff gürültüsü; yerel ayar meselesi |
| S1-B25 | Borç | Düşük | 31 satır içi `->validate([` 16 controller'da; bazılarında iş normalizasyonu da controller'da (bildirim override birleştirme) | `grep -rn -- '->validate(\[' app/Http/Controllers` → 31; `SiteController.php:858-884` | Aynı kurallar API/otomasyon için yeniden yazılır |
| S1-B26 | Perf | Düşük | Test paketi yavaş (572 s / 1080); sayfa render testleri 0.5–1.2 s; her DB testinde env kataloğu fixture'ı yeniden işleniyor; paralel test paketi yok | [01 §7](01-envanter.md#7-testler); `--filter=SiteCrudTest --profile` → 21 test 8.56 s, `index filters…` 1.19 s; `tests/TestCase.php:25-29`; lock'ta `brianium/paratest` yok. Doğrulanmadı: yavaşlığın ana kaynağı — `php artisan test --profile` ile ilk 20 test | Geri bildirim döngüsü ~10 dk; ajanlar tam paketi koşmaktan kaçınıyor |
| S1-B27 | Borç | Düşük | GET isteklerinde yazma: detay sayfası deploy durumunu düzeltiyor, liste kullanıcı tercihlerini yazıyor; durum düzeltmesi sayfa ziyaretine bağlı. Önceki: A2 (Yapılmadı) | `SiteDetailController.php:35-36` (`recoverSiteIfLatestFinished`), `SiteController.php:75,139` (`rememberColumns`, `rememberSort`) | Kimse bakmazsa durum düzelmez; okuma yolu yan etkili |
| S1-B28 | Risk | Orta | HTTP client'larının hata/tekrar modeli tutarsız: Coolify exception + retry + rate guard; agent Result DTO + retry trait; GitHub/Cloudflare/Hostinger'da 429/`Retry-After`/5xx tekrarı yok | `CoolifyClient.php:416,511`; `Concerns/RetriesThrottledAgentRequests.php:21`; `grep -niE "rate\|retry-after\|x-ratelimit" GitHubAppClient.php CloudflareClient.php HostingerMailClient.php` boş | GitHub push sonrası tema fan-out'u veya toplu DNS'te geçici hata = kalıcı hata |

Önem kırılımı: Kritik 0 · Yüksek 2 · Orta 16 · Düşük 10 (toplam 28).

---

## 3. İyileştirme ve güncelleme önerileri

Puan = Etki × 2 − Risk + (S=3, M=2, L=1, XL=0).

| ID | Öneri | Çözdüğü bulgu | Etki (1–5) | Efor | Risk (1–5) | Öncelik puanı | Kabul kriteri |
|----|-------|---------------|-----------|------|-----------|---------------|---------------|
| S1-O06 | Sessiz catch politikası: her `catch (Throwable)` ya yeniden fırlatır, ya `report()`, ya `Log::warning` + bağlam; `SiteAppHealthInspector:330` katalog okunamazsa rapora "katalog okunamadı" issue'su ekler. Kuralı koruyan birim testi (`app/` altında boş gövdeli/yorum-only `catch (Throwable)` bulunca kırmızı) | B08 | 4 | S | 1 | **10** | `grep` tabanlı test yeşil; 11 yerin her birinde log veya issue var; `SiteAppHealthInspector` için katalog hatası testi |
| S1-O05 | `CoolifyStatusMap::toDeploymentStatus()` tek kaynak + `DeploymentFailureRecorder` (provision/kanal `markFailed` ortak gövde; trigger/action parametre) | B07 | 4 | S | 2 | **9** | `mapRemoteStatus` tanımı 1 dosyada; `ChannelSwitchTest`, `ProvisionSiteTest`, `CoolifyWebhookTest` yeşil; eşleme için tablo testi (her Coolify durumu) |
| S1-O07 | Durum sahibi job'lara `failed(Throwable)` (`ProcessOpsBackgroundJob`, `ProvisionSiteJob`, `SwitchSiteChannelJob`, `Theme*Job`); `ProcessOpsBackgroundJob` catch'ine `report()` + `SecretRedactor` ile güvenli mesaj; `updated_at`'i N dk'dan eski `running` satırlarını `failed` yapan süpürme (S10 ile koordineli) | B09 | 4 | S | 2 | **9** | Timeout simülasyonunda (`failed()` doğrudan çağrı) satır `failed`, site `error`; süpürme testi; log'da stack trace |
| S1-O03 | Tek toplu-işlem yolu: `ChannelSwitcher::startMany`, `CoolifySiteSync::syncMany` (`PacedFanout` ile); controller form yolu ve `OpsJobRunner` aynı `*Many`'yi çağırır; 4 `sitesFromBulk` → `BulkSiteSelection::fromRequest()` (tek sıralama + eager load parametresi) | B03, B04 | 4 | M | 2 | **8** | `grep -n "foreach (\$sites" app/Http/Controllers` yalnız `authorize` döngüleri; `sitesFromBulk` 0 kopya; `BulkThrottleResilienceTest` form yolu için de geçer |
| S1-O10 | `ChannelSwitcher::assertCoolifyReady(Site)` sitenin bağlantısını kullanır (`SiteProvisioner::assertCoolifyReady` deseni) | B12 | 3 | S | 1 | **8** | İki bağlantılı test: varsayılan tokensız, site bağlantısı tokenlı → geçiş başlar; tersi → anlamlı hata |
| S1-O15 | Larastan (seviye 5 + baseline) ve `pint.json` (`line_ending: lf`); `composer check` betiği (pint --test + stan + hedefli test). CI yoksa yerel/ajan kapısı olarak | B23, B24, B19 | 3 | S | 1 | **8** | `composer check` 0 hata (baseline ile); baseline dosyası repo'da; ölü kod adayları stan raporunda listelenir |
| S1-O01 | Action katmanı: `app/Actions/Sites/{CreateSite,UpdateSite}` — girdi DTO, çıktı `SiteUpdateOutcome` (dns/bind/mail/identity alt sonuçları); controller yalnız outcome → flash eşler | B02, B17, B25 | 4 | M | 3 | **7** | `SiteController::update` ≤ 30 satır; action için birim testi (Http::fake) kısmi başarı senaryolarıyla; mevcut `SiteCrudTest`/`SiteLandingFlowTest` yeşil |
| S1-O11 | `AuditRecorder::record(subject, actor, ip, action, before, after, context)` + action sabit kataloğu; 4 özel `audit()` ve yeni kod buna geçer; tüm action'lar için lang etiketi testi | B16 | 3 | M | 1 | **7** | 4 özel `audit()` silinmiş; yeni çağrılar recorder'dan; `AuditLog::actionLabel` geri dönüşüne düşen action sayısı 0 (test) |
| S1-O14 | S1-B18 listesindeki ~25 yazma route'u için en az "viewer 403 + operator happy path (Http::fake)" testi | B18 | 3 | M | 1 | **7** | Betik yeniden koşunca yazma route'larından testte geçmeyen 0; C5 rol matrisiyle birleşebilir |
| S1-O23 | Sistem aktörü sözleşmesi: `ChannelSwitcher::start(?User $actor)` (force yalnız insan), `OpsJobRunner` aktörsüz `system` job tipine izin; audit `actor_user_id=null` + `after.source='automation'`; servis içi `OpsActionGuard` (kill switch + rol) | B17 | 4 | M | 3 | **7** | Aktörsüz kanal geçişi testi; audit satırında `source`; guard kapalıyken otomatik çağrı reddedilir |
| S1-O02 | `SiteController`'ı saf taşıma ile böl: `SiteListController`, `SiteMailController`, `SiteLifecycleController`, `SiteAgentController`; `bulkPurge` → lifecycle. Route adları değişmez | B01 | 3 | M | 2 | **6** | `SiteController` ≤ 500 satır; `route:list` adları aynı; tüm Sites testleri yeşil |
| S1-O04 | `Agent\SignedAgentRequest` (tek imzalı GET/POST + retry + secret yankı kontrolü + standart hata kodu); mail/platform-mail/deskron configurer'lar ve `SiteAgentClient` kullanır; `AgentResult` ortak tabanı `fromCmsError`'ı tekler | B05, B06 | 3 | M | 2 | **6** | `ControlPlaneAgentSignature::headers` çağrısı 1 yerde; 5 DTO ortak trait/tabandan; `Feature/Agent/*`, `Feature/Mail/*` yeşil |
| S1-O08 | `OpsActionException` tabanı (i18n anahtarı + bağlam); alan exception'ları ondan türer; `bootstrap/app.php` `withExceptions`'ta render → `back()->with('error')` (JSON'da 422); yanlış adlı `ComposePackException` kullanımı `CoolifyDeployException`'a taşınır | B10 | 3 | M | 2 | **6** | Yakalanmayan alan exception'ı 500 yerine flash (test); `ComposePackException` yalnız `ComposePackMigrator`'da |
| S1-O09 | 12 TR + EN literal exception ve 13 literal flash'ı lang'e taşı; `new \w+Exception('…')` literal'i ve `with('status', '…')` literal'ini yakalayan test | B11 | 2 | S | 1 | **6** | Test yeşil; en dilinde Coolify bağlantı testi flash'ı İngilizce |
| S1-O19 | `OpsJobStatus` enum + cast; `channel instanceof Channel` savunmalarını cast'in garanti ettiği yerlerde kaldır | B21 | 2 | S | 1 | **6** | `'running'` literal'i `app/` altında ≤ 5 (SQL CASE hariç); `instanceof Channel` ≤ 10 |
| S1-O20 | `reportedDeamonVersions` istek başına `once()` ile memoize (veya ADR-10 gibi kalıcı sürüm kolonu); Blade'deki 3 sorguyu controller'a taşı | B15 | 2 | S | 1 | **6** | `cms` filtresiyle Sites isteğinde tarama 1 kez (query log testi); `resources/views` altında `::query()` 0 |
| S1-O21 | Test hızı: `--profile` ile ilk 20 yavaş testi çıkar; env kataloğu seed'ini gerektiren testlere opt-in trait yap; `brianium/paratest` + `php artisan test --parallel` | B26 | 2 | S | 1 | **6** | Tam paket süresi ≤ 300 s (paralel), sonuç 1080+ yeşil |
| S1-O22 | `RetriesTransientHttp` ortak trait (429/`Retry-After`/5xx, `RetryAfter` yeniden kullanımı) → GitHub, Cloudflare, Hostinger client'ları | B28 | 3 | M | 2 | **6** | Her client için 429→başarı ve 3×5xx→exception testi (`Sleep::fake`) |
| S1-O16 | PHP 8.4'e (veya 8.3) yükseltme: `Dockerfile`, `composer.json` `config.platform`, yerel suite | B23 | 3 | M | 3 | **5** | İmaj 8.4; tam paket yeşil; 2026-12-31 öncesi canlıda |
| S1-O12 | `SiteListFilters` değer nesnesi (anahtar, seçenek, etiket, scope uygulaması); `scopeMatchingListFilters` 16 parametre yerine bu nesneyi alır; `Site` sunum metotları `Presenters/SitePresenter`'a | B14 | 3 | L | 3 | **4** | Yeni filtre eklemek 1 sınıf + lang; `Site.php` ≤ 900 satır; `SiteList*Test` yeşil |
| S1-O13 | `Services/Sites` ayrıştırma: `Services/Deploy/*` (Provisioner, ChannelSwitcher, CoolifyDeploySettings, ComposePackMigrator, Diagnosis, DeploymentFailureText, ChannelEnvironmentMap), `Services/AppHealth/*`, domain sınıfları `Services/Domains`, agent sınıfları `Services/Agent` | B13 | 2 | M | 2 | **4** | `Services/Sites` ≤ 10 sınıf; [01 §11](01-envanter.md#11-modül--dosya-haritası-dalga-1-sahiplik-referansı) haritası tek sahipli |
| S1-O17 | Ölü kod adaylarını (33) doğrulayıp sil | B19 | 1 | S | 1 | **4** | Her silinen metot için `grep -rnw` 0; paket yeşil |
| S1-O18 | Legacy kimlik geri dönüşlerine kullanım log'u (`Log::notice` + sayaç) ekle; 2 hafta sıfır kullanımda `coolify_settings`/env token dalını kaldır; migration'daki model çağrısını satır içi sorguya çevir | B20 | 2 | M | 3 | **3** | Log'da kullanım 0 → kod kaldırıldı; `migrate:fresh` temiz |

Öncelik sırası (puan): O06 10 · O05 9 · O07 9 · O03 8 · O10 8 · O15 8 · O01 7 · O11 7 · O14 7 · O23 7 · O02 6 · O04 6 · O08 6 · O09 6 · O19 6 · O20 6 · O21 6 · O22 6 · O16 5 · O12 4 · O13 4 · O17 4 · O18 3.

---

## 4. Otonomi fırsatları

Mimari tespit: servis metotlarının çoğu otomasyona hazır (`?User $actor`, guard'lar serviste, `lockForUpdate`, `PacedFanout`), ama (1) tek bir "action" giriş noktası yok — bazı akışlar controller'da (S1-B02), (2) toplu işlem iki yerde (S1-B03), (3) aktörsüz çağrı iki yerde engelli (S1-B17), (4) hata yutma otomasyonun "doğrulama" adımını kör ediyor (S1-B08). Aşağıdaki satırlar bu engeller kalkınca açılır.

| Akış | Bugünkü seviye | Hedef | Tetik | Ön koşul | Güvenli otomatik adım | Doğrulama | Geri alma | Guardrail/bütçe | Kill switch | Audit | Bildirim |
|------|---------------|-------|-------|----------|----------------------|-----------|-----------|-----------------|-------------|-------|----------|
| Takılı arka plan işi / provision satırı kurtarma | L0 (widget 2 sa sonra gizler, `OpsJobController.php:28`) | L4 | Zamanlanmış süpürme her 10 dk | S1-O07 (`failed()` + süpürme) | `running` ve `updated_at` > timeout+5 dk olan `OpsBackgroundJob` → `failed("worker öldü")`; `provisioning` > 30 dk ve açık deploy yok → `error` | Coolify `getDeployment` ile son deploy terminal mi | Satırı elle `queued`'a çekip yeniden kuyruğa (tek tık, L3) | Çalışma başına ≤ 50 satır | `config('ops.jobs.stale_sweep')` false | `ops_job.stale_failed`, `site.provision_stale` | Jobs widget "failed" şeridi + ops mail |
| Deploy durum uzlaştırma (Önceki A2) | L1 (yalnız detay sayfası GET'inde, `SiteDetailController.php:35`) | L4 | Saatlik schedule | S1-O05 (tek durum eşlemesi), S1-O06 | `in_progress` > 45 dk satırları `CoolifyDeploymentSync` ile yeniden oku | Coolify yanıtı terminal durum | Yok (salt okuma + düzeltme); yanlış eşlemede eski değer audit'ten | Coolify rate guard, çalışma başına ≤ 100 istek | `ops.coolify.reconcile.enabled` | `deployment.reconciled` (before/after) | Yalnız değişenler Activity'de |
| Toplu redeploy / follow-head / pin (koşullu) | L3 (insan tetikler, `OpsJobRunner`) | L4 | Ör. tema/CMS sürüm yayını sonrası "outdated" filtre | S1-O03 (tek yol), S1-O23 (sistem aktörü + guard) | `CoolifyDeploySettings::redeployMany` yalnız `status=active`, sağlıklı, pinli olmayan sitelere | `PollDeploymentJob` sonucu + agent health `deamon_version` | `pin` önceki commit'e (`pinMany`), kanal değişmez | Dalga başına ≤ 5 site, başarısızlık ≥ 1 ise dur | `ops.automation.enabled` + site bazlı `automation_opt_out` | `site.deploy_auto` + `source: automation` | Ops mail özeti; başarısızlıkta anında |
| Sessiz hata görünürlüğü (sync/inventory/health) | L0 (11 yutma, S1-B08) | L2 | Her senkron/inceleme çalışması | S1-O06 | Hata sayacı + son hata metnini sonuç DTO'suna ekle; UI'da "kısmi senkron" rozeti | Sonraki çalışmada sayaç 0 | Gerekmez (salt görünürlük) | — | — | Mevcut sync audit'ine `partial_errors` | Coolify/Sites kartında rozet |
| Site güncelleme yan etkilerinin yeniden denenmesi (DNS/bind/mail/kimlik) | L1 (yalnız flash, `SiteController.php:1079-1083`) | L3 → L4 | `SiteUpdateOutcome` içinde başarısız alt adım | S1-O01 (action + outcome) | Başarısız alt adımı (ör. `SiteIdentityPusher`, mail configure) job olarak yeniden kuyruğa al | Alt adımın kendi sonucu (`ok`) | Alt adımlar idempotent; DNS için `releaseHostDns` tersi | Alt adım başına ≤ 3 deneme, üstel bekleme | `ops.automation.enabled` | `site.update_step_retried` | Site detayda "bekleyen adım" satırı |
| Kod sağlığı kapısı (ajan oturumları) | L0 | L4 | Her ajan commit'i öncesi `composer check` | S1-O15, S1-O06 testi | pint + stan + hedefli test; kırmızıysa commit yok | Komut çıkış kodu 0 | `git restore` (commit öncesi) | Süre ≤ 3 dk (hedefli) | Ortam değişkeni ile atlama yok (bilinçli) | Commit mesajında check özeti | Oturum çıktısı |

---

## 5. Yeni özellik fikirleri (bu alana özgü)

- **Mimari kural testleri** (`tests/Unit/Architecture/*`): controller'da `Http::` yok, `resources/views` içinde `::query()` yok, boş `catch (Throwable)` yok, literal flash yok — PHP tokenizer ile, ek paket gerektirmeden.
- **Tek "ops action" kaydı**: her action sınıfı `name`, `requiresRole`, `idempotent`, `automatable` meta verisi taşır; palet ve gelecekteki otomasyon bu kayıttan okur.
- **Kısmi başarı sonuç nesnesi** (`OpsOutcome { steps: [ok|failed|skipped + reason] }`): update, provision, bulk; UI ve Activity aynı nesneyi gösterir.
- **Kontrat anlık görüntüleri**: `SignedAgentRequest` her yanıt kodunu/şeklini metriklerle sayar; Activity'de "agent 404 = eski CMS" dağılımı (CMS sürüm göçü takibi).
- **Sorgu bütçesi testi** Sites index için (tam sayfa ≤ N sorgu) — mevcut 5 sorgu testinin genellemesi.

---

## 6. CMS handoff

| Konu | CMS tarafı |
|------|------------|
| S1-O04 / Önceki E2 | `/internal/control/v1/*` her uç için örnek istek/yanıt JSON'larını (başarı + 401/404/422/429 hata gövdesi `{error, message}`) yayınlaması; Plane bunları `tests/Fixtures/agent/*.json` olarak kullanır |
| S1-O22 | Agent uçlarının 429'da `Retry-After` başlığını tutarlı döndürmesi (Plane `RetryAfter` ile okuyor) — teyit |

---

## 7. Açık sorular

| Soru | Önerilen default |
|------|------------------|
| Action katmanı nerede yaşamalı: `app/Actions/*` mi, `app/Services/*` içinde `*Action` mı? | `app/Actions/<Alan>/*`, tek `handle()` + sonuç DTO; servisler yardımcı olarak kalır |
| JS'siz (form post) toplu işlem yolu sürsün mü? | Kaldır: her toplu işlem `OpsBackgroundJob` üzerinden; testte `QUEUE_CONNECTION=sync` zaten senkron çalıştırır (`phpunit.xml:32`) |
| Sistem aktörü modeli: `users` içinde "system" kullanıcısı mı, `actor_user_id=null` + `source` mu? | `null` + `after.source='automation'` (Önceki C3 "neden" alanıyla birlikte tasarlansın) |
| `coolify_settings` ve env token geri dönüşü ne zaman kalkar? | Kullanım log'u 2 hafta sıfırsa bir sonraki dilimde (S1-O18) |
| Hedef PHP sürümü | 8.4 (Laravel 12 destekli); 2026-12-31 öncesi canlıda |
| CI yokken statik analiz nerede koşar? | `composer check` betiği + ajan başlangıç prompt'una zorunlu adım; CI kararı Önceki E7 ile ayrı |
