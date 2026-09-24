# 12 — Performans ve ölçek (50 → 500 site)

**Tarih:** 2026-09-24 · **HEAD:** 8484d37 · **Hazırlayan:** S10 (Operasyon/altyapı/gözlemlenebilirlik denetçisi, Dalga 2)
**Kapsam:** Health fan-out, app-health inspect, Sites/Fleet/Jobs/Activity sayfaları, günlük snapshot, toplu işler (`PacedFanout`), tema fan-out, Coolify/Cloudflare çağrı bütçesi, sayfa ağırlığı. Altyapı bulguları için [11-operasyon-altyapi-gozlemlenebilirlik.md](11-operasyon-altyapi-gozlemlenebilirlik.md) (S10-B..).
**Kapsam dışı:** Canlı ölçüm (istek atılmadı), CMS tarafı performansı, Coolify host kapasitesi.

## Özet

- **500 sitede en büyük 3 darboğaz:**
  1. **Health turu:** Tur başına 1000 iş tek worker'da koşuyor. Yalnız Plane tarafı (Http::fake ile ölçülen) taban 0,30 sn/site, yani 150 sn. Gerçek ağ gecikmesi eklenince tahmini 500–1150 sn; 600 sn'lik pencere doluyor, worker %83–190 meşgul (S10-PB01).
  2. **Toplu deploy:** 500 site × ≥1,3 sn ≈ 650 sn. 300 sn'lik iş timeout'u yaklaşık 230. sitede işi öldürüyor (S10-PB02). Coolify'a hepsi birden kuyruğa atılan build'ler 34 dakikalık poll bütçesini aşınca satırlar toplu olarak sahte "Timed out" `failed` oluyor (S10-PB03).
  3. **Sites tam sayfa (soğuk cache):** 50 sitede 93 sorgu / 0,76 sn iken 500 sitede **552 sorgu / 5,0 sn**. Sebep, filo taramasındaki N+1 (S10-PB04).
- Coolify API bütçesi: health inspect tek başına saatte 6000 çağrı (500 site), 50 sitede 600. RateGuard süreç içinde tutuluyor; FPM ile worker bütçeyi paylaşmıyor (S10-PB05, PB06).
- İyi ölçeklenenler: Fleet 9 sorgu, palette 7, snapshot 7, liste bölge (region) isteği 32 sorgu. Hepsi N'den bağımsız. `reportedDeamonVersions` endişesi (S1-B15/S2-B22/S4-B17) ölçümde +1 sorgu çıktı; Düşük kalmalı.
- En değerli öneriler: kademeli health taraması (S10-PO02), N+1 düzeltmesi (PO01), `health` kuyruğu + 2 worker (PO03), kapasite metrikleri ve uyarıları (PO11).

## 1. Mevcut durum

### 1.1 Ölçüm yöntemi

Repo dışı geçici PHPUnit probu (`mktemp -d` → `S10CostProbeTest.php`), yerel sqlite `:memory:`, `Http::fake`. Repo dosyası değiştirilmedi. Komut: `php vendor/bin/phpunit <tmp>/S10CostProbeTest.php`. Fixture: N aktif site (secret, `coolify_app_uuid`, health payload, 1 bitmiş deploy), operatör kullanıcı. Mevcut maliyet testi: `php artisan test --compact --filter=SiteListAppHealthCountCostTest` → 6 geçti, 20 assertion, 2,79 sn.

| Ölçüm (yerel sqlite, göreli) | 50 site | 500 site | N'e bağlı mı |
|------------------------------|---------|----------|:---:|
| `GET ops.sites` tam sayfa, soğuk | 93 sorgu / 763 ms | **552 sorgu / 5004 ms** | evet |
| `GET ops.sites?cms=outdated` | 94 / 1746 ms | 553 / 5428 ms | evet |
| `GET ops.sites` region (`q=a`) | 32 sorgu | 32 sorgu | hayır |
| `GET ops.fleet` | 9 / 78 ms | 9 / 189 ms | hayır |
| `GET ops.jobs` (widget) | 66 / 339 ms | 66 / 407 ms | hayır (sorgu) |
| `GET ops.palette?q=s` | 7 / 92 ms | 7 / 98 ms | hayır |
| `ops:snapshot-fleet` | 7 / 30 ms | 7 / 29 ms | hayır |
| `DispatchSiteHealthChecksJob` (Queue::fake) | 100 iş / 92 ms | 1000 iş / 922 ms | evet |
| `CheckSiteHealthJob` × 20 (ağ 0) | 6 sorgu/iş, **62 ms/iş** | aynı | — |
| `InspectSiteAppHealthJob` × 20 (ağ 0) | 8 sorgu/iş, **236 ms/iş** | aynı | — |

