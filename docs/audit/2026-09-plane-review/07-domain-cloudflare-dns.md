# 07 — Domain, Cloudflare ve DNS

**Tarih:** 2026-09-24 · **HEAD:** 8484d37 · **Hazırlayan:** S6 — Domain, Cloudflare ve DNS denetçisi (Dalga 1)
**Kapsam:** M5 ([01-envanter.md §11](01-envanter.md)): `app/Services/Cloudflare/**`, `app/Services/Domains/**`, `app/Services/Sites/{SiteDomainReconciler,SiteDomainSync,SitePrimaryDomain,SiteLanding}`, `DomainController`, `SiteDomainController`, `SiteCloudflareController`, `CloudflareOpsController`, `SiteDomain`/`CloudflareSetting`/`CloudflareDnsDefault`, `*Domain*` request'leri, `resources/views/ops/{domains,cloudflare}`, `sites/{_domains,_cloudflare,_landing}`, `public/js/{sites-cloudflare,sites-aliases,sites-landing}.js`, `routes/ops/{domains,cloudflare}.php`. Temas noktaları: `CoolifySiteSync`, `SiteAppHealthFixer::bindDomains`, `SiteLiveProbe`, `CoolifyDeploySettings::redeploy`.
**Kapsam dışı:** Coolify deploy kuyruğu iç işleyişi (deploy ajanı), mail/Hostinger akışı, CMS kodu, canlı Cloudflare/Coolify sorgusu (hiç istek atılmadı). Registrar API, WAF/SSL/cache ayarları spec'te "Out" ([cloudflare-provision spec](../../superpowers/specs/2026-09-10-cloudflare-provision-design.md) §Scope).

Doğrulama: `php artisan test --compact --filter='Domain|Cloudflare|SiteLandingFlow|SiteAliasRows'` → **132 geçti / 0 başarısız** (871 assertion, 69 sn).

## Özet

- **Kritik — DNS şablonu mevcut müşteri kayıtlarını eziyor.** `upsertRecord` aynı ad+tipteki *ilk* kaydı bulup içeriğini değiştiriyor; TXT `@` (SPF ya da doğrulama kaydı), `_dmarc` ve öncelik 5/10 MX kayıtları müşterinin eski değerleriyle karşılaştırılmadan PUT ediliyor. Hostinger mail şablonu her zaman uygulanıyor; `mail_template_enabled` hiçbir yerde okunmuyor (S6-B01).
- **Yüksek — "Bağlı" rozeti gerçeği yansıtmıyor.** Toplu bind, `bind_domains` fix'i ve Coolify Sync auto-rebind yalnız PATCH atıyor, redeploy yapmıyor. Plane'in kendi yorumuna göre bu durumda host yayına girmiyor. Yine de `verified_at` dolduruluyor. Sütun hiçbir yerde sıfırlanmıyor ve hiç PATCH atılmadığı durumlarda da set ediliyor (S6-B03…B06).
- **Yüksek — `.com.tr` / `.co.uk` gibi 2 etiketli kamu soneklerinde (TLD) apex yanlış hesaplanıyor.** `apex('shop.adanapelet.com.tr') === 'com.tr'`; zone yoksa Plane `com.tr` adına zone oluşturmaya çalışır (S6-B02).
- **Yüksek — Domains ve Cloudflare yazma yüzeylerinin hiçbirinde audit izi yok.** Bu, S0'ın tespitini doğruluyor: `DomainController`, `CloudflareOpsController` (zone silme dahil), toplu sweep'ler, reconcile ve `bind_domains` fix'inde `auditLogs` sayısı 0 (S6-B08).
- **Yüksek — Cloudflare hesabı silinince ya da devre dışı bırakılınca siteler sessizce varsayılan hesaba düşüyor.** FK `nullOnDelete`, `settingsFor` ise sessizce varsayılana dönüyor. Bu durumda bir sonraki alias ekleme başka hesapta yeni zone açabilir (S6-B09).
- En değerli öneriler: şablonu "üzerine yazmaz" hale getirmek (O01), audit kapsamı (O06), hesap/zone silme koruması (O07/O08), Public Suffix List (O02), bağlama durumunu gözleme dayalı yapmak (O04), zamanlanmış drift ve zone taraması (O05), TLS/DNS gözetimi (O14 = Öneri-B8).

## 1. Mevcut durum

### 1.1 Akışlar

```mermaid
flowchart TD
  subgraph Tekil
    A1[POST /sites/{site}/domains<br/>SiteDomainController::store] --> S1[SiteDomainSync::addAlias<br/>DB satırları]
    A2[POST /domains<br/>DomainController::store] --> S1
    A3[PATCH /domains/{domain}<br/>DomainController::update<br/>UI yok] --> D0[domain->delete] --> S1
    S1 --> CF[SiteLanding::applyAliasDns<br/>CloudflareZoneService::ensureZoneAndDns<br/>tüm hostlar + şablon]
    CF --> BR[SiteLanding::bindAndRedeploy<br/>setDomains PATCH + verified_at + redeploy force=true]
    A4[POST /domains/{domain}/bind] --> BR
    A5[DELETE /sites/{site}/domains/{domain}] --> RM[SiteDomainSync::removeAlias] --> REL[releaseHostDns<br/>removeHostRecords] --> BR
    A6[promote] --> PR[SitePrimaryDomain::promote<br/>DB::transaction + audit] --> CF
  end
  subgraph Toplu
    B1[POST /domains/bulk-bind<br/>OpsJob domains.bulk_bind] --> BS[DomainBindSweep::run<br/>PacedFanout → syncCoolifyDomains<br/>PATCH, redeploy YOK, verified_at]
    B2[POST /domains/bulk-clear] --> CS[DomainClearSweep::run<br/>satır sil, CF/Coolify YOK]
  end
  subgraph Uzlaştırma
    C1[Coolify Sync<br/>CoolifySiteSync::sync] --> RC[SiteDomainReconciler::reconcile<br/>içe aktar + auto_rebind PATCH]
    C2[App health live inspect] --> DU[domain_unbound issue] --> FX[SiteAppHealthFixer::bindDomains<br/>PATCH, redeploy YOK]
  end
  subgraph Cloudflare
    Z1[POST /sites/{site}/cloudflare/zone] --> CF
    Z2[POST /sites/{site}/cloudflare/confirm DNS] --> CD[SiteLanding::confirmDns<br/>getZone → active ise temp bırak + bindAndRedeploy + audit]
    Z3[/cloudflare/* hesap, zone, DNS CRUD<br/>CloudflareOpsController/] --> ZS[CloudflareZoneService]
  end
```

