# 03 — Sites ve yaşam döngüsü

**Tarih:** 2026-09-24 · **HEAD:** 8484d37 · **Hazırlayan:** S2 — Sites ve yaşam döngüsü denetçisi (Dalga 1)
**Kapsam:** `routes/ops/sites.php`; `SiteController`, `SiteDetailController`, `SiteCoolifyOpsController` (yalnız yaşam döngüsü: activate/deactivate, purge, bulk deploy/channel), `SitePublishStatusController`, `SiteListPreferencesController`, `SiteAdminController`; `app/Services/Sites/{SiteLifecycle,SiteAttacher,SiteProvisioner,ChannelSwitcher,SiteListSummary,SiteFilterVerdict,SitePublishStateUpdater}` (akış düzeyi); `app/Support/Lists/*`; `app/Http/Requests/Ops/*Site*`; `SitePolicy`; `NotHeldByArchivedSite`; `resources/views/ops/sites/*`; `ops:import-coolify-apps` (yalnız Plane satırına etkisi); ilgili testler.
**Kapsam dışı:** Coolify istemci/rate-guard/env kataloğu ayrıntısı (S3), agent imza ve sağlık değerlendirme içi (S4), Cloudflare/domain servis içi (S6), görsel UI (S8), CMS kodu.

Önceki inceleme durumu: [15-onceki-inceleme-durum-takibi.md](15-onceki-inceleme-durum-takibi.md). Envanter: [01-envanter.md §11 M1](01-envanter.md#m1--sites-crud-liste-detay-kabuğu-lifecycle).

## Özet

- **Kanal geçişi Coolify'ı yarı yolda bırakıyor.** `git_branch` + env PATCH'i deploy kapısından **önce** yapılıyor. Kapı meşgulse ya da deploy başarısız olursa Plane `channel` eski değerde kalıyor ama Coolify yeni dalda duruyor. Ardından eski kanala "geri dön" isteği `same_channel` hatasıyla reddediliyor (S2-B01). Canlıda redis kuyruğu kullanıldığı için toplu kanal geçişinde aynı host'taki ilk siteden sonraki her site bu duruma düşüyor. Testler `sync` kuyrukla koştuğu için bu yakalanmıyor (S2-B02).
- **Başarısızlık eski deploy kaydını eziyor.** `SwitchSiteChannelJob`/`ProvisionSiteJob`, hata anında sitenin **en son** `deployments` satırını `failed` + `finished_at=now` yapıyor. Bu satır geçen haftaki başarılı bir deploy olabilir. Sonuçta KPI, `deploy=failed` filtresi ve otomatik teşhis yanlış satır üzerinde çalışıyor (S2-B03).
- **Aynı Coolify uygulaması iki Plane sitesine bağlanabiliyor.** Benzersiz indeks yok, doğrulama yok ve açılır liste zaten bağlı olan uygulamaları da gösteriyor. Sitelerden birini kalıcı silmek, ötekinin volume'larını da siliyor (S2-B04, **Kritik**). Canlı sitede Coolify hedefleri (bağlantı, sunucu, proje) formdan değiştirilebiliyor. Kalıcı silme 404'ü "zaten silinmiş" saydığı için uygulama çalışır halde sahipsiz kalabiliyor (S2-B05).
- **Durum makinesinde koruma eksik.** `provisioning`/`deploying` durumundaki site arşivlenebiliyor; kuyruktaki işler bu durumda sessizce çıkıyor ve site o durumda takılıyor (S2-B06). Takılan `provisioning`/`deploying` için UI'dan kurtarma yolu yok (S2-B07). Durdurulmuş (`stopped`) sitede redeploy, pin, domain bağlama ve toplu deploy uygulamayı yeniden ayağa kaldırıyor ama Plane `stopped` göstermeye devam ediyor (S2-B08).
- **Geri alınamaz işlem, geri alınabilirden daha düşük yetki istiyor.** Kalıcı silmeyi (tekil ve `all=1` ile tüm filo) operatör yapabiliyor; geri yükleme için Super Admin gerekiyor. Geçersiz bir filtre değeri toplu işlemi genişletiyor ve sunucu tarafında beklenen sayı kontrolü yok (S2-B10).
- En değerli öneriler: S2-O09 kalıcı silmeyi sertleştirme (puan 12), S2-O04 attach tekilliği (11), S2-O01/O02/O03 kanal geçişini ve başarısızlık kaydını düzeltme (10'ar puan).

## 1. Mevcut durum

### 1.1 Durum makinesi (kod: `app/Enums/SiteStatus.php:37-48`)

```mermaid
stateDiagram-v2
    [*] --> draft: store (SiteController@store)
    draft --> provisioning: SiteProvisioner::start (lockForUpdate)
    draft --> active: attach (SiteAttacher::apply)
    provisioning --> active: markSucceeded (PollDeploymentJob)
    provisioning --> error: markFailed
    active --> deploying: ChannelSwitcher::start (lockForUpdate)
    deploying --> active: markSucceeded
    deploying --> error: markFailed
    error --> provisioning: provision retry
    error --> deploying: channel retry
    error --> active: recoverSiteIfLatestFinished (detay GET / sync)
    active --> stopped: SiteLifecycle::deactivate
    stopped --> active: SiteLifecycle::activate
    note right of active: archived durumu tanımlı ama hiçbir kod yazmıyor (S2-B18)
```

Arşiv, `status` değil **soft delete** (`deleted_at`): `SiteController@destroy` → `/sites/archived` → `restore` (Super Admin) veya `purge` (`withTrashed`).

### 1.2 Akışlar (route → controller → servis → job)

| Akış | Zincir | Kilit / tekillik | Audit |
|------|--------|------------------|-------|
| Oluştur (draft) | `POST /sites` → `StoreSiteRequest` → `SiteController@store` (`DB::transaction`) → `SiteDomainSync::sync` | slug/primary DB unique + `NotHeldByArchivedSite` | `site.created` |
| Attach | aynı store, `placement=attach` → `SiteAttacher::apply` (Coolify `getApp`) | **Yok** (uuid tekilliği yok) | `site.created` (snapshot'ta uuid yok) |
| Provision | `POST /sites/{site}/provision` → `SiteProvisioner::start` (lockForUpdate) → `ProvisionSiteJob` (unique 3600 s) → `provisionOnCloudflare` + `provisionOnCoolify` → `PollDeploymentJob` → `markSucceeded/markFailed` | lockForUpdate + job unique | `site.provision_started/succeeded/failed`, `site.cloudflare_ready` |
| Import | `ops:import-coolify-apps [--apply]` → `CoolifyFleetImporter::apply` (satır başına transaction + lockForUpdate) | transaction | `site.import_created/updated` (actor yok) |
| Düzenle | `PUT /sites/{site}` → `UpdateSiteRequest` → `update` → `releaseHostDns` → `applyAliasDns` + `bindAndRedeploy` → `SitePrimaryDomain::followAgentBaseUrl` → `SiteIdentityPusher` | Yok | `site.updated`, `site.domain_changed` |
| Primary terfi | `POST /sites/{site}/domains/{domain}/primary` → `SitePrimaryDomain::promote` | Yok | audit var (`SitePrimaryDomain.php:47`) |
| Kanal geçişi | `POST /sites/{site}/channel` → `SwitchSiteChannelRequest` → `ChannelSwitcher::start` (confirm + sürüm kapısı + lockForUpdate) → `SwitchSiteChannelJob` → `switchOnCoolify` (PATCH → envs → deploy) → `PollDeploymentJob` | lockForUpdate + job unique | `site.channel_switch_started/switched/failed` |
| Toplu kanal | `POST /sites/bulk/channel` → ops job `sites.bulk_channel` → `PacedFanout` → site başına `start()` → **kuyruk** | sweep yalnız `start()` için | site başına |
| Activate / Deactivate | `POST …/activate\|deactivate` → `SiteLifecycle` → Coolify `start`/`stop` → `transition` | Yok | `site.activated/deactivated` |
| Arşiv / Geri yükle | `DELETE /sites/{site}` (soft) · `POST /sites/{site}/restore` | Yok | `site.deleted`, `site.restored` |
| Kalıcı sil | `DELETE /sites/{site}/purge` · `POST /sites/bulk/purge` (JSON → `sites.bulk_purge`) → `SiteLifecycle::purge[Many]` → Coolify DELETE `delete_volumes=true` → `releaseHostDns` → `forceDelete` | Yok | `site.purged` |
| Yayın durumu | `POST …/publish-status` · `/bulk/publish-status` → `SitePublishStateUpdater` → agent `site/status` | CMS'in döndürdüğü değer yansıtılır | `site.published/unpublished` (yalnız gerçek geçişte) |
| Liste | `GET /sites` → `SiteSavedViews::resolve` → `SiteListView::resolve` → `Site::matchingListFilters` → `ListFragment::respond`; tile'lar `SiteListSummary::counts/trend` | — | — (kişisel tercih) |
| Detay | `GET /sites/{site}` (`SiteDetailController`) + lazy `…/admins/panel`, `…/coolify-ops/panel` | — | GET içinde durum yazımı (S2-B14) |
| Adminler | `SiteAdminController` → `SiteAgentClient` (create/invite/resend/reset/(de)activate/delete) | — | `site.admin.*` |

### 1.3 Ana dosyalar

| Dosya | Sorumluluk | Satır | Test |
|-------|------------|-------|------|
| `app/Http/Controllers/Ops/SiteController.php` | liste, CRUD, provision/channel girişi, lifecycle, arşiv, mail uçları | 1355 | `SiteCrudTest`, `SiteArchiveTest`, `SiteLifecycleTest` |
| `app/Models/Site.php` | guard'lar, filtre scope'u, sürüm okuma | 1250 | `Unit/Models/SiteTest` |
| `app/Support/Lists/SiteSavedViews.php` | kayıtlı görünümler, filtre sanitize, bulk scope | 583 | `SiteSavedViewsTest` |
| `app/Services/Sites/SiteProvisioner.php` | provision akışı | 576 | `ProvisionSiteTest`, `ProvisionSiteCloudflareTest` |
| `app/Http/Controllers/Ops/SiteCoolifyOpsController.php` | toplu deploy/pin/channel/purge, panel | 549 | `SiteBulkActionsTest`, `BulkThrottleResilienceTest` |
| `app/Services/Sites/ChannelSwitcher.php` | kanal geçişi | 384 | `ChannelSwitchTest` (17 test) |
| `app/Http/Controllers/Ops/SiteAdminController.php` | CMS admin yönetimi | 253 | `SiteAdminAgentTest` (18) |
| `app/Services/Sites/SiteLifecycle.php` | activate/deactivate/purge | 218 | `SiteLifecycleTest` (14) |
| `app/Http/Controllers/Ops/SiteListPreferencesController.php` | kolon, mod, görünüm | 207 | `SiteListPreferencesTest` (16), `SiteListViewModeTest` (7) |
| `app/Services/Sites/SiteAttacher.php` | mevcut app bağlama | 158 | `CoolifySiteFormTest` (2 attach testi) |
| `app/Http/Controllers/Ops/SitePublishStatusController.php` | yayın durumu | 108 | `SitePublishStateTest` (17) |
| `app/Services/Sites/SiteFilterVerdict.php` | health/app verdict kolonları | 104 | `HealthAppFilterTest` |
| `app/Services/Sites/SiteListSummary.php` | tile sayıları + günlük trend | 85 | `FleetSnapshotTrendTest` (6) |
| `app/Policies/SitePolicy.php` | rol kararları | 79 | `SiteLifecycleTest::test_viewer_cannot_activate_or_purge`, `SiteArchiveTest::test_operator_cannot_restore` |
| `routes/ops/sites.php` | 76 route | 100 | — |

### 1.4 Test kapsamı

Hedefli koşu: `php artisan test --compact --filter='SiteLifecycleTest|SiteArchiveTest|ChannelSwitchTest|SiteListAppHealthCountCostTest|SiteFleetSearchTest|FleetSnapshotTrendTest|SiteAdminAgentTest|SitePublishStateTest'` sonucu **93 passed / 445 assertion (50 s)**.

| Testli | Testsiz (bu rapordaki bulgu) |
|--------|------------------------------|
| provision mutlu yol, 422, retry'da uuid'nin yeniden kullanılması, viewer 403 | provision job timeout → takılı `provisioning` (B07) |
| kanal: confirm, sürüm kapısı, force, `deploying` iken reddetme, PATCH hatası | deploy kapısı meşgulken geçiş; önceden deploy satırı varken `markFailed` (B01, B03); `redis` kuyrukla toplu geçiş (B02) |
| purge: Coolify DELETE, DNS temizliği, Cloudflare 500, Coolify 404, toplu, JSON job | Cloudflare **bağlantı** hatası (B11); apex kaydı (B11) |
| arşiv listesi, restore (super admin), operator restore 403, arşivden purge | `deploying`/`provisioning` iken arşiv (B06) |
| deactivate/activate temel | durdurulmuş sitede deploy/bind (B08); activate sonrası deploy takibi (B12) |
| attach uuid/domain/channel, `needs_review` | aynı uuid'nin ikinci kez bağlanması; Coolify domain çakışması (B04) |
| kayıtlı görünüm, kolon, sıralama, liste maliyeti, arama | geçersiz filtre + `all=1` tehlikeli toplu işlem (B10) |

Not: `phpunit.xml:32` → `QUEUE_CONNECTION=sync`; canlı ise `docker-compose.coolify.yml:30` → `redis`. Kuyruk sırasına bağlı yarışlar test ortamında görünmüyor.

## 2. Bulgular

| ID | Tür | Önem | Bulgu | Kanıt | Etki |
|----|-----|------|-------|-------|------|
| S2-B01 | Hata | Yüksek | Kanal geçişi Coolify `git_branch` + `APP_ENV/DEAMON_CHANNEL` PATCH'ini deploy kapısından ve deploy'dan **önce** yapıyor. Deploy reddedilirse/başarısız olursa `markFailed` durumu `error` yapıyor, `channel` eski kalıyor, Coolify ise yeni dalda duruyor. `desired_channel` hiç temizlenmiyor ve env sync onu tercih ediyor. "Eski kanala geri dön" isteği `from === target` kontrolüne takılıyor. Runbook "switch back" öneriyor ama bu yol kapalı; tek çıkış Coolify Sync ile `channel`'ı Coolify'dan okutmak | `app/Services/Sites/ChannelSwitcher.php:56-62,139-145`; `app/Services/Coolify/CoolifyApplicationService.php:227-231` (gate `deploy()` içinde); `app/Services/Coolify/CoolifyAppEnvSync.php:316-324`; `app/Services/Coolify/CoolifyDeploymentSync.php:226-241` (error→active, desired temizlenmez); `docs/runbooks/channel-switch.md` "Failure notes" | Plane ile Coolify ayrışıyor. Bir sonraki manuel deploy veya auto-deploy, Plane'in başka kanal gösterdiği sitede hedef dalı derliyor |
| S2-B02 | Hata | Yüksek | Toplu kanal geçişi `PacedFanout` sweep'i içinde yalnızca `start()` çağırıyor; asıl Coolify işi `SwitchSiteChannelJob` olarak **kuyruğa** gidiyor ve sweep watermark'ının dışında koşuyor. Host başına eşzamanlı deploy sınırı 1 olduğundan, aynı Coolify host'undaki ikinci siteden itibaren her iş PATCH'ten sonra `CoolifyDeployBusyException` alıyor ve B01 durumuna düşüyor. Testler `sync` kuyrukla (iş sweep içinde koşuyor) geçtiği için hata görünmüyor. Doğrulanmadı (canlı): kod yolu net; doğrulamak için `Queue::fake()` + işleri sweep dışında sırayla `handle` eden bir test yazılmalı | `app/Services/Ops/OpsJobRunner.php:146-153`; `app/Services/Sites/ChannelSwitcher.php:123` (`SwitchSiteChannelJob::dispatch` transaction sonrası); `app/Services/Coolify/CoolifyDeployGate.php:38-68`; `config/ops.php:165`; `phpunit.xml:32` ↔ `docker-compose.coolify.yml:30` | Filo genelinde kanal terfisinde toplu ayrışma ve toplu `error` |
| S2-B03 | Hata | Yüksek | Job içindeki `catch`, `markFailed`'e `$fresh->deployments()->latest('id')->first()` veriyor. Hata yeni deploy satırı yaratılmadan önce olduysa (PATCH hatası, kapı meşgul, create hatası) **ilgisiz eski satır** `failed` + `error_message` + `finished_at=now` ile eziliyor. Geçmişteki başarılı bir deploy "son 24 saatte başarısız" sayılıyor ve `DiagnoseDeploymentJob` (saved hook) yanlış satırı teşhis edip `AUTO_SAFE` düzeltme deneyebiliyor (zincirin son adımı Doğrulanmadı) | `app/Jobs/SwitchSiteChannelJob.php:41-51`; `app/Jobs/ProvisionSiteJob.php:41-52`; `ChannelSwitcher.php:273-281`; `app/Services/Sites/SiteProvisioner.php:324-332`; test boşluğu `tests/Feature/Sites/ChannelSwitchTest.php:386` (önceki deploy satırı yok) | Deploy geçmişi bozuluyor, "başarısız dağıtımlar" KPI'ı şişiyor, otomatik düzeltme yanlış sebeple tetikleniyor |
| S2-B04 | Risk | **Kritik** | Attach aynı Coolify uygulamasını birden çok Plane sitesine bağlayabiliyor: `coolify_app_uuid` yalnız indeksli (unique değil), `attach_app_uuid` için tekillik kuralı yok, `attachableApps()` zaten bağlı olanları elemiyor. Attach, Coolify'dan gelen `primary_domain`'i doğrulamadan yazıyor; çakışırsa `QueryException` yakalanmıyor ve 500 dönüyor (yakalanan tek istisna `SiteProvisionException`). İkiz satırlardan birini kalıcı silmek `DELETE …?delete_volumes=true` gönderiyor | `database/migrations/2026_08_13_071300_create_sites_table.php:19,33`; `app/Http/Requests/Ops/Concerns/ValidatesCoolifySiteTargets.php:27`; `app/Services/Sites/SiteAttacher.php:55-57,93-102,126-144`; `SiteController.php:502-510,521`; `app/Services/Sites/SiteLifecycle.php:146-161`; test yok (`grep attach_app_uuid tests` → yalnız `CoolifySiteFormTest`) | Canlı müşteri verisi (MySQL/Redis volume) iki tıkla kaybolabilir |
| S2-B05 | Risk | Yüksek | Canlı sitede (uuid dolu) Coolify bağlantı/sunucu/proje/ortam/git seçimleri formda açık ve `update` hepsini DB'ye yazıyor. Coolify uygulaması taşınmıyor, sonraki çağrılar yanlış instance'a gidiyor. Purge bu durumda 404 alıyor, bunu "zaten silinmiş" sayıp `forceDelete` ediyor ve gerçek uygulama volume'larıyla birlikte sahipsiz çalışmaya devam ediyor | `resources/views/ops/sites/_form.blade.php:185-188,245,256,297`; `SiteController.php:980-992`; `ValidatesCoolifySiteTargets.php:55-110` (uuid/durum kilidi yok); `SiteLifecycle.php:153-158` | Yetim Coolify uygulaması, hayalet deploy'lar, sahipsiz veri |
| S2-B06 | Hata | Yüksek | Arşiv (soft delete) durumdan bağımsız: `provisioning`/`deploying` iken de çalışıyor. SoftDeletes kapsamı yüzünden `PollDeploymentJob`, `SwitchSiteChannelJob`, `ProvisionSiteJob` siteyi bulamayıp sessizce çıkıyor. Deploy satırı `in_progress`, site `deploying`/`provisioning` kalıyor ve restore sonrasında takılı kalıyor. Açık satır restore sonrası aynı host'taki bütün deploy'ları kilitleyebilir (Doğrulanmadı: `GET /jobs` yeniden tetiklemesinin bu satırı kapsayıp kapsamadığı). Arşivli sitenin Coolify uygulaması çalışmaya ve auto-deploy almaya devam ediyor ama sağlık taraması dışında kalıyor | `SiteController.php:1112-1132`; `app/Jobs/PollDeploymentJob.php:45-48`; `SwitchSiteChannelJob.php:36-38`; `ProvisionSiteJob.php:36-38`; `app/Jobs/DispatchSiteHealthChecksJob.php:19-21`; `CoolifyDeployGate.php:93-117`; `lang/tr/sites.php:902` ("Coolify'e dokunulmaz") | İzlenmeyen canlı site; restore sonrası kilitli site/host |
| S2-B07 | Hata | Yüksek | Takılı `provisioning`/`deploying` durumu için kurtarma yok. Job `$timeout=120`, `tries=1` ve `failed()` yok: timeout ile öldürülen iş `catch` bloğuna ulaşmıyor. `canBeProvisioned()` yalnız `draft/error` kabul ediyor, kanal geçişi `deploying/provisioning` durumunu reddediyor, `provisioning` için activate/deactivate yok. Otomatik iyileştirme (`recoverSiteIfLatestFinished`) yalnız `error` durumuna bakıyor. Watchdog yok (`grep SiteStatus::Provisioning app` → yalnız guard'lar) | `ProvisionSiteJob.php:17-21,41-52`; `app/Models/Site.php:464-467`; `ChannelSwitcher.php:76-78`; `CoolifyDeploymentSync.php:226-230`; [01 §4.3](01-envanter.md#43-joblar) (`failed()` yok) | Operatör tinker/SSH'e muhtaç; site UI'dan yönetilemiyor |
| S2-B08 | Hata | Yüksek | Durdurulmuş sitede redeploy, pin ve follow-head durum kontrolü yapmıyor; Deploy menüsü `stopped` durumda da görünüyor. Toplu deploy yalnız uuid'e göre filtreliyor ve `all=1` ile stopped/draft/error sitelerin hepsini kapsıyor. Domain ekleme/terfi `canBindCoolifyDomains` (Stopped dahil) → `bindAndRedeploy` ile siteyi başlatıyor. Deactivate auto-deploy'u kapatmıyor, bu yüzden git push durdurulmuş siteyi geri getirebilir (Doğrulanmadı: Coolify'ın durdurulmuş uygulamada auto-deploy davranışı). Tüm bu durumlarda Plane `stopped` göstermeye devam ediyor | `app/Services/Sites/CoolifyDeploySettings.php:87-160`; `resources/views/ops/sites/_header-actions.blade.php:6`; `SiteCoolifyOpsController.php:485-487`; `Site.php:502-517`; `app/Services/Sites/SiteLanding.php:41-61`; `SiteLifecycle.php:30-38` | Müşterinin kapatılmasını istediği site sessizce yeniden yayına giriyor |
| S2-B09 | Risk | Orta | Öneri-B6 **hâlâ Kısmen**: durdurulmuş ve draft siteler 5–15 dakikada bir agent + Coolify taramasına giriyor. Durdurulan sitenin bir sonraki taraması `health_unhealthy` yazıyor, sonuç "Sağlıksız" tile/KPI'ına ve `SITE_DOWN` mailine gidiyor (`scope=plane` alıcıları) | `DispatchSiteHealthChecksJob.php:19-27`; `app/Services/Mail/SiteHealthMailNotifier.php:21-33`; `app/Services/Sites/SiteFilterVerdict.php:49-63`; `app/Services/Sites/SiteListSummary.php:30`; `app/Services/Mail/PlatformNotificationCatalog.php:80-85` | Yanlış alarm, KPI gürültüsü, alarm körlüğü |
| S2-B10 | Güvenlik | Yüksek | Geri alınamaz kalıcı silme `forceDelete` = `canWriteOps` (operatör), toplu `all=1` ile tüm filoyu kapsayabiliyor; geri alınabilir restore ise Super Admin istiyor. Onay yalnız evet/hayır: isim yazdırma yok, sunucu tarafında `expected_count`/ID özeti yok, boyut sınırı yok. Geçersiz filtre değeri "düşürülüp genişletiliyor"; bu kural tehlikeli toplu işlemde de geçerli (ör. elle üretilmiş `filter_channel=develop` → tüm filo) | `app/Policies/SitePolicy.php:40-48`; `app/Http/Requests/Ops/BulkSiteIdsRequest.php:19-40`; `SiteCoolifyOpsController.php:272-296,519-524`; `Site.php:908-922`; `docs/modules/ops-sites.md:215` ("typo widens"); `resources/views/ops/sites/show.blade.php:269` | Tek hatalı tıkla filo çapında veri kaybı. C5 (rol matrisi) açık |
| S2-B11 | Hata | Orta | Purge'de Cloudflare **bağlantı** hatası (`ConnectionException`) yakalanmıyor: `releaseHostDns` yalnız `SiteProvisionException`, `removeHostRecords` yalnız `CloudflareApiException` yakalıyor. Bu durumda Coolify uygulaması silinmiş, Plane satırı ise duruyor ve audit yazılmıyor. Yeniden denenirse 404 → devam ettiği için kurtarılabilir. Ayrıca apex kayıtları hiç silinmiyor (`host === zoneName → continue`); müşteri apex'i origin IP'ye işaret etmeye devam ediyor | `app/Services/Sites/SiteLanding.php:240-244`; `app/Services/Cloudflare/CloudflareZoneService.php:294-319` (`:301-303` apex atlanıyor); `app/Services/Cloudflare/CloudflareClient.php:165-169`; test yalnız HTTP 500: `tests/Feature/Sites/SiteLifecycleTest.php:198` | Yarım purge; sarkık DNS kaydı (başka site/Traefik 404) |
| S2-B12 | Hata | Orta | Activate, Coolify `/start` sonucunu (`CoolifyDeployResult`) atıyor: deploy satırı yok, poll yok, deploy kapısı yok, env sync yok. Durum başlatmanın sonucu beklenmeden `active` yapılıyor | `SiteLifecycle.php:20-28,110-126`; `CoolifyApplicationService.php:186-189` ↔ `:227-231` | Başlatma başarısız olsa da site "active"; işler widget'ında görünmüyor |
| S2-B13 | Hata | Orta | İçe aktarma update'i Plane'e ait alanların üzerine yazıyor: `name` Coolify uygulama adıyla değişiyor ve sonraki sağlık taraması bu adı CMS'e itiyor. `primary_domain` Coolify'ın ilk host'u oluyor. `status` da değişiyor: `stopped` → `exited` → `error`; `starting` → `draft` (bu durumda formda slug/kanal kilidi açılıyor). Durum geçişi `transitionTo` kullanmadan doğrudan atanıyor | `app/Console/Commands/ImportCoolifyApps/CoolifyFleetImporter.php:240,249-259`; `CoolifyFleetClassifier.php:110-127,363-371`; `app/Services/Agent/SiteHealthChecker.php:44-46`; `SiteController.php:995-998`; `docs/modules/ops-sites.md:186-193` (Plane-owned) | Yeniden içe aktarma sessizce müşteri vitrin adını ve durumu bozuyor |
| S2-B14 | Eksik | Orta | Audit'siz durum mutasyonları: (a) `GET /sites/{site}` her açılışta `recoverSiteIfLatestFinished` ile `error→active` yazıyor (viewer bile tetikliyor); (b) Coolify Sync/envanter `channel`, hedef uuid'ler ve `channel_needs_review` değerlerini audit'siz değiştiriyor | `app/Http/Controllers/Ops/SiteDetailController.php:34-39`; `CoolifyDeploymentSync.php:226-241`; `app/Services/Coolify/CoolifySiteTargetSync.php:68-104,120-135` (`grep audit` boş) | "Kim/ne değiştirdi" sorusu cevapsız kalıyor; GET'in yan etkisi var |
| S2-B15 | Borç | Orta | Kanal geçişi `git_commit_sha: HEAD` gönderiyor (pin'i kaldırıyor) ama `coolify_pinned_sha`/`coolify_auto_deploy` yansısını güncellemiyor. Liste bir sonraki Sync'e kadar `auto_deploy=pinned` filtresinde siteyi pin'li gösteriyor | `ChannelSwitcher.php:134-139`; `CoolifyDeploySettings.php:274-293` (yansıtma yalnız burada) | Yanlış pin rozeti ve filtre sonucu |
| S2-B16 | UX | Orta | CMS admin şifre sıfırlama onaysız çalışıyor; bu işlem CMS'te 2FA'yı ve oturumları da temizliyor. Deactivate/delete danger onaylı, resend onaylı; reset formunda `data-confirm` yok | `resources/views/ops/sites/_admins_body.blade.php:69`; `docs/modules/site-admins.md` ("clears 2FA + sessions") | Müşteri admini yanlış tıkla dışarıda kalıyor |
| S2-B17 | Güvenlik | Düşük | Oluşturulan/sıfırlanan admin şifresi flash olarak `sessions` tablosuna (DB) şifrelenmeden yazılıyor; bir sonraki isteğe kadar orada duruyor ve DB yedeklerine giriyor | `SiteAdminController.php:223-233`; `config/session.php:21,50` (`SESSION_ENCRYPT` varsayılanı false) | Açık metin şifre depoda. Güvenlik ajanına devredilir |
| S2-B18 | Borç | Düşük | `SiteStatus::Archived` tanımlı ama hiçbir kod yazmıyor (arşiv = soft delete). Durum filtresinde bu seçenek her zaman boş liste döndürüyor. Doküman "deactivate → archived" diyor, kod `stopped` yapıyor | `app/Enums/SiteStatus.php:13,42,44`; `SiteController.php:220` (`statuses`); `docs/modules/ops-sites.md:43` | Kafa karışıklığı, ölü filtre |
| S2-B19 | Eksik | Düşük | Purge audit'i minimal (slug/name/domain/status/uuid). Kanal, sunucu, bağlantı ve son deploy özeti yok; `deployments` cascade ile siliniyor. Attach audit'inde `coolify_app_uuid` yok (`auditSnapshot` alanları) | `SiteLifecycle.php:208-217`; `database/migrations/2026_08_13_071302_create_deployments_table.php:13`; `SiteController.php:1192-1211` | Olay sonrası inceleme verisi kayboluyor |
| S2-B20 | Borç | Düşük | Operatöre giden sabit metinler `__()` dışında (todo B10 kuralına aykırı): attach hataları, hedef doğrulama mesajları (TR), `StoreSiteRequest::messages()` (EN) | `SiteController.php:505`; `SiteAttacher.php:113,117`; `ValidatesCoolifySiteTargets.php:82,87,96,109`; `app/Http/Requests/Ops/StoreSiteRequest.php:66-74` | Dil tutarsızlığı |
| S2-B21 | Borç | Düşük | Admin silme audit'indeki e-posta istemcinin gönderdiği `admin_email` alanından geliyor, CMS yanıtından değil | `SiteAdminController.php:151-161` | Audit yanlış kişiyi gösterebilir |
| S2-B22 | Perf | Düşük | Tam sayfa liste render'ı: `reportedDeamonVersions()` tüm sitelerin `last_health_payload` alanını belleğe alıyor, tema seçenekleri JSON üzerinde distinct çalıştırıyor, tile'lar 5 ayrı count sorgusu yapıyor. Bugünkü ölçekte (≤200 site) sorun değil; bölge (region) istekleri bunları zaten atlıyor | `SiteController.php:131-136,194-199`; `Site.php:1050-1081`; `SiteListSummary.php:26-35` | Filo büyüdükçe ilk açılış yavaşlar |

Önem kırılımı: **Kritik 1 · Yüksek 8 · Orta 7 · Düşük 6 = 22.**

### 2.1 Önceki inceleme maddeleri (bu alan)

| Önceki ID | Durum (15'ten) | Bu rapordaki karşılık |
|-----------|----------------|-----------------------|
| Öneri-B6 (stopped'ı taramadan çıkar) | Kısmen, değişmedi | S2-B09 → S2-O08 |
| A2 (deployment reconcile) | Yapılmadı | S2-B06/B07 → S2-O07 genişletir |
| A3 (import sonrası secret) | Yapılmadı | Otonomi tablosu |
| A4 (provision sonrası ilk admin daveti) | Yapılmadı | S2-O18 |
| A6 (kanal terfi otomasyonu) | Yapılmadı | S2-O01/O02 önkoşul: toplu geçiş bugün güvenli değil |
| Öneri-B5 (müşteri kartı) | Yapılmadı | §5 |
| Öneri-B7 (staging klon) | Yapılmadı | §5 (spec gerekir) |
| Öneri-B10 (incident/not) | Yapılmadı | §5 |
| Öneri-B11 (deploy dondurma) | Yapılmadı | §5; S2-O06 guard matrisi zemin hazırlar |
| C5 (rol matrisi) | Yapılmadı | S2-B10 |
| E6 (uzun controller) | Yapılmadı (kötüleşti: 1355 satır) | `SiteController` mail/mailbox/platform-mail uçlarını da taşıyor (`app/Http/Controllers/Ops/SiteController.php:739-961`) |

`docs/todo_sites_backend.md` B1–B11 ve `docs/todo_sites_uiux.md` davranış maddeleri (U1–U3, U5, U7) kodda işaretli ve doğrulandı (ör. `SiteLifecycle.php:72-101` B8, `SiteArchiveTest` B11). Tekrar açılmadı.

## 3. İyileştirme ve güncelleme önerileri

Öncelik puanı = Etki × 2 − Risk + (S=3, M=2, L=1, XL=0).

| ID | Öneri | Çözdüğü bulgu | Etki (1–5) | Efor | Risk (1–5) | Öncelik puanı | Kabul kriteri |
|----|-------|---------------|-----------|------|-----------|---------------|---------------|
| S2-O09 | Kalıcı silmeyi sertleştir: `forceDelete` → Super Admin (config ile geri açılabilir). Tekil purge'de site slug'ı yazdırılsın. Toplu purge'de sunucu `expected_count` + ID özeti doğrulasın, `ops.sites.bulk_purge_max` (ör. 10) sınırı olsun. Tehlikeli toplu işlemlerde geçersiz filtre değeri 422 dönsün, genişletilmesin | B10 | 5 | S | 1 | **12** | Operatör purge → 403; slug eşleşmezse 422; `expected_count` uyuşmazsa 422 ve Coolify'a istek gitmez; `filter_channel=develop` + `all=1` purge → 422 (feature testleri) |
| S2-O04 | Attach tekilliği: `attach_app_uuid` için `withTrashed` benzersizlik kuralı; `attachableApps()` bağlı uuid'leri elesin; `(coolify_connection_id, coolify_app_uuid)` partial unique index (NULL serbest). Coolify'dan gelen domain `NotHeldByArchivedSite` + unique kurallarından geçsin; `QueryException` alan hatasına çevrilsin | B04 | 5 | S | 2 | **11** | İkinci attach 422 (alan hatası); açılır listede bağlı uuid yok; çakışan Coolify domain'i 500 değil 422; migration mevcut veride çakışma raporu üretir |
| S2-O01 | Kanal geçişini ön kontrollü ve telafili yap: PATCH'ten önce `CoolifyDeployGate::assertCanStartDeploy`; PATCH sonrası deploy başarısız olursa önceki `git_branch`/env değerlerini geri yaz (telafi) ya da `coolify_branch` yansısı tutup "geri dön" kontrolünü Coolify dalına göre yap; `markFailed` `desired_channel`'ı temizlesin veya "ayrışmış" bayrağı koysun | B01 | 5 | M | 2 | **10** | Kapı meşgulken Coolify'a PATCH gitmez (`Http::assertNotSent`); deploy hatasında Coolify dalı eski değere döner ya da UI "Coolify beta, Plane main — hizala" gösterir; eski kanala dönüş reddedilmez |
| S2-O02 | Toplu kanal geçişini kuyruk gerçeğiyle uyumlu yap: `SwitchSiteChannelJob` host başına seri çalışsın (`WithoutOverlapping("coolify-host:{id}")` + kapı meşgulse `release(backoff)`, ekstra deneme yok) ya da ops job içinde sweep kapsamında `switchOnCoolify` senkron çağrılsın. Kuyruğun async olduğu test eklensin | B02 | 5 | M | 2 | **10** | Aynı host'ta 3 sitelik toplu geçiş async kuyrukla testte 3/3 `deploying→active`; hiçbir site `error` + ayrışma durumuna düşmez |
| S2-O03 | `markFailed` asla "sitenin son satırını" almasın: job, kendi denemesinin satır id'sini taşısın (yoksa yeni `failed` satırı yaratsın). `finished_at` dolu satır yeniden açılmasın (guard) | B03 | 4 | S | 1 | **10** | Önceden `finished` satırı olan sitede PATCH hatası → eski satır değişmez, yeni `failed` satırı eklenir (test) |
| S2-O05 | Uuid atanmış sitede Coolify hedeflerini kilitle (form disabled + sunucuda `prohibited`). Taşıma için ayrı bir Super Admin "yeniden hedefle" akışı: yeni bağlantıda `getApp` doğrulansın. Purge'de 404 geldiğinde varsayılan bağlantıda da arama yapılsın; "uygulama bulunamadı, yalnız Plane kaydı silinsin mi?" onayı istensin | B05 | 4 | S | 2 | **9** | Active sitede `coolify_connection_id` değişikliği 422; purge 404 → ikinci onay olmadan `forceDelete` yok |
| S2-O06 | Merkezi yaşam döngüsü guard matrisi (`SiteLifecycleGuard::assert(action, site)`). Arşiv/purge/deploy/pin/bind işlemleri `provisioning`/`deploying` durumunda engellensin. `stopped` sitede deploy/bind engellensin veya "önce etkinleştir" densin. Deactivate auto-deploy'u kapatıp önceki değeri saklasın, activate geri yüklesin. Toplu işlemler guard'a uymayan siteyi `atlandı` saysın | B06, B08 | 4 | M | 2 | **8** | `deploying` sitede arşiv 422; stopped sitede redeploy 422; toplu deploy `all=1` stopped siteleri `atlandı` sayar; deactivate sonrası Coolify `is_auto_deploy_enabled=false` |
| S2-O07 | Takılı durum watchdog'u: `ops:reconcile-site-states` (10 dk, `withoutOverlapping`, `onOneServer`). N dakikadan eski `provisioning`/`deploying` veya açık deploy satırı için Coolify okunsun → `markSucceeded/markFailed`. Provision/switch job'larına `failed()` eklensin (timeout'ta `markFailed`). A2 ile birleşir | B07, B06 | 4 | M | 2 | **8** | Timeout simülasyonunda site `error`'a düşer; 30 dk'dan eski `deploying` bir sonraki çalışmada çözülür; her düzeltme `site.state_reconciled` audit'i yazar |
| S2-O08 | Öneri-B6'yı tamamla: `DispatchSiteHealthChecksJob` yalnız `active`/`deploying`/`error` sitelerini taramalı; `SiteHealthMailNotifier` stopped sitede mail atmamalı; `constrainUnhealthy` `stopped` durumunu hariç tutmalı | B09 | 3 | S | 1 | **8** | Stopped site için `CheckSiteHealthJob` kuyruğa girmez; deactivate sonrası `SITE_DOWN` maili yok; tile sayısı değişmez (test) |
| S2-O12 | İçe aktarma update'inde Plane'e ait alanlar korunmalı: `name`, `primary_domain` ve mevcut `status` yazılmasın (ayrışma notlara/rapora düşsün); var olan uuid'ye `draft` asla yazılmasın. `--adopt` bayrağı açık kararla üzerine yazsın | B13 | 3 | S | 1 | **8** | Yeniden `--apply` sonrası ad/durum değişmez; dry-run tablosunda "ayrışma" sütunu görünür |
| S2-O13 | Sessiz mutasyonları audit'le: recover (`site.status_recovered`) ve Sync kanal/hedef değişimi (`site.coolify_targets_synced`, before/after) audit'e yazılsın. `recoverSiteIfLatestFinished` detay GET'inden çıkarılıp poll/sync/watchdog'a taşınsın | B14 | 3 | S | 1 | **8** | Detay GET'i `sites` tablosuna yazmaz (query log testi); Sync kanal değişimi audit satırı üretir |
| S2-O10 | Purge sağlamlığı: `releaseHostDns` `Throwable` yakalasın. Plane'in açtığı zone'larda apex A kaydı da silinsin (içerik = origin IP ve başka Plane sitesi kullanmıyorsa). Audit'e kanal, sunucu, bağlantı, host'lar ve son 5 deploy özeti eklensin | B11, B19 | 3 | S | 2 | **7** | `ConnectionException` fake'inde purge tamamlanır, `dns_error` dolu gelir; apex kaydı için DELETE gönderilir (koşullu); audit `after` içinde yeni alanlar var |
| S2-O11 | Activate deploy yolundan geçsin: kapı + env sync + `deployments` satırı (trigger `start`) + poll. Durum `deploying` → `active` olsun | B12 | 3 | S | 2 | **7** | Activate sonrası widget'ta satır görünür; start başarısızsa site `error` olur |
| S2-O14 | Kanal geçişi başarılı olunca `mirrorOntoSite(auto_deploy?, sha=HEAD)` çağrılsın | B15 | 2 | S | 1 | **6** | Pin'li sitede geçiş sonrası `coolify_pinned_sha=null` (test) |
| S2-O15 | Admin reset'e danger onayı eklensin; tek seferlik şifre session yerine `Crypt` ile şifrelenip 60 s TTL'li cache anahtarında tutulsun | B16, B17 | 2 | S | 1 | **6** | `ConfirmMatrixTest` reset formunu kapsar; `sessions.payload` içinde açık şifre yok |
| S2-O18 | Provision sonrası ilk admin daveti (A4): create formunda isteğe bağlı müşteri e-postası; ilk başarılı health sonrası `createAdmin(password_mode=invite)` tek sefer çalışsın | — (A4) | 3 | M | 2 | **6** | E-posta girilmiş site ilk `ok` health'te `site.admin.created` (`invite`) yazar; ikinci health tekrar denemez |
| S2-O16 | Temizlik: `SiteStatus::Archived`'ı ya kaldır ya da soft-delete ile eşle; doküman düzeltilsin; sabit metinler `lang/*` altına taşınsın; admin silme audit'i CMS yanıtından e-posta alsın | B18, B20, B21 | 1 | S | 1 | **4** | Durum filtresinde ölü seçenek yok; `grep` ile controller/request içinde `__()` dışı TR/EN metin kalmaz |
| S2-O17 | `reportedDeamonVersions()` 60 s cache'lensin (App fix counts deseni) veya `deamon_version` kolonu denormalize edilsin | B22 | 1 | S | 1 | **4** | Tam sayfa render'ında `last_health_payload` toplu okuması cache isabetinde 0 sorgu |

Önerilen sıra (bağımlılıkla): O09 → O04 → O03 → O01 + O02 (birlikte) → O05 → O07 → O06 → O08 → kalanlar. A6 (kanal terfi otomasyonu) O01+O02 tamamlanmadan açılmamalı.

## 4. Otonomi fırsatları

| Akış | Bugünkü seviye | Hedef | Tetik | Ön koşul | Güvenli otomatik adım | Doğrulama | Geri alma | Guardrail/bütçe | Kill switch | Audit | Bildirim |
|------|----------------|-------|-------|----------|-----------------------|-----------|-----------|-----------------|-------------|-------|----------|
| Takılı `provisioning`/`deploying` | L0 (UI'da görünmez kurtarma) | L4 | Schedule 10 dk; durum yaşı > `ops.lifecycle.stuck_minutes` (30) | Site trashed değil; son 30 dk'da başka düzeltme yok | Coolify `getDeployment`/son deploy'u oku → `markSucceeded`/`markFailed`; açık satır yoksa `error` | Sonraki çalışmada durum terminal; `deployments.finished_at` dolu | `error` → operatör retry; otomatik ikinci deneme yok | Çalışma başına ≤20 site, host başına 1 Coolify okuması/5 s (`CoolifyRateGuard`) | `ops.lifecycle.reconcile_enabled` | `site.state_reconciled` (önce/sonra, sebep) | Jobs widget satırı + günlük özet |
| Kanal geçişi ayrışması (Plane≠Coolify dalı) | L1 (Sync sonrası görünür) | L3 → L4 | Switch `markFailed` veya Sync'te `git_branch ≠ channel` | Uuid var; `deploying` değil | L3: "Coolify'ı Plane kanalına hizala" tek tık (PATCH geri al, deploy yok). L4: deploy hiç başlamadıysa otomatik telafi | GET app → `git_branch == channel` | Ters PATCH (önceki dal audit'te saklı) | Site başına günde 1 otomatik telafi | `ops.channel_switch.auto_compensate` | `site.channel_compensated` | Flash + widget |
| Durdurulmuş siteyi taramadan çıkarma (Öneri-B6) | L1 | L4 | Deactivate / schedule | `status=stopped` | Agent + Coolify inspect atlanır; mail bastırılır | Stopped sitede `last_health_at` ilerlemez, mail yok | Activate taramayı geri açar | — | `ops.agent.skip_stopped` | Yok (durum audit'i yeterli) | Yok |
| Yetim/ikiz tespiti (Coolify app ↔ Plane satırı) | L0 | L2 → L3 | Günlük envanter senkronu | Coolify bağlantısı sağlıklı | Rapor: Plane'de olmayan Deamon app'leri, uuid'si 404 dönen siteler, aynı uuid'li satırlar; L3: "Plane kaydını sil" / "İçe al" tek tık | Rapor tekrarında aynı madde kalmaz | Salt okunur; L3 eylemleri mevcut akışların audit'iyle | Silme asla otomatik değil | `ops.fleet.orphan_scan` | `fleet.orphan_scan` özet satırı | Fleet dikkat kartı |
| Arşivli ama çalışan site | L1 (arşiv sayfası) | L3 | Arşivde N gün (7) + Coolify app `running` | Site trashed; uuid var | Öneri: "Coolify'da durdur" tek tık (stop + auto-deploy kapat) | GET app `exited`/`stopped` | Restore + activate | Otomatik stop yok (müşteri kesintisi) | — | `site.archived_stopped` | Arşiv sayfası rozeti |
| Provision sonrası ilk admin daveti (A4) | L0 | L4 | İlk `ok` health, `seed_admin_email` dolu | CMS ≥ 1.2.18; davet daha önce gönderilmemiş | `createAdmin(password_mode=invite)` tek sefer | Admin listesinde e-posta, `password_is_set=false` | Super Admin admin'i siler | Site başına 1 deneme + 1 retry | `ops.provision.auto_invite` | `site.admin.created` (`invite`, `auto=true`) | Flash yok; widget + audit |
| İçe aktarma sonrası secret (A3) | L0 | L4 | `--apply` sonrası `missing` bucket | Uuid var, site `active` | `SiteAgentSecretSweep` (rotate=false) + tek redeploy kuyruğu | Sonraki health 200 → `ok` bucket | Secret'ı silme yok; manuel rotate | Çalışma başına ≤25 site, `PacedFanout` | `--no-inject-secret` / config | `site.agent_secret_injected` | Komut çıktısı + widget |
| `error → active` iyileşmesi | L4 (örtük; detay GET'inde, audit'siz) | L4 (görünür) | Poll/Sync/watchdog | Son deploy `finished` | Mevcut `recoverSiteIfLatestFinished` | Durum `active` | Yok (salt düzeltme) | — | Mevcut | `site.status_recovered` (yeni) | Yok |

## 5. Yeni özellik fikirleri (bu alana özgü)

- **Tutarlılık (drift) paneli:** Plane ↔ Coolify karşılaştırması: dal, pin, auto-deploy, durum (`running` vs `stopped`), domain listesi. Satır başına "hizala" düğmesi (S2-O01, O05'in UI yüzü).
- **Kalıcı silme önizlemesi:** Silinecekleri listeler: Coolify app + volume adları, DNS kayıtları (apex dahil), mail binding'leri, tema kurulumları, deploy geçmişi sayısı. Silmeden önce bu listeyi JSON olarak audit'e yazar.
- **Site yaşam döngüsü zaman çizelgesi:** Detayda durum geçişleri, kanal geçişleri, arşiv ve restore tek akışta (audit'ten derlenir).
- **Bakım modu / planlı durdurma (Öneri-B11 ile):** Deactivate'e süre ve not; süre bitince hatırlatma. Taramadan çıkarma (S2-O08) bu bayrağa bağlanır.
- **Toplu işlem önizlemesi:** `all=1` gönderilmeden önce "şu 12 site etkilenecek" listesi ve ID özeti (S2-O09 ile aynı sözleşme).
- **Müşteri kartı (Öneri-B5):** Siteyi müşteri iletişimine bağlar; A4 davet e-postasının kaynağı olur. Kapsam: yalnız iç ops (multi-tenant değil).
- **Olay notu (Öneri-B10):** Site detayında zaman damgalı notlar; purge audit'ine eklenir.

## 6. CMS handoff

Yok. Bu alandaki öneriler mevcut agent uçlarıyla (`admins`, `site/status`, `site/identity`, `health`) yapılabiliyor. S2-O18 CMS ≥ 1.2.18 davet ucunu kullanıyor, yeni uç gerekmiyor.

## 7. Açık sorular

| # | Soru | Önerilen default |
|---|------|------------------|
| 1 | Kalıcı silme kimin yetkisinde olmalı? | Super Admin; operatör yalnız arşivler. Toplu purge için ayrıca sayı sınırı (10) |
| 2 | Başarısız kanal geçişinde Coolify dalı otomatik geri alınsın mı? | Deploy hiç başlamadıysa (kapı/PATCH/env hatası) evet, otomatik telafi. Deploy başladıysa hayır; "ayrışmış" durumu gösterilip tek tıkla hizalatılsın |
| 3 | Arşiv Coolify uygulamasını durdurmalı mı? | Evet ama onaylı: arşiv yalnız `stopped` sitede serbest; `active` sitede "önce durdur" seçeneği sunulsun |
| 4 | Purge müşteri apex A kaydını silsin mi? | Yalnız içerik Plane origin IP'si ise ve zone'u başka Plane sitesi kullanmıyorsa |
| 5 | Durdurulmuş siteler hiç taranmasın mı? | Agent taraması hiç yapılmasın; Coolify inspect günde 1 yapılsın (beklenmedik `running` durumunu yakalamak için) |
| 6 | İçe aktarma Plane'e ait alanların (ad, primary, durum) üzerine yazsın mı? | Hayır; yalnız `--adopt` ile ve dry-run'da ayrışma sütunu gösterilerek |
| 7 | `SiteStatus::Archived` kalsın mı? | Kaldırılsın (arşiv = soft delete tek kaynak); enum geçiş testleri güncellensin |