Inspect'in 236 ms'lik süresinin çoğu `CoolifyRateGuard` beklemesi: 2 Coolify çağrısı × 120 ms (`CoolifyRateGuard.php:33-39`, `config/ops.php:182`). N+1 kaynağı sorgu izleme probuyla bulundu: `SiteAppHealthInspector.php:217` ← `:137` ← `:81` ← `Site.php:391` (`appHealth()`).

### 1.2 Ana dosyalar

| Dosya | Sorumluluk | Satır | Test |
|-------|------------|-------|------|
| `app/Jobs/DispatchSiteHealthChecksJob.php` | Tüm siteler için Check + Inspect dispatch | 29 | `tests/Feature/Agent/*` |
| `app/Services/Sites/SiteAppHealthInspector.php` | Inspect (getApp + listEnvs) | 436 | `tests/Feature/Sites/SiteAppHealthTest.php` |
| `app/Services/Sites/SiteAppHealthFixer.php` | Filo sayaçları (60 sn cache) | — | `SiteListAppHealthCountCostTest` |
| `app/Services/Coolify/CoolifyRateGuard.php` | Host başına 120 ms aralık, cooldown | 102 | `tests/Unit/Coolify/*` |
| `app/Services/Ops/PacedFanout.php` | Seri toplu süpürme | 232 | `BulkThrottleResilienceTest` |
| `app/Jobs/PollDeploymentJob.php` | 40 deneme, 15→60 sn | — | `PollDeploymentThrottleTest` |
| `app/Support/Ops/ActivityFeed.php` | 3 tablo UNION | — | `ActivityFeedTest` |

## 2. Senaryo tablosu

Varsayımlar (**Doğrulanmadı**, canlı ölçüm yok): agent RTT 0,3–1,0 sn; Coolify çağrısı 0,2–0,5 sn; CMS build 3–5 dk; Coolify eşzamanlı build sayısı `c` (sunucu ayarı). Poll aralığı 10 dk (`config/ops.php:112`).

| Senaryo | 50 site | 500 site | Darboğaz | Öneri |
|---------|---------|----------|----------|-------|
| Health turu (iş sayısı) | 100 | 1000 | Tek worker, tek kuyruk (S10-B05) | PO02, PO03 |
| Health turu süresi, Plane tabanı (ölçülen) | 15 sn | 150 sn | RateGuard uykusu + DB | PO02 |
| Health turu süresi, tahmini gerçek | 50–115 sn (%8–19) | **500–1150 sn (%83–190)** | Ağ + tek süreç | PO02, PO03 |
| %10 site erişilemez (site başına 2×10 sn + ≤5 sn) | +125 sn | **+1250 sn** | Agent timeout × retry (`config/ops.php:111,120-122`) | PO02 (başarısız siteler ayrı tempo) |
| Coolify çağrısı/saat (yalnız inspect) | 600 | **6000** | Coolify throttle (limit Doğrulanmadı) | PO02, PO04 |
| Toplu deploy süpürmesi (≥3 çağrı × (0,12+0,3) sn) | ≈65 sn | **≈650 sn > 300 sn timeout** | Tek iş, süre bütçesi yok | S10-O06, PO05 |
| Toplu deploy build'leri Coolify'da | 150–250 dk / c | 1500–2500 dk / c | Coolify build kapasitesi | PO05 |
| Poll bütçesi (40 deneme ≈ 34 dk) | ilk ≈ 7–11×c build | aynı; kalan satırlar sahte `failed` | Kuyrukta beklemek de denemeden sayılıyor | PO06 |
| Tema auto-update fan-out | ≤50 iş | 200 iş (fazlası sessizce düşer) | 200 sınırı + tek worker; iş başına ~20 sn ile ≈67 dk dolu (tahmin) | PO07 |
| Sites tam sayfa (soğuk) | 93 sorgu / 0,76 sn | 552 / 5,0 sn | N+1 (PB04) | PO01 |
| Sites tam sayfa (60 sn içinde sıcak) | ≈50 sorgu | ≈50 sorgu | satır başına 1 sorgu (25) | PO01 |
| Jobs widget (hızlı kademe, sekme başına) | 40 poll/dk × 66 sorgu ≈ 2640 sorgu/dk | aynı + her poll'da tüm filo yükleniyor | `OpsCoolifyDeployQueue.php:32-35` | PO08 |
| Activity (yıllık yeni satır, tahmin) | 23 K–146 K | 230 K–1,46 M | UNION + COUNT, tam tarama (S10-B12) | S10-O09, PO09 |
| Live sync (8 sn timeout, seri) | ≈25 sn | ≈250 sn; ~7 timeout ile 300 sn aşılır | 300 sn iş timeout'u | S10-O06 |
| Toplu purge (Cloudflare, site başına 6–12 çağrı) | 300–600 | 3000–6000 (> 1200 / 5 dk, Doğrulanmadı) | 429 işlenmiyor (S6-B13) | S6-O11, PO10 |
| Sayfa ağırlığı (layout) | 291 KB ham / 61 KB gzip | aynı | gzip/expires ayarı yok (Doğrulanmadı) | PO10 |