Preview host: provision sırasında `SiteLanding::ensureTemporaryPreview` → `PreviewHostname::allocate` (`{adj}-{noun}`; 26×20 = 520 kombinasyon, 40 deneme + hex sonek; `app/Services/Cloudflare/PreviewHostname.php:12-45`) → wildcard zone'a açık A kaydı. Zone `active` olmadan Coolify yalnız temp host'a bağlanır (`app/Models/Site.php:350-356`).

### 1.2 Ana dosyalar

| Dosya | Sorumluluk | Satır | Test |
|------|-------------|------:|------|
| `app/Services/Cloudflare/CloudflareZoneService.php` | Zone bul/oluştur, host A, şablon upsert, kayıt serbest bırakma | 637 | `SiteCloudflareZoneTest`, `ProvisionSiteCloudflareTest`, `SiteLandingFlowTest`, `CloudflareDnsOpsTest` |
| `app/Services/Cloudflare/CloudflareClient.php` | Bearer HTTP, sayfalama, hata zarfı | 239 | dolaylı (Http::fake) |
| `app/Services/Cloudflare/CloudflareHostname.php` | normalize, zone adayları, apex | 98 | `Unit/Cloudflare/CloudflareHostnameTest` (6) |
| `app/Services/Cloudflare/CloudflarePermissionProbe.php` | Token izin probu (400/403 ayrımı) | 149 | `CloudflareSettingsTest` (11) |
| `app/Services/Cloudflare/{CloudflareDnsTemplate,CloudflareDnsRecord,PreviewHostname,CloudflareAccounts,CloudflareApiException}.php` | Şablon, kayıt DTO, preview adı, varsayılan hesap, hata | 83/128/58/39/131 | kısmi |
| `app/Services/Domains/DomainBindSweep.php` | Toplu bind (site başına tek PATCH) | 138 | `DomainBulkBindTest` (11) |
| `app/Services/Domains/DomainClearSweep.php` | Bağlanmamış satırları silme | 58 | `DomainBulkClearTest` (7) |
| `app/Services/Sites/SiteLanding.php` | bind+redeploy, DNS onayı, alias DNS, kayıt bırakma | 276 | `SiteLandingFlowTest` (21) |
| `app/Services/Sites/SiteDomainSync.php` | `site_domains` istenen küme, çakışma kontrolü | 179 | `SiteLandingFlowTest`, `SiteAliasRowsTest` |
| `app/Services/Sites/SiteDomainReconciler.php` | Coolify→Plane içe aktarma, eksik host, auto-rebind | 153 | `SiteDomainReconcileTest` (3) |
| `app/Services/Sites/SitePrimaryDomain.php` | Alias'ı birincil yapma | 96 | `SiteLandingFlowTest` |
| `app/Http/Controllers/Ops/DomainController.php` | `/domains` liste, ekle, ata, bind, toplu | 293 | Liste/toplu testli; `store`/`update`/`bind` **testsiz** |
| `app/Http/Controllers/Ops/CloudflareOpsController.php` | N hesap, zone, DNS CRUD, varsayılanlar | 435 | `CloudflareSettingsTest`, `CloudflareDnsOpsTest`, `ConfirmMatrixTest` |
| `app/Http/Controllers/Ops/SiteDomainController.php` | Site detay domain ekle/kaldır/birincil yap | 203 | `SiteLandingFlowTest` |
| `app/Http/Controllers/Ops/SiteCloudflareController.php` | Site zone oluştur, DNS onayı | 132 | `SiteCloudflareZoneTest` (8) |

### 1.3 Test kapsamı

| Testli | Testsiz |
|--------|---------|
| Toplu bind: site başına tek PATCH, 429 = atlandı, app yok = unready (`tests/Feature/Ops/DomainBulkBindTest.php:94-160`) | `ops.domains.store` / `ops.domains.update` / `ops.domains.bind` (grep: `tests/` altında route adı yok) |
| Toplu clear: primary/temp/bound atlanır | Mevcut TXT/MX kaydının şablonca ezilmesi |
| Reconcile: içe aktarma + `domain_unbound` + fix PATCH (`tests/Feature/Sites/SiteDomainReconcileTest.php:32,62`) | Çok etiketli TLD (`com.tr`, `co.uk`) |
| Alias ekle/kaldır/birincil, DNS onayı, purge'de kayıt bırakma (`3e6e28d`, `03f50b3`) | Cloudflare 429 / timeout / sayfalama kesmesi |
| Probe 400/403 ayrımı, token sızmaması | Hesap silme / devre dışı bırakma → siteler başka hesaba düşer |
| Confirm matrisi (zone/DNS/hesap silme `data-confirm-danger`) | Kullanımdaki zone'un silinmesi; bind sonrası redeploy yokluğu (toplu) |

### 1.4 Güçlü yanlar (korunmalı)

- Global tekillik üç katmanda korunuyor: DB `site_domains.domain` unique (`database/migrations/2026_08_13_071301_create_site_domains_table.php:14`), `SiteDomainSync::assertAvailable` (`app/Services/Sites/SiteDomainSync.php:134-166`) ve Coolify `force_domain_override=false` (`app/Services/Coolify/CoolifyClient.php:253-256`). İki sitenin aynı domaini sahiplenmesi normal yolda mümkün değil.
- Token şifreli, probe hiçbir zaman gerçek `name` göndermiyor (`app/Services/Cloudflare/CloudflareClient.php:97-102`), hata metni redakte ediliyor (`CloudflareApiException.php:79-100`).
- Zone/DNS mutasyonlarında hesap sahipliği kontrolü var (`CloudflareZoneService.php:205-216`); ID'ler regex ile doğrulanıyor (`CloudflareOpsController.php:418-425`).
- `f256f80` ile tekil bind yolları tek fonksiyonda birleşti (`SiteLanding.php:41-62`).