### 2.1 Hesap ayrıntıları

| Hesap | Formül | 50 | 500 |
|-------|--------|----|-----|
| Health turu, site başına | Check 0,062 sn + Inspect 0,236 sn (ölçülen) + agent RTT (0,3–1,0) + 2 × Coolify (0,2–0,5) | 1,0–2,3 sn | aynı |
| Health turu toplamı | site × site başı süre / worker sayısı (1) | 50–115 sn | 500–1150 sn |
| Erişilemeyen site maliyeti | 2 × 10 sn timeout + ≤ 5 sn 429 bekleme (`config/ops.php:111,120-122`) | 25 sn/site | aynı |
| Coolify çağrısı/saat (inspect) | site × 2 çağrı × (60 / 10 dk) | 600 | 6000 |
| Coolify çağrısı/saat (widget) | bağlantı × 3600 / 5 sn cache, sekme açıkken (`config/ops.php:172`) | ≤ 720 | ≤ 720 |
| Poll bütçesi | Σ min(60, ⌊15 × (1 + 0,25(n−1))⌋), n = 1..39 (`PollDeploymentJob.php:172-179`) = 2043 sn | ≈ 34 dk | aynı |
| Bulk deploy süpürmesi | site × (listEnvs + updateEnvs + deploy ≥ 3 çağrı) × (0,12 + 0,3 sn) | ≈ 65 sn | ≈ 650 sn |
| 300 sn'de işlenebilen site | 300 / 1,3 sn (throttle yok) | tamamı | ≈ 230 |
| Tema fan-out yayılımı (son işin gecikmesi) | ⌊(k − 1) / 3⌋ × 10 sn, k = kurulum ≤ 200 (`GitHubWebhookHandler.php:90,95`) | 160 sn (k = 50) | 660 sn (k = 200; tek worker'da anlamsız) |

Önceki inceleme: bu alanda doğrudan madde yoktu. İlgili olanlar Öneri-B6 (stopped siteleri hariç tut, **Kısmen**; PB08/PO02) ve A1 (zamanlanmış işler, **Kısmen**; 11 S10-B09).

## 3. Bulgular

| ID | Tür | Önem | Bulgu | Kanıt | Etki |
|----|-----|------|-------|-------|------|
| S10-PB01 | Perf | Yüksek | Health turu 500 sitede pencereye sığmıyor. Her turda site başına Check + Inspect kuyruğa atılıyor; hepsi tek worker'da. Tur uzarsa `uniqueFor=240` düşüyor ve kopya iş giriyor (S4-B11). Stopped/draft siteler de taranıyor (S2-B09/S4-B02). | `DispatchSiteHealthChecksJob.php:19-27`; `CheckSiteHealthJob.php:19`; `InspectSiteAppHealthJob.php:19`; `docker/supervisor/supervisord.conf:27`; §1.1 ölçümü | `last_health_at` bayatlıyor, stale → unhealthy yanlış alarmı; deploy/tema işleri health arkasında bekliyor |
| S10-PB02 | Perf/Hata | Yüksek | Toplu süpürme tek `ProcessOpsBackgroundJob` içinde (300 sn) ve süre bütçesi yok. 500 sitelik bulk deploy ≥ 650 sn sürüyor; iş öldürülüyor, kilit 900 sn kalıyor, satır `running` kalıyor (mekanizma S10-B08). | `OpsJobRunner.php:192-199,356`; `CoolifyApplicationService.php:229-232`; `CoolifyAppEnvSync.php:63,84,185`; `ProcessOpsBackgroundJob.php:18` | Filo çapındaki işlem yarıda kalıyor, operatör hangi sitelerin atlandığını göremiyor |
| S10-PB03 | Hata | Yüksek | Poll bütçesi yaklaşık 34 dk: 40 deneme, 15 sn'den 60 sn'ye büyüyor. Coolify kuyruğunda bekleme de bu bütçeden düşüyor. Toplu deploy'da bütçeyi aşan her satır `failed` + "Timed out waiting" oluyor ve satır başına bir `DiagnoseDeploymentJob` + `sync_deployments` otomatik düzeltmesi (Coolify okuması) tetikleniyor. | `PollDeploymentJob.php:120-128,172-179`; `config/ops.php:201-203`; `Deployment.php:60-71`; `DeploymentFailureClassifier.php:258-261` | 500 sitede yüzlerce sahte hata satırı, teşhis yükü ve "son 24 saatte başarısız" KPI'ı şişer |
| S10-PB04 | Perf | Orta | Sites tam sayfada N+1. `categoryCounts` `latestDeployment` ilişkisini eager-load ediyor, ama `latestDeployment()` yalnız `deployments` ilişkisine bakıyor ve site başına sorgu atıyor. Ayrıca sayfadaki her satır için 1 sorgu (25). | `SiteAppHealthFixer.php:121-124`; `SiteAppHealthInspector.php:206-221`; `config/ops.php:72` (60 sn TTL); ölçüm §1.1 | Dakikada bir soğuk render O(n): 500 sitede 5 sn |
| S10-PB05 | Perf | Orta | `CoolifyRateGuard` süreç içi singleton. FPM istekleri ile worker aynı host bütçesini paylaşmıyor ve koordinasyon yok. Worker'da her Coolify çağrısı öncesinde en az 120 ms uyku var: inspect başına ~240 ms, 500 sitede turda ≥120 sn. | `AppServiceProvider.php:27`; `CoolifyRateGuard.php:17-22,27-44` | Bütçe gerçekte korunmuyor, worker zamanı uykuda harcanıyor |
| S10-PB06 | Risk | Orta | Coolify API çağrı bütçesi ölçülmüyor. Health inspect 500 sitede saatte 6000 çağrı yapıyor; buna widget (bağlantı başına 5 sn cache → saatte ≤720), poll zincirleri ve toplu işler ekleniyor. Coolify'ın throttle eşiği bilinmiyor. | `SiteAppHealthInspector.php:40-47`; `config/ops.php:172,201`; **Doğrulanmadı:** Coolify API limiti. Doğrulama: Coolify instance throttle ayarı / 429 sayısı (Plane logunda `coolify.*` warning) | 429 dalgası tüm operatör işlerini yavaşlatır (runbook `coolify-rate-limit.md` olayı tekrarlanır) |
| S10-PB07 | Perf | Orta | Tema fan-out'undaki "3'lü / 10 sn" tempo tek worker'da anlamsız; işler zaten seri koşuyor. 200 iş (smoke dahil iş başına 120 sn'ye kadar) health işlerinin arasına giriyor; 200'ü aşan kurulumlar sessizce düşüyor (S5-B04). | `GitHubWebhookHandler.php:71,90,95`; `ThemeRolloutService.php:132-135`; `ThemeUpdateJob.php:18` | Push sonrası health turu kayar; 500 sitede opt-in > 200 ise güncelleme eksik kalır |
| S10-PB08 | Perf | Orta | Tarama önceliklendirmesi yok. Sağlıklı, başarısız, stopped ve draft siteler aynı sıklıkta taranıyor; inspect (Coolify) her turda agent check ile birlikte koşuyor, oysa env/pack/domain sorunları deploy olmadan nadiren değişir. | `DispatchSiteHealthChecksJob.php:19-27`; `SiteAppHealthInspector.php:38-53` | Coolify çağrılarının büyük kısmı değişmeyen veriyi yeniden okuyor |
| S10-PB09 | Perf | Orta | Jobs widget her poll'da 66 sorgu yapıyor ve Coolify kuyruk eşlemesi için tüm filoyu (`uuid`'li siteler) belleğe yüklüyor. Hızlı kademe 1,5 sn, sekme başına. | `OpsCoolifyDeployQueue.php:32-35`; `config/ops.php:93-96`; `tests/Feature/Ops/JobsPollPressureTest.php` | Çok sekmede DB yükü; 500 sitede poll başına 500 satırlık hydrate |
| S10-PB10 | Perf | Orta | Cloudflare toplu işlemleri tempo ve 429 yönetimi olmadan 500 sitede hesap limitine yaklaşıyor. `removeHostRecords` host başına zone arama + kayıt listesi + silme yapıyor. | `CloudflareZoneService.php:279-323,403`; S6-B13, S6-B14 | Toplu purge/bind yarıda kalır, kısmi DNS durumu oluşur |
| S10-PB11 | Perf | Düşük | `reportedDeamonVersions` tam filo taraması ölçümde +1 sorgu ve ihmal edilebilir süre verdi (500 site). O(n) bellek kaygısı geçerli ama öncelik düşük. S1-B15/S2-B22/S4-B17 ile aynı konu. | `SiteController.php:352`; §1.1 (`cms=outdated` 553 vs 552 sorgu) | 5000+ sitede anlamlı olur |
| S10-PB12 | Perf | Düşük | Sayfa ağırlığı: her sayfada 3 CSS + 9 JS = 291 KB ham (gzip -9 ile 61 KB). Build/minify yok. `ops-confirm.js` ve `ops-coolify-form.js` `?v=` taşımıyor. nginx'te statik dosya için `gzip_types`/`expires` yok. Google Fonts her sayfada dış istek. | `resources/views/layouts/ops.blade.php:27-30,249-257` (`:250` sürümsüz); `resources/views/ops/coolify/show.blade.php:409`; `docker/nginx/default.conf:1-45`. **Doğrulanmadı:** imajdaki `/etc/nginx/nginx.conf` gzip ayarı | Deploy sonrası eski `ops-confirm.js` önbellekte kalabilir; ilk yükleme 291 KB |
| S10-PB13 | Perf | Düşük | Live sync seri ve 8 sn timeout'lu, 300 sn'lik tek iş içinde koşuyor. 500 sitede yaklaşık 7 timeout süreyi aşmaya yetiyor. | `OpsJobRunner.php:54-75`; `SiteLiveProbe.php:48` | Uzun toplu işlerle aynı öldürülme yolu |

## 4. İyileştirme ve güncelleme önerileri

Öncelik = Etki×2 − Risk + (S=3, M=2, L=1, XL=0). Puana göre sıralı.

| ID | Öneri | Çözdüğü bulgu | Etki | Efor | Risk | Puan | Kabul kriteri |
|----|-------|---------------|:---:|:---:|:---:|:---:|---------------|
| S10-PO02 | Kademeli tarama: stopped/draft/provisioning atlanır (S4-O02). Agent check 10 dk'da bir; inspect saatte bir, ayrıca her deploy/fix/env değişikliğinden sonra. Başarısız siteler 5 dk'da bir ama site başına tavanla. Dispatch sırası `last_health_at ASC` | PB01, PB06, PB08 | 5 | M | 2 | **10** | 500 sahte sitede tur başına Coolify çağrısı ≤ 200 (bugün 1000); Plane taban tur süresi ≤ 60 sn (probe) |
| S10-PO03 | `health` kuyruğu + `numprocs=2` (S10-O01 ile). Agent check'ler `Http::pool` ile 5'li gruplar (health job'u site listesi alır) | PB01 | 4 | S | 2 | **9** | Tur süresi (heartbeat metriği) ≤ poll aralığının %50'si; deploy poll'u health turundan etkilenmiyor |
| S10-PO11 | Kapasite metrikleri: `JobProcessing/JobProcessed` dinleyicisi iş tipi başına süre ve bekleme (Redis'te kayan pencere). Health turu başlangıç/bitişi, Coolify 429/saat, poll bütçesi tükenme oranı. `/ops/system`'de gösterilir, §5'teki uyarılara bağlanır | PB01, PB03, PB06 | 4 | M | 1 | **9** | Her iş tipi için p50/p95 süre görünür; eşik testleri (sahte veri) uyarı üretir |
| S10-PO01 | N+1 düzeltmesi: `latestDeployment()` önce yüklü `latestDeployment` ilişkisini kullanır; liste sorgusu zaten eager-load ediyor | PB04 | 3 | S | 1 | **8** | `SiteListAppHealthCountCostTest`'e sorgu sayısı testi: 50 ve 500 sitede tam sayfa sorgusu eşit (±5) ve ≤ 60 |
| S10-PO06 | Poll: Coolify `queued` dediği sürece deneme sayılmaz (ayrı tavan: 6 sa). Webhook öncelikli; zamanlanmış reconcile (S3-O06) seyrek okur | PB03 | 4 | M | 2 | **8** | 50 build kuyruktayken hiçbir satır "Timed out" olmuyor (sahte Coolify testi) |
| S10-PO09 | Activity: varsayılan 30 gün penceresi, `simplePaginate` (COUNT yok), `created_at` indeksi (S10-O09) | S10-B12 | 3 | S | 1 | **8** | 1 M satırlık fixture'da sayfa sorgusu `EXPLAIN` ile indeks kullanıyor; COUNT sorgusu yok |
| S10-PO04 | RateGuard'ı Redis'e taşı: host başına atomik token bucket (Lua ya da `Cache::lock` + sayaç), FPM ve worker ortak. Uyku yerine "sonra dene" (job release) | PB05 | 3 | M | 2 | **6** | İki süreç aynı hosta saniyede ≤ limit çağrı yapıyor (entegrasyon testi); worker uykusu toplamı turda < 10 sn |
| S10-PO05 | Toplu deploy'u Plane tarafında yuvarla: Coolify'a en çok K build (varsayılan `c`) ileride; kalanlar `OpsBackgroundJob` cursor'ında bekler (S10-O06 devamı) | PB02, PB03 | 4 | L | 3 | **6** | 500 sitelik bulk deploy'da Coolify kuyruğu hiçbir an K+1'i geçmiyor; sahte "Timed out" 0 |
| S10-PO07 | Tema fan-out `long` kuyruğunda, dalga tabanlı (S5 kademeli yayılım önerisiyle), 200 kesmesi yanıtta ve audit'te görünür | PB07 | 3 | M | 2 | **6** | Fan-out sırasında health turu süresi değişmiyor; 250 kurulumda yanıt `truncated: 50` diyor |
| S10-PO08 | Widget: filoyu değil yalnız Coolify satırlarındaki uuid'leri sorgula (`whereIn`); kullanıcı başına 1 sn yanıt cache'i | PB09 | 2 | S | 1 | **6** | `JobsPollPressureTest`'e sorgu sayısı: poll başına ≤ 20, N'den bağımsız |
| S10-PO10 | Statik dosyalar: nginx `gzip on; gzip_types text/css application/javascript;` + sürümlü dosyalara `expires 1y`; eksik 2 `?v=`; Cloudflare toplu işlemleri için S6-O11 + işlem başına tempo | PB10, PB12 | 2 | S | 1 | **6** | `curl -H 'Accept-Encoding: gzip' -I` CSS için `Content-Encoding: gzip`; tüm `<script src>` `?v=` taşıyor (Blade grep testi) |

## 5. Otonomi fırsatları — kapasite uyarıları

| Akış | Bugünkü seviye | Hedef | Tetik | Ön koşul | Güvenli otomatik adım | Doğrulama | Geri alma | Guardrail/bütçe | Kill switch | Audit | Bildirim |
|------|:---:|:---:|-------|-----------|------------------------|-----------|-----------|-----------------|-------------|-------|----------|
| Health turu aşımı | L0 | L4 | Tur süresi > aralığın %80'i, art arda 2 tur | PO11 metriği | Sonraki turda inspect'i atla, yalnız agent check; aralığı bir kademe büyüt (en çok 15 dk, `routes/console.php:14`) | Sonraki tur < %60 | Metrik 6 tur normal kalınca eski aralık | Aralık ≤ 15 dk | `ops.capacity.adaptive_health` | `system.capacity.health_throttled` | Banner + özet |
| Kuyruk birikimi | L0 | L2 | En eski iş > 5 dk ya da derinlik > 2×site | Heartbeat taze | Banner: hangi iş tipleri bekliyor (neden + öneri) | — | — | — | — | `system.capacity.queue_backlog` | Mail 30 dk'da bir |
| Coolify 429 | L1 (satırda) | L4 | Saatte > 20 adet 429 | — | Health inspect'i 1 saat duraklat; toplu işlere cooldown | 429/saat < 5 | Süre dolunca otomatik | 1 saat | `ops.capacity.coolify_backoff` | `system.capacity.coolify_throttled` | Banner |
| Toplu iş ön kontrolü | L0 | L3 | Toplu iş başlatılırken tahmini süre > 240 sn | Site sayısı × ortalama süre (PO11) | Onay modalında tahmini süre + "parçalı çalıştırılacak" | — | İptal | — | — | mevcut job audit'i | Modal |
| Poll bütçesi tükenmesi | L0 | L4 | Son 1 saatte `coolify_timeout` > 10 | — | Toplu deploy'da yeni build göndermeyi duraklat (PO05) | Oran düşüyor | Otomatik devam | — | `ops.capacity.deploy_pause` | `system.capacity.deploy_paused` | Banner |
| Tema fan-out sınırı | L0 | L2 | Opt-in kurulum > 200 | — | Webhook yanıtında ve Activity'de `truncated` | — | — | — | — | `theme.fanout_truncated` | Özet |
| Tablo büyümesi | L0 | L2 | `audit_logs`/`deployments` > 1 M satır ya da DB > 2 GB | — | /ops/system uyarısı + S10-O09 önerisi | — | — | — | — | — | Özet |

## 6. Yeni özellik fikirleri

- "Kapasite" kartı: iş tipi başına p95, health turu süresi, Coolify çağrı/saat, tahmini doygunluk ("mevcut hızla 380 site sonra health turu pencereye sığmaz").
- `ops:simulate-fleet --sites=500` (yalnız local/testing): sahte site + `Http::fake` ile tur süresini ölçer. Bu raporun probunun kalıcı hali; CI olmadığı için elle koşulur.
- Liste için ön hesaplı `deamon_version` kolonu (S4-B17 önerisiyle), histogram kartı.

## 7. CMS handoff

| Konu | CMS tarafında gereken |
|------|------------------------|
| Health yanıt süresi | `/internal/control/v1/health` p95 hedefi (≤ 300 ms) ve pahalı kontrollerin (kuyruk, DB) önbelleklenmesi; tur süresinin büyük kısmı bu uca bağlı |
| Toplu health (opsiyonel) | Yok; site başına uç yeterli, Plane tarafında tempo çözülür |

## 8. Açık sorular

| Soru | Önerilen varsayılan |
|------|---------------------|
| Coolify API throttle eşiği ve eşzamanlı build sayısı (`c`) nedir? | Emin'den Coolify ayarı alınır; öğrenilene kadar `c=1` ve 60 çağrı/dk varsayılır |
| Inspect sıklığı saatte bire düşerse env drift ne kadar geç görülür? | Deploy/fix/env sonrası tetiklenen inspect bunu kapatır; kabul edilebilir |
| Hedef filo büyüklüğü ve zaman çizelgesi? | 12 ay içinde 200 varsayımıyla PO01–PO03 ve PO11 önce |