## 2. Bulgular

| ID | Tür | Önem | Bulgu | Kanıt | Etki |
|----|-----|------|-------|-------|------|
| S6-B01 | Hata | **Kritik** | Şablon upsert'ü müşteri kayıtlarını içerik karşılaştırmadan ezer. `findExisting` ad+tip (+MX önceliği) ile **ilk** kaydı döndürür; `updateIfChanged` içerik farklıysa PUT eder. TXT `@` (SPF ya da `google-site-verification` gibi), `_dmarc`, MX 5/10 üzerine yazılır. Öncelik farklı MX'ler için Hostinger MX'i müşterininkinin **yanına** eklenir. Mail şablonu koşulsuz uygulanır: `builtin()` `true` sabitiyle çağrılıyor, `mail_template_enabled` yalnız modelde geçiyor | `app/Services/Cloudflare/CloudflareZoneService.php:476-479,505-510,550-560,566-588`; `app/Services/Cloudflare/CloudflareDnsTemplate.php:16,36-82`; `grep -rn mail_template_enabled app resources` → yalnız `app/Models/CloudflareSetting.php:26,48` | Zone'u zaten Plane hesabında olan bir müşteriye (alias ya da birincil) domain eklemek e-posta akışını bölebilir, SPF'yi veya alan doğrulamasını silebilir. Canlı mail kesintisi |
| S6-B02 | Hata | Yüksek | Apex "son 2 etiket" olarak hesaplanıyor; PSL yok. `apex('shop.adanapelet.com.tr')` ve `apex('adanapelet.com.tr')` → `com.tr`; `isApex('adanapelet.com.tr')` → false. Kapsayan zone yoksa `createApexZone($client, $accountId, 'com.tr')` çağrılır | `app/Services/Cloudflare/CloudflareHostname.php:53-89`; `CloudflareZoneService.php:33-41,351-357`; komut: `php -r '… H::apex("shop.adanapelet.com.tr") …'` → `string(6) "com.tr"`; `grep -rn "com\.tr\|publicSuffix" tests app` boş | `.com.tr` müşterisi (filoda var) için zone önceden açılmamışsa provision/alias ekleme Cloudflare hatasıyla durur. Hatalı bir zone isteğinin CF tarafındaki sonucu Doğrulanmadı |
| S6-B03 | Hata | Yüksek | Toplu bind yalnız `syncCoolifyDomains` (PATCH) çağırıyor, redeploy yok. Plane'in kendi yorumu: "PATCH alone never makes a new host answer". Satırlar yine de `verified_at=now()` alıyor. Test de tek PATCH'i doğruluyor | `app/Services/Domains/DomainBindSweep.php:55-61`; `app/Services/Sites/SiteLanding.php:34-37`; `tests/Feature/Ops/DomainBulkBindTest.php:94-117` | Operatör 30 hostu toplu bağlıyor, liste "Bağlı" diyor ama hostlar bir sonraki deploy'a kadar karanlık kalıyor. Sessiz yanlış durum |
| S6-B04 | Hata | Yüksek | `bind_domains` fix'i ve Coolify Sync auto-rebind de yalnız PATCH atıyor. Reconciler ayrıca tüm geçici olmayan satırları `verified` işaretliyor. `bind_domains` `AUTO_SAFE`'te (`DeploymentFailureClassifier.php:23`) ama hiçbir kuralın `auto` değeri değil; yalnız `compose_domains_before_raw` kuralında elle seçilebilir düzeltme (`:246`, ölü otomatik giriş — S3-B27). Bugün PATCH-only otomatik yol yalnız Coolify Sync auto-rebind (`CoolifySiteSync.php:38` → `SiteDomainReconciler.php:58-66`). Fix'te audit yok | `app/Services/Sites/SiteAppHealthFixer.php:284-295`; `SiteDomainReconciler.php:58-66`; `app/Services/Sites/Diagnosis/DeploymentFailureClassifier.php:23` | `domain_unbound` "düzeldi" görünür (Coolify listesinde host var) ama Traefik label'ı yok. Yeniden inceleme sorunu yakalayamaz |
| S6-B05 | Risk | Yüksek | `verified_at` hiçbir yerde `null`'a çekilmiyor. `/domains` "Bağlı/Bağlı değil" rozeti, sayaçlar ve `unbound` filtresi bu bayat sütuna dayanıyor. Gerçek fark yalnız live inspect'teki `domain_unbound` ile görülüyor ve bu sütunu güncellemiyor | `grep -rn verified_at app` → yalnız set eden satırlar (`SiteLanding.php:53`, `SiteDomainReconciler.php:33,44,62`, `DomainBindSweep.php:58`); `app/Models/SiteDomain.php:77-79`; `DomainController.php:246-247`; `SiteAppHealthInspector.php:361-372` | Coolify'dan elle ya da import ile düşen host, Domains ekranında sonsuza dek "Bağlı" kalır. Spec'teki `coolify_bound_at` önerisi uygulanmamış ([domain spec](../../superpowers/specs/2026-09-11-domain-registry-coolify-binding-design.md) §Data model) |
| S6-B06 | Hata | Yüksek | Toplu bind, hiç PATCH atılmadığında da `verified_at` yazıyor. `todo` filtresi yalnız `coolify_app_uuid` şartına bakıyor. `syncCoolifyDomains` ise site bağlanabilir durumda değilse (`canBindCoolifyDomains`) veya bağlama boşsa sessizce `return` ediyor. DNS beklenirken bağlama yalnız temp host olduğu halde alias satırları da "bağlı" işaretleniyor | `DomainBindSweep.php:36-40,56-59`; `SiteLanding.php:200-209`; `app/Models/Site.php:350-356,502-517` | Draft/Error durumundaki ya da zone'u pending olan sitenin hostları "Bağlı" görünür ve `unbound` filtresinden kaybolur |
| S6-B07 | Hata | Orta | `DomainController::update` (hostu başka siteye taşıma) transaction dışında önce satırı siliyor, sonra `addAlias` çağırıyor. Taşınan alias'ın `www.` kardeşi eski sitede kaldığı için `assertAvailable` `ValidationException` atıyor (yalnız `SiteProvisionException` yakalanıyor). Sonuçta satır kayboluyor. Eski sitenin Coolify/DNS bağlaması hiç güncellenmiyor. Audit yok, test yok, **UI'da çağıran form yok** | `app/Http/Controllers/Ops/DomainController.php:85-122`; `SiteDomainSync.php:39-54,134-166`; `grep -rn "domains.update" resources tests` boş | Yalnız elle PATCH ile erişilebilen ölü uç nokta; tetiklenirse kayıt defterinden satır kaybı ve Coolify ile sapma |
| S6-B08 | Güvenlik | Yüksek | Domain ve Cloudflare yazma yüzeylerinde audit yok: fleet ekle/bind/toplu bind/toplu clear; CF hesap oluştur/güncelle/token değiştir/sil, zone oluştur/**sil**, DNS kaydı oluştur/güncelle/**sil**, şablon varsayılanları; reconcile içe aktarma; `bind_domains` fix'i. S0'ın tespiti (01-envanter §8) doğrulandı | `auditLogs\|AuditLog` sayımı: `DomainController.php` 0, `CloudflareOpsController.php` 0, `DomainBindSweep.php` 0, `DomainClearSweep.php` 0, `SiteDomainReconciler.php` 0, `CloudflareZoneService.php` 0 (karşılaştırma: `SiteDomainController.php` 2, `SiteLanding.php` 1) | Müşteri zone'unun tamamını silen işlem iz bırakmıyor; olay sonrası "kim, ne zaman" sorusu yanıtsız kalıyor |
| S6-B09 | Risk | Yüksek | CF hesabı silinince `sites.cloudflare_setting_id` NULL'a dönüyor; devre dışı bırakılan hesapta ise `settingsFor` sessizce varsayılana düşüyor. Sonraki alias eklemede `ensureZoneAndDns` başka hesapta zone arıyor, bulamayınca **yeni zone açıyor** ve sitenin `cloudflare_zone_id`/NS/durum alanlarını eziyor. Hesap silmede "kullanan site sayısı" kontrolü yok | `database/migrations/2026_09_10_170000_add_cloudflare_setting_id_to_sites_table.php:15-20` (`nullOnDelete`); `app/Services/Sites/SiteLanding.php:259-270`; `CloudflareOpsController.php:102-119`; `CloudflareZoneService.php:29-45,71-75` | Sitede zone pending'e düşer (`isWaitingOnDns`), tüm bind'ler `waiting_dns` döner, müşteriye yanlış NS gösterilir. Cloudflare'in aynı zone'u ikinci hesapta kabul edip etmediği Doğrulanmadı |
| S6-B10 | Güvenlik | Orta | Zone ve DNS silme yalnız `ops.write` iznine bağlı. Zone'un bir site veya `site_domains` satırı tarafından kullanılıp kullanılmadığına bakılmıyor. `deleteZone` alias satırlarındaki `cloudflare_zone_id/status/nameservers` alanlarını temizlemiyor | `CloudflareOpsController.php:293-307,342-354`; `CloudflareZoneService.php:156-169` | Operatör rolü tek onayla canlı müşteri DNS'ini kaldırabilir; alias satırlarında hayalet zone bilgisi kalır |
| S6-B11 | Hata | Orta | Toplu clear, satırları Cloudflare kaydını bırakmadan siliyor. `DomainController::store`, DNS'i bind'dan önce yazıyor (`applyAliasDns`); app yoksa (`BIND_NO_APP`) `verified_at` boş kalıyor ve satır "clearable" oluyor. `www.` kardeşi seçilmediyse yetim kalıyor. `3e6e28d`/`03f50b3` ilkesiyle çelişiyor | `app/Services/Domains/DomainClearSweep.php:9-11,24-32`; `DomainController.php:74-77`; `SiteLanding.php:44-46`; kıyas: `SiteDomainSync.php:91-121` | Yetim A kayıtları origin'e işaret etmeye devam eder; görünür değil ve temizlenmiyor |
| S6-B12 | Risk | Orta | `removeHostRecords`, A kaydını içerik (origin) eşleşmesine bakmadan siliyor; `findExisting` yalnız ad+tip ile arıyor. Müşteri kaydı başka sunucuya yönlendirmişse alias kaldırma/purge o kaydı da siler. Aynı ada birden çok A kaydı varsa yalnız ilki silinir | `CloudflareZoneService.php:279-323,566-588` | Müşterinin Plane dışına taşıdığı alt alan adı kesilir |
| S6-B13 | Risk | Orta | Cloudflare istemcisinde 429 / `Retry-After` / backoff / rate guard yok (Coolify'da `RateGuard` var). Sayfalama 20 sayfada (1000 kayıt) sessizce kesiliyor. Tüm çağrılar HTTP isteği içinde senkron, her biri 20 sn timeout | `app/Services/Cloudflare/CloudflareClient.php:163-208`; `grep -rn "429\|retry" app/Services/Cloudflare` boş | Yoğun işlemde 429 düz hata olarak gelir, yarım kalmış DNS uygulaması tekrar gerektirir. Büyük hesapta zone listesi eksik görünür |
| S6-B14 | Perf | Orta | Her alias ekleme/atama/birincil yapma, sitenin **tüm** hostları için DNS'i baştan uyguluyor (her host için zone araması, `*` ve host A upsert'ü, apex'te 11 kayıtlık şablon). Koddan sayım: birincil apex + www + başka apex'te 1 alias + www ≈ 27–54 ardışık Cloudflare çağrısı, üstelik hiçbir şey değişmemişken | `CloudflareZoneService.php:49-68,467-484,505-544`; çağıranlar `SiteDomainController.php:32`, `DomainController.php:76,115`, `SitePrimaryDomain.php:62` | Yavaş istek (20 sn × N timeout riski), gereksiz API kotası, S6-B01 ezme riskine her seferinde yeniden maruz kalma |
| S6-B15 | Perf | Orta | Her tekil bind'da `force=true` (cache'siz) rebuild tetikleniyor, birleştirme (debounce) yok. Deploy meşgulse (`BIND_DEPLOY_BUSY`) `verified_at` zaten yazılmış oluyor ve sonradan deploy tetikleyen bir mekanizma yok | `SiteLanding.php:52-61`; `app/Services/Sites/CoolifyDeploySettings.php:149`; `app/Services/Coolify/CoolifyClient.php:360-370` | 3 alias = 3 tam rebuild; yük altındaki Coolify host'unda gereksiz CPU. Meşgul durumda host karanlık kalıyor ama "Bağlı" görünüyor. Force'suz deploy'un Traefik label'larını yenileyip yenilemediği Doğrulanmadı |
| S6-B16 | Eksik | Orta | Zamanlanmış domain drift veya zone durum taraması yok. Pending zone'un yaşı tutulmuyor, `activation_check` yok. `moved`/`deleted`/`deactivated` durumları yalnız operatör "DNS'i güncelledim" dediğinde okunuyor. `zoneIsReady('')` true dönüyor | `routes/console.php:16-42` (4 giriş, domain/CF yok); `grep -rn "pending_since\|zone_checked\|activated_on" app database` boş; `CloudflareHostname.php:46-51`; `SiteLanding.php:119-149` | Müşteri NS'i Cloudflare'den çektiğinde Plane bunu fark etmez; unutulan pending zone'lar Cloudflare tarafında düşebilir (CF politikası, Doğrulanmadı) |
| S6-B17 | Eksik | Orta | TLS/sertifika görünürlüğü yok (Boşluk-B10, Öneri-B8 hâlâ **Yapılmadı**). Live probe yalnız birincil hostu `https://` ile yokluyor; TLS, DNS ve timeout hatalarının hepsi `status=0`'a iniyor | `app/Services/Sites/SiteLiveProbe.php:47-55,88-96`; `grep -rni "certificat\|letsencrypt\|acme" app` boş; [15 §2](15-onceki-inceleme-durum-takibi.md) | Süresi dolan veya alınamayan sertifika (özellikle alias'larda) ilk müşteriden duyulur |
| S6-B18 | Risk | Orta | Kendi zone'u pending olan alias hemen Coolify'a bağlanıyor, çünkü `isWaitingOnDns` yalnız birincil zone'a bakıyor. DNS henüz origin'e işaret etmezken Traefik ACME denemeleri başarısız olur | `app/Models/Site.php:246-249,350-356`; `SiteDomainController.php:52-60` (pending alias NS'i flash'ta) | Let's Encrypt başarısız doğrulama limitine takılma riski (Traefik resolver ayarı Doğrulanmadı) |
| S6-B19 | UX | Orta | Coolify Sync'in domain sonucu (imported/conflicts/rebound/missing) operatöre gösterilmiyor; reconcile istisnası `catch (\Throwable)` ile yutuluyor, log yok | `app/Http/Controllers/Ops/SiteCoolifyOpsController.php:182-186`; `app/Services/Coolify/CoolifySiteSync.php:38-48` | Başka siteye ait host çakışması ve içe aktarılan yeni hostlar görünmez |
| S6-B20 | Borç | Orta | Fleet ekleme ve alias ekleme yolları transaction dışında: `SiteDomainSync::sync` önce siliyor, sonra upsert ediyor. Cloudflare hatasında satır kalıyor ve hata yolu audit edilmiyor. Eşzamanlı istekte unique ihlali 500 olarak dönebilir (Doğrulanmadı). Site düzenleme ve promote transaction kullanıyor | `SiteDomainSync.php:56-77`; `DomainController.php:62-72`; `SiteDomainController.php:29-36`; kıyas `SiteController.php:974`, `SitePrimaryDomain.php:44` | Yarım durum: DB'de alias var, DNS/Coolify yok; ancak `domain_unbound` ile görünür |
| S6-B21 | Borç | Düşük | Şablondaki `@`/`www`/`*` A kayıtları global `CloudflareDnsDefault` içeriğini (config'teki origin) kullanıyor; host A kayıtları ise hesap başına `origin_ipv4`. `ensureStarA`, paylaşılan üst zone'daki `*` kaydını da hesap origin'ine yeniden yazıyor. Kodda sabit origin IPv4 fallback'i var (<gizli>) | `CloudflareDnsTemplate.php:14-16`; `app/Models/CloudflareSetting.php:71-76`; `CloudflareZoneService.php:474-489` | Birden çok origin'li (N Coolify sunucusu) kurulumda apex ile alt host farklı sunuculara gider. Tek origin'de etkisi yok |
| S6-B22 | Borç | Düşük | Preview host bırakma hatası yutuluyor (`catch (Throwable) {}`); wildcard zone'da yetim A kayıtları birikebilir ve bunları süpüren bir iş yok | `SiteLanding.php:152-158` | Kozmetik/bakım; `*` zaten aynı origin'e işaret ettiği için trafik etkisi yok |

**Sayım:** 22 bulgu — Kritik 1, Yüksek 7, Orta 12, Düşük 2.

### 2.1 Odak soruları — kısa yanıtlar

| Soru | Yanıt | Kanıt |
|------|-------|-------|
| İki site aynı domaini alabilir mi? | Normal akışta hayır (DB unique + `assertAvailable` + Coolify 409). Taşıma uç noktası (B07) ve eşzamanlı istek (B20) istisna | §1.4 |
| Coolify ↔ Plane drift tespiti var mı? | Kısmen: live inspect `domain_unbound` (L1), Sync içe aktarma + PATCH-only auto-rebind. Zamanlanmış tarama yok, `verified_at` bayat | B04, B05, B16 |
| Bind başına redeploy maliyeti / toplu fırtına | Tekil: her bind force rebuild. Toplu: redeploy **hiç yok** (fırtına yok ama etkisiz) | B03, B15 |
| Cloudflare API hata/rate limit | 403 → izin mesajı, duplicate → yeniden oku; 429/backoff yok | `CloudflareZoneService.php:528-543`; B13 |
| Zone pending süresi, NS doğrulama | Yalnız CF `status`; yaş yok, hatırlatma yok, `activation_check` yok | B16 |
| TLS görünürlüğü / SSL modu | Yok. Tüm kayıtlar DNS-only (`proxied=false` zorunlu), SSL modu okunmuyor; proxied açılsa bile Plane bir sonraki upsert'te kapatır | `CloudflareZoneService.php:552-559,603-605`; B17 |
| Domain bitiş tarihi | Registrar verisi yok; spec "Out" (registrar API). RDAP okuma fikri §5'te, karar gerekiyor | spec §Scope |
| Token izin kapsamı | Zorunlu: tüm zone'lar (gelecekler dahil) + Zone Read/Edit + DNS Read/Edit ([cloudflare-client.md](../../modules/cloudflare-client.md)). Zone Edit ile zone silinebildiği Doğrulanmadı (CF dokümanı); Plane zone silmeyi `ops.write` ile açıyor | B10 |
| Audit kapsamı | S0'ın tespiti doğrulandı; tekil site yolları (`site.domain_added/removed/changed`, `site.dns_confirmed`, `site.cloudflare_ready`) audit ediliyor, fleet ve CF yüzeyleri audit edilmiyor | B08 |

## 3. İyileştirme ve güncelleme önerileri

Puan = Etki×2 − Risk + (S=3, M=2, L=1, XL=0). Puana göre sıralı.

| ID | Öneri | Çözdüğü bulgu | Etki (1–5) | Efor | Risk (1–5) | Öncelik puanı | Kabul kriteri |
|----|-------|---------------|-----:|:----:|-----:|-----:|---------------|
| S6-O01 | Şablon upsert'ünü **üzerine yazmaz** yap. A `@`/`www`/`*` origin'e çekilebilir. `@` altında herhangi bir `v=spf1` TXT, `_dmarc` altında herhangi bir `v=DMARC1` veya zone'da herhangi bir MX varsa içerik farklı olsa bile ne PUT ne POST yapılır; fark dikkat kartına düşer (çift SPF/DMARC permerror verir, RFC 7208 §3.2). Yalnız ilgili tür/ad hiç yoksa **eklenir**. Mevcut zone'da mail kayıtları yalnız `mail_template_enabled` açıksa yazılır | B01, B21 | 5 | M | 2 | **10** | Http::fake testi: `@` altında `google-site-verification` TXT'si ve MX 10 `aspmx` olan zone'da bind → TXT/MX için PUT ve POST yok; SPF/DMARC/MX hiç olmayan zone'da POST var. `mail_template_enabled=false` → MX/TXT/DKIM çağrısı 0 |
| S6-O06 | Audit kapsamı: `domain.created/assigned/bound`, `domains.bulk_bind/clear` (site başına satır + job id), `cloudflare.account_{created,updated,deleted,token_rotated}` (token değeri yok), `cloudflare.zone_{created,deleted}`, `cloudflare.dns_{created,updated,deleted}`, `site.domain_imported` (reconcile), `bind_domains` fix'i | B08 | 4 | S | 1 | **10** | Her listelenen route için feature testi `audit_logs` satırı bekler; `after` içinde token/secret geçmez (`SecretRedactor` testi) |
| S6-O07 | CF hesabı silme ve devre dışı bırakma koruması: sitede kullanılıyorsa engelle, "yeniden ata" akışı sun. FK `restrictOnDelete`; açık `cloudflare_setting_id`'si olan sitede `settingsFor` varsayılana düşmez, hata verir | B09 | 4 | S | 1 | **10** | 1 sitenin bağlı olduğu hesapta DELETE → 422 ve site sayısı flash'ta; devre dışı hesaplı sitede alias ekleme `createZone` çağırmaz |
| S6-O08 | Zone silme koruması: zone ID'si `sites.cloudflare_zone_id` veya `site_domains.cloudflare_zone_id`'de geçiyorsa reddet; yalnız Super Admin; alias satırlarını da temizle; audit | B10 | 4 | S | 1 | **10** | Kullanımdaki zone'da DELETE → Cloudflare'e DELETE gitmez; kullanılmayan zone'da `site_domains` alanları null |
| S6-O02 | Public Suffix List ile apex: PHP domain-parser paketi ya da repoda tutulan PSL anlık görüntüsü; runtime'da indirme yok. Kullanılmayan `sameRegistrableApex`/`isApex` düzeltilir | B02 | 4 | S | 2 | **9** | `apex('shop.adanapelet.com.tr')==='adanapelet.com.tr'`, `apex('a.b.co.uk')==='b.co.uk'`; `createZone` hiçbir zaman PSL sonekiyle çağrılmaz (unit + Http::fake) |
| S6-O14 | TLS/DNS gözetimi (= Öneri-B8): live probe'u her operatör hostuna genişlet. DNS A (beklenen origin mi?), TLS bitiş tarihi/issuer/SAN, hata sınıfı (`dns`/`tls`/`timeout`/`http`). Sonucu `site_domains` satırına yaz; 14 günden az kalan sertifika için dikkat kartı | B17 | 4 | M | 1 | **9** | Unit: stream context fake ile bitiş < 14 gün → kart; DNS uyuşmazlığı → `dns_mismatch` rozeti; satır başına `checked_at` |
| S6-O04 | Bağlama durumunu gözleme dayandır: spec'teki `coolify_bound_at` eklenir, yalnız `getApp` hostu gösterdiğinde set edilir, host eksikse null'a çekilir. Hiç PATCH atılmadıysa (no-op, temp bağlama) işaretlenmez | B05, B06 | 4 | M | 2 | **8** | Bağlanamaz durumdaki sitede toplu bind → sütun boş kalır; Coolify listesinden düşen host live inspect sonrası "Bağlı değil" görünür; `/domains` sayacı ile `domain_unbound` aynı kümeyi verir |
| S6-O05 | Zamanlanmış drift ve zone taraması (`ops:reconcile-domains`, saatlik, `withoutOverlapping` + `onOneServer`): site başına paced `getApp` + reconcile (rebind yok, yalnız tespit) + pending zone'lar için `getZone` | B05, B16 | 4 | M | 2 | **8** | Komut Http::fake ile 3 sitede çalışır: bir sapma `domain_unbound` + `coolify_bound_at=null` üretir; PATCH/deploy çağrısı 0 |
| S6-O10 | `removeHostRecords` yalnız içerik origin olan A kaydını silsin; eşleşmeyenleri "atlandı" diye raporlasın | B12 | 3 | S | 1 | **8** | Başka IP'ye işaret eden A için DELETE yok; sonuçta `skipped_foreign` listesi |
| S6-O11 | Cloudflare istemcisine 429/5xx için `Retry-After`'lı sınırlı retry (en çok 2 deneme) ve hesap başına küçük aralık; sayfalama kesilirse uyarı | B13 | 3 | S | 1 | **8** | 429 ardından 200 fake → tek başarılı sonuç; 3×429 → "rate limited" mesajı; 21. sayfada `truncated=true` |
| S6-O15 | Pending zone yaşı ve durumu: `cloudflare_zone_checked_at` + `pending_since`, butonla `PUT /zones/{id}/activation_check`, 7 günü geçen pending ve `moved/deleted` için dikkat kartı | B16 | 3 | S | 1 | **8** | 8 günlük pending sitede kart görünür; `moved` durumu bind'ı engeller ve nedenini söyler |
| S6-O17 | `PATCH /domains/{domain}` uç noktasını kaldır (UI'da kullanılmıyor) ya da transaction'lı "taşı" akışına çevir: eski siteden `removeAlias` + DNS/Coolify unbind, yeni sitede add + bind; audit | B07 | 3 | S | 1 | **8** | Route yoksa `route:list`'te görünmez; varsa: www kardeşi olan alias taşıma testinde satır kaybı yok, eski site PATCH'i gözlenir |
| S6-O03 | Bind semantiğini tek yolda birleştir: toplu bind, `bind_domains` fix'i ve Sync auto-rebind PATCH'ten sonra (paced) redeploy kuyruğa alır ya da açıkça "redeploy gerekli" durumunu yazar | B03, B04 | 4 | M | 3 | **7** | Toplu bind 2 site → 2 PATCH + 2 deploy (PacedFanout aralığıyla); özet "redeploy kuyrukta" der; fix sonrası `domain_unbound` yalnız deploy bitince temizlenir |
| S6-O09 | Toplu clear, `SiteDomainSync::removeAlias` yolunu kullansın (www kardeşiyle birlikte) ve `releaseHostDns` çağırsın | B11 | 3 | S | 2 | **7** | Clear testinde CF DELETE gözlenir; www kardeşi de silinir; CF hatası satır silmeyi durdurmaz ama flash'ta görünür |
| S6-O16 | Kendi zone'u pending olan alias, zone aktif olana kadar Coolify bağlamasından çıkarılsın (host başına bekleme) | B18 | 3 | S | 2 | **7** | Pending zone'lu alias'ta `coolifyDomainBinding()` o hostu içermez; zone aktif olup DNS onaylanınca eklenir |
| S6-O12 | Artımlı DNS: alias eklemede yalnız yeni host(lar) işlenir; şablon yalnız zone oluşturulurken veya "Varsayılanları uygula" ile uygulanır | B14, B01 | 3 | M | 2 | **6** | Mevcut 4 hostlu siteye 1 alias → CF çağrı sayısı ≤ 6 (Http::recorded sayımı) |
| S6-O18 | Sync flash'ı domain sonucunu göstersin (`imported`, `conflicts`, `rebound`); reconcile istisnası `report()` ile loglansın | B19 | 2 | S | 1 | **6** | Çakışmalı fake'te flash "1 host başka siteye ait" der; istisnada log satırı |
| S6-O19 | Fleet ekleme/alias ekleme DB kısmı `DB::transaction` içinde; CF/Coolify hata yolu da `site.domain_add_failed` audit'i yazar | B20 | 2 | S | 1 | **6** | CF 403 fake'te audit satırı var; unique ihlali 422 döner, 500 değil |
| S6-O13 | Yalnız domain değişikliği için force'suz deploy + site başına birleştirme (ör. 60 sn içinde tek deploy) + meşgulse `pending_redeploy` bayrağı ve sonradan tetikleme | B15 | 3 | M | 3 | **5** | 3 alias art arda → 1 deploy; meşgul → bayrak set, deploy bitince tek yeni deploy. Önce: force'suz deploy'un label ürettiği staging'de doğrulanmalı |
| S6-O20 | Şablon A kayıtlarında `{origin}` yer tutucusu: hesap `origin_ipv4` kullanılır; koddaki sabit IP fallback'i kaldırılır (yalnız config) | B21 | 2 | S | 2 | **5** | İki farklı origin'li hesapta apex ve alt host aynı IP'yi alır; `grep` ile kodda IPv4 literal yok |

**Sayım:** 20 öneri.

## 4. Otonomi fırsatları

| Akış | Bugünkü seviye | Hedef | Tetik | Ön koşul | Güvenli otomatik adım | Doğrulama | Geri alma | Guardrail/bütçe | Kill switch | Audit | Bildirim |
|------|:---:|:---:|-------|----------|------------------------|-----------|-----------|-----------------|-------------|-------|----------|
| Coolify ↔ Plane host drift | L1 (live inspect `domain_unbound`) + yarım L4 (Sync PATCH-only, doğrulamasız) | L4 | Saatlik `ops:reconcile-domains` (O05) | Site `canBindCoolifyDomains`, zone aktif, son 30 dk'da başka deploy yok, O03 uygulanmış | `setDomains` (tam küme) + force'suz redeploy | Deploy bitince `getApp` hostu içerir; host `https://` 200/3xx (O14) | Önceki `docker_compose_domains` değeri audit'ten geri PATCH | Saatte ≤ 5 site, site başına günde 1; PacedFanout aralığı | Mevcut `ops.coolify.auto_rebind_domains` (`config/ops.php:157`) + yeni `ops.domains.auto_heal` | `site.domain_auto_rebound` (before/after hosts) | Activity + günlük özet e-postası |
| Pending zone aktivasyonu | L1 (NS gösteriliyor, "DNS'i güncelledim" elle) | L4 | Saatlik; pending zone için `getZone` | `sites.cloudflare_zone_status=pending`, `temporary_domain` dolu | Zone `active` olunca `SiteLanding::confirmDns` (temp bırak + bind + redeploy) | Yeni hostlar Coolify'da; live probe 200 | Temp host'u yeniden bağla (`ensureTemporaryPreview`) | Site başına günde 24 GET; 7 günden sonra günde 1 | `ops.cloudflare.auto_confirm_dns` | Mevcut `site.dns_confirmed` + `auto=true` | Activity; 7/21 gün pending → dikkat kartı + e-posta |
| TLS sertifika sağlığı | L0 | L2 → L3 | Günlük probe (O14) | Host DNS'i origin'i gösteriyor | L2: neden sınıfı (DNS yanlış / ACME başarısız / süre doluyor); L3: "Yeniden deploy et" tek tık | Yeni probe'da `not_after` > 30 gün | Yok (salt okuma + redeploy) | Günde host başına 1 TLS el sıkışması | `ops.domains.tls_probe` | `site.tls_checked` yalnız durum değişince | Kart + e-posta (≤ 14 gün) |
| Yetim DNS kayıtları | L0 | L3 | Haftalık tarama: origin'e işaret eden, Plane'de hostu olmayan A kayıtları | Kayıt Plane'in hesap zone'unda, içerik = origin, ad `*`/`@`/`www` değil | Liste + "Serbest bırak" (tek tık, onaylı) | Kayıt CF'de yok | Silinen kaydı audit'teki payload'dan yeniden oluştur | Tek tıkta ≤ 20 kayıt | `ops.cloudflare.orphan_scan` | `cloudflare.dns_released` (payload ile) | Activity |
| Toplu bind | L3 (ama etkisiz: redeploy yok) | L3 (doğru) → L4 | Operatör / drift işi | O03 + O04 | PATCH + paced redeploy | `coolify_bound_at` gözlemle set | Önceki host listesine PATCH | `ops.coolify.bulk.*` mevcut limitleri | Mevcut toplu iş iptali (`/jobs`) | Site başına audit + job id | Jobs widget özeti |
| Preview host temizliği | L0 (hata yutuluyor) | L4 | DNS onayında bırakma başarısızsa kuyruğa al; günlük tekrar | Host `PreviewHostname` desenine uyuyor, hiçbir sitede yok | A kaydını sil | CF'de kayıt yok | Aynı A'yı yeniden yaz | Günde ≤ 50 | `ops.cloudflare.preview_gc` | `cloudflare.preview_released` | Yok (yalnız Activity) |
| DNS şablon sapması | L1 (zone sayfasında kayıt listesi) | L2 | Zone sayfası açılışı / haftalık | O01 uygulanmış | Beklenen ile mevcut kayıt farkı ("eksik", "yabancı içerik") | — | — | Salt okuma | — | — | Zone sayfasında rozet |

## 5. Yeni özellik fikirleri (bu alana özgü)

- **Host detay çekmecesi** `/domains/{id}`: site, zone, NS, CF A değeri, Coolify bağlama zamanı, TLS bitişi, son probe, ilgili audit satırları.
- **DNS planı önizleme (diff):** alias ekleme ve "Varsayılanları uygula" öncesinde oluşturulacak, güncellenecek ve atlanacak kayıtlar tablosu (O01 ile birlikte).
- **Domain taşıma sihirbazı** (site A → B): O17'nin UI'lı hali, iki sitede redeploy özeti.
- **Toplu "DNS'i kontrol et"**: tüm pending siteler için tek iş (`activation_check` + `getZone`).
- **Yetim kayıt bulucu:** origin'e işaret eden, Plane'de sahibi olmayan kayıtlar.
- **Bitiş tarihi (RDAP, salt okuma):** yeni dış çağrı gerektirir. *Kapsam dışı — bilinçli karar gerekir* (spec registrar'ı "Out" sayıyor).
- **Host başına opt-in proxied + SSL modu (Full Strict) kontrolü:** origin IP'sini gizler, bot yükünü azaltır (Coolify host yük profili). *Kapsam dışı — bilinçli karar gerekir* (spec v1: `proxied` kapalı, SSL/WAF "Out").

## 6. CMS handoff

- Doğrulanmadı: alias hostlardan birincile kanonik yönlendirme / `rel=canonical`. Plane her hostu aynı uygulamaya bağlıyor; SEO açısından yinelenen içerik riskini CMS'in nasıl ele aldığı CMS tarafında kontrol edilmeli.
- Doğrulanmadı: birincil domain değişince `APP_URL` Coolify tarafından enjekte ediliyor (`app/Services/Coolify/CoolifyAppEnvSync.php:35-39` koruma listesi). CMS'in önbelleğe aldığı mutlak URL'lerin (sitemap, medya) redeploy sonrası yenilenip yenilenmediği CMS'te doğrulanmalı.
- Plane tarafı öneriler CMS değişikliği gerektirmiyor.

## 7. Açık sorular

| # | Soru | Önerilen varsayılan |
|---|------|---------------------|
| 1 | Toplu bind ve `bind_domains` fix'i redeploy tetiklemeli mi? | Evet; paced, force'suz, site başına tek deploy (O03/O13) |
| 2 | Şablon, mevcut zone'daki yabancı TXT/MX'e dokunabilir mi? | Hayır. Yalnız eksikse ekle; değiştirmek için diff önizlemeli açık "Uygula" (O01) |
| 3 | PSL için composer bağımlılığı mı, repoda tutulan anlık görüntü mü? | Paket + repoda PSL anlık görüntüsü; runtime'da indirme yok (O02) |
| 4 | `verified_at` yeniden mi anlamlandırılsın, yeni sütun mu? | Spec'teki `coolify_bound_at` eklensin; `verified_at` "Plane son PATCH" olarak kalsın (O04) |
| 5 | Kullanımdaki CF hesabı silinirken engellensin mi, yeniden atama mı istensin? | Engelle + yeniden atama ekranı (O07) |
| 6 | `PATCH /domains/{domain}` kaldırılsın mı, düzeltilsin mi? | Kaldır; taşıma ihtiyacı çıkarsa sihirbaz olarak yeniden tasarla (O17) |
| 7 | Zone silme hangi rolde olmalı? | Yalnız Super Admin + kullanım kontrolü (O08) |
| 8 | Proxied (turuncu bulut) v1 dışında mı kalacak? | Evet; bot yükü nedeniyle yeniden değerlendirme ayrı karar |
