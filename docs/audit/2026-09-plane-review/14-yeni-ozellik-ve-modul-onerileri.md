# 14 — Yeni özellik ve modül önerileri

**Tarih:** 2026-09-24 · **HEAD:** 8484d37 · **Hazırlayan:** S12 — Yeni özellik ve modül analisti (Dalga 2)
**Kapsam:** Dalga 1 raporlarının §5 fikirleri ([02](02-mimari-ve-kod-sagligi.md)–[09](09-ui-ux-i18n-erisilebilirlik.md)), önceki incelemenin Öneri-B1–B13 / D1–D6 / F1–F7 maddeleri ([15](15-onceki-inceleme-durum-takibi.md) durumlarıyla), yeni modüllerin dayanacağı mevcut yapı taşları (kod okuma) · **Kapsam dışı:** Dalga 1 bulgularının düzeltme önerileri (ilgili raporda kalır), CMS kodu, canlı sistemler.
Not: 10–13 raporları bu dosya yazılırken hazırlık klasöründe yoktu (başta ve sonda kontrol edildi). Onlara dayanan noktalar "Doğrulanmadı" etiketlidir.

## Özet

- **24 aday kart:** 19 Uygun, 2 CMS gerekli (S12-M04 yedek, S12-M15 uzak bakım), 3 Kapsam dışı — bilinçli karar gerekir (S12-M22–M24). Her kart en az bir Dalga 1 bulgu ID'sine veya `dosya:satır` kanıtına bağlı; bağsız aday yok.
- **Puana göre ilk 5:** S12-M01 Uyarı ve bildirim merkezi (10), S12-M16 Env kataloğu değişiklik kapısı (10), S12-M19 Otomasyon kontrol paneli (9), S12-M20 Plane öz-sağlık ve entegrasyon paneli (9), S12-M03 TLS/DNS gözetimi (9). S12-M13 Plane hesapları + 2FA da 9 puan alıyor. Eşitlik kuralına göre 6. sırada; güvenlik açısından önkoşul olduğu için §7'de öne alma sorusu olarak duruyor.
- **Ortak önkoşullar:** yeni tabloların hepsi A8 prune'unu (Yapılmadı) gerektiriyor. L4 otomasyonların hepsi S1-O23'e (sistem aktörü) ve S12-M19'a (kill switch'ler DB'de) bağlı. Kanarya rollout ise önce S5-O01 ve S5-O06 Kritik düzeltmelerini bekliyor.
- **Önceki rapor:** Öneri-B1–B13, D1–D6 ve F1–F7 toplam 26 madde. Yapıldı durumunda olan yok; 15'e göre 5'i Kısmen (B3, B6, B12, F2, F4). 20'si bu dosyada bir karta birleşti. B3, B4, F5 ve F6 düzeltme olarak sayıldı (§5.3). F4 ve F7 CMS handoff'ta (§6) ve M21'in v2'sinde.
- **Birleştirme:** Dalga 1 §5'te 53 fikir var (02:5, 03:7, 04:8, 05:8, 06:7, 07:7, 08:6, 09:5). 33'ü 14 karta birleşti. 17'si modül sayılmayıp sahibi rapora bırakıldı (§5.3). 3'ü kapsam dışı (M22, M23, proxied SSL).

## 1. Mevcut durum

Yeni modüllerin oturacağı omurga (bugünkü akış):

1. Zamanlayıcı: `routes/console.php:16-42`. 4 giriş var (health fan-out, env kataloğu, Deskron reconcile, fleet snapshot). Hiçbirinde `onOneServer` yok.
2. Job'lar durumu `sites.*` kolonlarına ve `deployments` tablosuna yazıyor. Operatör tetiklemeli işler `OpsBackgroundJob` üzerinden koşuyor (`app/Services/Ops/OpsJobRunner.php:34-49`, 14 sabit tip).
3. Denetim kaydı `audit_logs` tablosunda (`database/migrations/2026_08_13_071303_create_audit_logs_table.php:12-23`). `reason` kolonu yok. `actor_user_id` nullable, yani sistem aktörü şemada mümkün.
4. Görünürlük tarafında Fleet KPI (`app/Services/Fleet/FleetDashboardKpis.php:33`), Activity (`app/Support/Ops/ActivityFeed.php`) ve günlük snapshot (`fleet_daily_snapshots`) var.
5. Bildirimler senkron e-posta: `SiteHealthMailNotifier` → `PlatformOpsMailer`.

| Yapı taşı | Sorumluluk | Satır | Test | Üstüne kurulacak aday |
|-----------|------------|-------|------|------------------------|
| `app/Services/Mail/SiteHealthMailNotifier.php` | ok↔unhealthy kenar maili | 135 | Yok ([05](05-agent-saglik-ve-izleme.md) S4-B20) | M01, M09 |
| `app/Services/Mail/PlatformOpsMailer.php` | Ops mail gönderimi, alıcı seçimi | 151 | Kısmi (`tests/Feature/Mail/*`) | M01, M21 |
| `app/Models/FleetDailySnapshot.php` + `ops:snapshot-fleet` | Günde 1 fleet sayımı | 47 | `tests/Feature/Sites/FleetSnapshotTrendTest.php` | M02, M21 |
| `app/Services/Sites/SiteLiveProbe.php` | Manuel HTTP probe (favicon/status) | 177 | `tests/Unit/Services/SiteLiveProbeTest.php` | M03 |
| `app/Services/Themes/ThemeRolloutService.php` | Tema install/update/smoke/geri alma | 757 | `tests/Feature/Themes/*` (8 dosya) | M07 |
| `app/Services/Sites/ChannelSwitcher.php` | Kanal geçişi + sürüm kapısı | 384 | `tests/Feature/Sites/ChannelSwitchTest.php` | M06 |
| `app/Services/Coolify/CoolifyDeployGate.php` | Sunucu başına eşzamanlı deploy kapısı | 118 | `tests/Feature/Ops/DeployQueueStandingTest.php` | M08, M09 |
| `app/Services/Coolify/CoolifyAppEnvSync.php` | Env upsert + prune | 352 | `tests/Feature/Coolify/*` | M16 |
| `app/Services/Coolify/CoolifyInventorySync.php` | Sunucu/proje/ortam envanteri | 153 | `tests/Feature/Coolify/*` | M08, M17 |
| `app/Services/Ops/OpsJobRunner.php` | 14 arka plan iş tipi | 409 | `tests/Feature/Ops/OpsBackgroundJobTest.php` | M10 |
| `app/Services/Cloudflare/PreviewHostname.php` | Wildcard önizleme host'u | 58 | `tests/Unit/Cloudflare/*` | M05 |
| `app/Models/AuditLog.php` + `ActivityFeed` | Audit, birleşik akış, CSV | 130 / 377 | `tests/Feature/Ops/ActivityFeedTest.php` | M14, M19 |

**Test kapsamı:** Snapshot trendi, live probe ayrıştırması, Activity filtreleri ve deploy kapısı testli. Testsiz olanlar: bildirici kenar geçişleri, mail teslim hatası (S4-B20, S7-B22) ve zamanlayıcı girişlerinin varlığı (`routes/console.php` için test yok, Doğrulanmadı: `grep -rn "ops-snapshot-fleet" tests` ile teyit edilebilir).

## 2. Bulgular

Bu bölümdeki bulgular, yeni bir modül gerektiren eksiklerdir. Düzeltme düzeyindeki bulgular Dalga 1 raporlarında kalıyor.

| ID | Tür | Önem | Bulgu | Kanıt | Etki |
|----|-----|------|-------|-------|------|
| S12-B01 | Eksik | Yüksek | Bildirim tek kanaldan gidiyor: e-posta, senkron, kuyruksuz ve geçmiş tutulmadan. Bastırma ve eskalasyon da yok. Önceki: D1 ve D2 Yapılmadı | `app/Services/Mail/SiteHealthMailNotifier.php:16-40` (yalnız kenar geçişi); `app/Services/Mail/PlatformNotificationCatalog.php:24-30` (kanal boyutu yok); S4-B01, S4-B02, S7-B04, S7-B18 | Gece gelen down alarmı gelen kutusunda kalıyor. SMTP düşerse alarm da düşüyor |
| S12-B02 | Eksik | Orta | Site bazlı sağlık geçmişi yok. Filo için günde yalnız bir anlık görüntü alınıyor. Önceki: D5 Yapılmadı | `database/migrations/2026_09_23_190000_create_fleet_daily_snapshots_table.php:14-21`; S4-B13 | Uptime, "ne zamandan beri düşük" ve SLA sorusu yanıtsız kalıyor |
| S12-B03 | Eksik | Orta | TLS bitiş tarihi ve DNS gözetimi yok. Probe manuel çalışıyor ve sertifikayı okumuyor. Önceki: Öneri-B8 Yapılmadı | `app/Services/Sites/SiteLiveProbe.php:48` (`Http::timeout(8)`, sertifika yok); S6-B17, S4-B19, S4-B08 | Süresi dolan sertifika ilk olarak müşteriden duyuluyor |
| S12-B04 | Eksik | Yüksek | Yedek ya da geri yükleme yüzeyi yok. Kanal düşürme öncesinde yedek alınması operatörün disiplinine kalmış durumda. Önceki: Boşluk-B8 ve F3 Yapılmadı | `docs/runbooks/channel-switch.md:29` ("Plane does not snapshot"); `app/Services/Agent/ControlPlaneAgentContract.php:20-53` (backup yolu yok) | Kanal düşürme veya purge sonrası veri kaybı riski |
| S12-B05 | Eksik | Yüksek | Tema kademeli olarak yayılmıyor. Fan-out tüm opt-in kurulumlara tek turda gidiyor; yalnız 3'lü gruplar 10 sn arayla geciktiriliyor, dalgalar arasında sağlık/smoke kapısı ve durdurma yok. Önceki: A5 Yapılmadı | `app/Services/GitHub/GitHubWebhookHandler.php:71,90` (`floor($index/$concurrency)*10`); S5-B04, S5-B05 | Bozuk bir tema commit'i bütün opt-in sitelere aynı anda iniyor |
| S12-B06 | Eksik | Orta | CMS kanal terfisi elle yapılıyor. Toplu kanal geçişi de prod kuyruğunda hatalı çalışıyor. Önceki: A6 Yapılmadı | `app/Services/Sites/ChannelSwitcher.php:342` (tek eşik `main_minimum_version`); `app/Models/Site.php:845` (`CMS_FILTERS`); S2-B02, S3-B03 | "beta'da N gündür temiz" bilgisi yok. Toplu terfi N−1 siteyi `error` durumuna düşürüyor |
| S12-B07 | Eksik | Orta | Sunucu kapasitesine dair veri tutulmuyor: envanterde yalnız uuid, ad ve IP var. Önceki: Öneri-B9 Yapılmadı | `database/migrations/2026_09_10_001000_create_coolify_connections_and_inventory.php:32-39`; `app/Services/Coolify/Dto/CoolifyServer.php:10-14`; `app/Services/Coolify/CoolifyClient.php:61` (yalnız `listServers`); `config/ops.php:165` | Yeni site hangi sunucuya konacağı bilinmeden yerleştiriliyor. Deploy kapısı reddinin nedeni görünmüyor |
| S12-B08 | Eksik | Yüksek | Bakım penceresi ve deploy dondurma yok. Durdurulmuş siteler de taranıyor ve alarm üretiyor. Önceki: Öneri-B6 Kısmen, Öneri-B11 Yapılmadı | `app/Jobs/DispatchSiteHealthChecksJob.php:19-27` (tüm siteler); `grep -rni freeze app config` boş; S2-B09, S4-B02 | Yanlış "düştü" mailleri geliyor. Kampanya dönemindeki bir deploy ya da tema update'i engellenemiyor |
| S12-B09 | Eksik | Yüksek | Plane'de hesap yönetimi, şifre sıfırlama ve 2FA yok. Alarm alıcıları rol gözetilmeden ilk 20 kullanıcı. Önceki: Öneri-B1/B2 Yapılmadı | `config/fortify.php:162-164` (`features => []`); `database/migrations/0001_01_01_000000_create_users_table.php:15-21` (2FA kolonu yok); `app/Services/Mail/PlatformOpsMailer.php:104-107` | Tüm filo tek bir şifre faktörüyle korunuyor (`docs/security.md:29-31`: Plane ele geçirilirse tüm filo risk altında). Operatör ekleyip çıkarmak SSH gerektiriyor |
| S12-B10 | Eksik | Orta | Müşteri kimliği yok. `sites.notes` hem serbest not hem de build-pack işaretçisi olarak kullanılıyor. Önceki: Öneri-B5 Yapılmadı | `app/Models/Site.php:62,884` (`DOCKERFILE_BUILD_PACK_MARKER` notes içinde); `grep -rn customer_id app database` boş | Davet e-postası (A4) ve müşteri bazlı rapor için kaynak yok |
| S12-B11 | Eksik | Orta | Operatör bağlamı ve site zaman çizelgesi yok: audit makine kaydı olarak tutuluyor, `reason` alanı yok. Önceki: Öneri-B10 ve C3 Yapılmadı | `create_audit_logs_table.php:12-23`; `resources/views/ops/sites/_form.blade.php:384-396` (tek textarea); S1-B16 | Olay sonrası "neden yapıldı" sorusu yanıtsız kalıyor |
| S12-B12 | Eksik | Orta | Uzak bakım komutları ve genel log görünümü yok. Coolify logu yalnız teşhis içinde okunuyor. Önceki: F1 Yapılmadı, F2 Kısmen | `app/Services/Coolify/CoolifyClient.php:309` (`getApplicationLogs`); `ControlPlaneAgentContract.php:20-53` | Cache temizlemek, migrate durumuna bakmak veya log okumak için Coolify UI'a gitmek gerekiyor |
| S12-B13 | Risk | Kritik | Env kataloğunda yapılan değişiklik onaysız olarak filo çapında prune'a dönüşüyor. Korunan anahtarlar yalnız 2 önek ve 3 sabitten ibaret | `app/Services/Coolify/CoolifyAppEnvSync.php:25-39,151-189`; S3-B01 | Tek bir CMS commit'i `DB_PASSWORD` / agent secret silebilir |
| S12-B14 | Eksik | Yüksek | Plane, Coolify, Cloudflare ve CMS arasında sapma (drift) taraması yok. Sapmalar sessiz kalıyor | `database/migrations/2026_08_13_071300_create_sites_table.php:33` (uuid unique değil, S2-B04); `app/Services/Domains/DomainBindSweep.php:31` (bayat `verified_at`, S6-B05); `app/Models/SiteThemeInstallation.php:69-79` (CMS SHA'sı yok, S5-B17); S3-B18, S3-B16, S4-B04 | Yanlış "Bağlı" ya da "Güncel" durumları operatörü yanıltıyor |
| S12-B15 | Eksik | Yüksek | Otomasyon bayrakları yalnız env'de tutuluyor. Activity'de "Sistem" aktörü filtresi yok | `config/ops.php:116,157,230`; `app/Support/Ops/ActivityFilters.php:34-35` (yalnız rakam `actor`); S8-B04, S3-B11 | L4 bir işi durdurmak redeploy gerektiriyor. Otomatik işlemler görünmüyor |
| S12-B16 | Eksik | Orta | Plane kendi sağlığını izlemiyor. `failed_jobs` için arayüz yok. Önceki: D6 ve E1 Yapılmadı | `bootstrap/app.php:19` (yalnız `/up`); `config/queue.php:124-126`; S4-B22, S1-B09 | Worker ya da scheduler ölürse bütün filo "stale" görünüyor ama banner çıkmıyor |
| S12-B17 | UX | Düşük | Runbook referansları düz metin, tıklanamıyor. Önceki: Öneri-B12 Kısmen | `lang/tr/deploy_diagnosis.php:23`; `resources/views/ops/deployments/show.blade.php:180`; `docs/runbooks/` 9 dosya | Hata ekranından ilgili prosedüre geçiş elle yapılıyor |
| S12-B18 | Eksik | Düşük | Dışarıdan tüketilebilecek salt-okunur yüzey (API/CLI rapor) yok. Önceki: Öneri-B13 Yapılmadı | `routes/api.php` yok; `bootstrap/app.php:16` `withRouting` (api yok) | Grafana ya da bot entegrasyonu mümkün değil |
| S12-B19 | Borç | Düşük | İşlem kaydı sabit `match` ile tutuluyor. Aksiyonlar aktörsüz çağrılamıyor, bu da playbook altyapısını engelliyor | `app/Services/Ops/OpsJobRunner.php:34-49`; `app/Services/Sites/ChannelSwitcher.php:49` (S1-B17) | Çok adımlı prosedürler otomatikleştirilemiyor |

Önem dağılımı: Kritik 1 · Yüksek 7 · Orta 8 · Düşük 3.

## 3. İyileştirme ve güncelleme önerileri

Aday modüller aşağıda puana göre sıralı. Puan formülü: Etki×2 − Risk + (S=3, M=2, L=1, XL=0). Eşit puanda sıralama sırasıyla şöyle yapılır: düşük risk, küçük efor, bağlı Kritik/Yüksek bulgu sayısı.

| ID | Öneri | Çözdüğü bulgu | Etki (1–5) | Efor (S/M/L/XL) | Risk (1–5) | Öncelik puanı | Kabul kriteri |
|----|-------|---------------|-----------|-----------------|-----------|---------------|---------------|
| S12-M01 | Uyarı ve bildirim merkezi | S12-B01 | 5 | M | 2 | **10** | Her bildirim `ops_notifications` satırı yazar. SMTP hatasında 3 deneme yapılır, sonra `failed` rozeti çıkar. İmzalı webhook kanalı mail kanalından bağımsız teslim eder. 2 ardışık hata gelmeden mail gitmez |
| S12-M16 | Env kataloğu değişiklik kapısı | S12-B13 | 5 | M | 2 | **10** | Katalogdan anahtar kaldırıldığında prune 0 olur ve Settings'te fark görünür. Super Admin onayından sonra uygulanır. Bir kez `generated` işaretlenmiş anahtar hiçbir zaman silinmez |
| S12-M19 | Otomasyon kontrol paneli | S12-B15 | 4 | M | 1 | **9** | 4 bayrak Settings'ten redeploy gerekmeden kapatılabilir. `activity?actor=system` yalnız aktörsüz satırları döndürür. Bütçe aşımında `skipped:budget` audit yazılır |
| S12-M20 | Plane öz-sağlık + entegrasyon paneli | S12-B16 | 4 | M | 1 | **9** | Heartbeat 2×poll'dan eskiyse banner çıkar. `failed_jobs` listelenir ve retry edilebilir. Her entegrasyon için son başarılı çağrı ve son webhook zamanı görünür |
| S12-M03 | TLS / DNS gözetimi | S12-B03 | 4 | M | 1 | **9** | Saatlik ve tempolu probe çalışır. Bitişine 14 günden az kalan sertifika dikkat kartına düşer. DNS A kaydı origin'le uyuşmazsa `dns_mismatch` işaretlenir. Probe'ta yazma çağrısı 0 |
| S12-M13 | Plane hesapları + 2FA | S12-B09 | 5 | L | 2 | **9** | Super Admin `/users` ekranından davet eder, rol değiştirir, hesabı devre dışı bırakır. Fortify `resetPasswords` ve `twoFactorAuthentication` açık. `super_admin` 2FA olmadan ops route'larına giremez |
| S12-M17 | Drift dedektörü | S12-B14 | 5 | L | 2 | **9** | `ops:detect-drift` 6 kontrolü yazma yapmadan çalıştırır. Aynı uuid'e bağlı iki site ve bayat `verified_at` Http::fake testinde yakalanır. Fleet'te sapma kartı görünür |
| S12-M09 | Bakım penceresi + deploy dondurma | S12-B08 | 4 | M | 2 | **8** | `maintenance_until` dolu olan sitede mail, otomatik düzeltme ve tema auto-update 0. Dondurma penceresinde toplu ve otomatik deploy reddedilir, tekli deploy yalnız Super Admin'le yapılır |
| S12-M02 | Sağlık geçmişi + SLA raporu | S12-B02 | 4 | M | 2 | **8** | Her geçiş bir `site_health_events` satırı yazar. Site detayında 30 günlük uptime yüzdesi görünür. Fleet'te deploy başarı oranı ve p95 süre kartı var |
| S12-M07 | Tema kanarya rollout | S12-B05 | 5 | L | 3 | **8** | Alpha dalgasında smoke hatası olursa beta ve main dalgaları 0 hedef alır. Plan `halted` olur, operatör sürdür/iptal edebilir |
| S12-M21 | Haftalık ops özeti | S12-B01, S12-B02 | 3 | S | 1 | **8** | Pazartesi 08:00'de tek mail gider. İçeriği: yeni siteler, başarısız deploy'lar, en çok düşen 5 site, bekleyen mailbox istekleri. Kapatılabilir |
| S12-M04 | Yedek / geri yükleme orkestrasyonu | S12-B04 | 5 | L | 4 | **7** | Main'den düşürmede son 24 saatlik yedek meta kaydı yoksa geçiş reddedilir. Yedek yalnız meta olarak saklanır (boyut, zaman), dosya Plane'e gelmez |
| S12-M08 | Kapasite paneli | S12-B07 | 3 | M | 1 | **7** | Coolify sayfasında her sunucu için site sayısı, açık deploy sayısı ve durum dağılımı görünür. Create formu en az yüklü sunucuyu önerir |
| S12-M11 | Müşteri kartı | S12-B10 | 3 | M | 1 | **7** | `customers` CRUD çalışır, site→customer bağlanır, Sites müşteriye göre filtrelenir. Davet e-postası müşteri kaydından ön doldurulur |
| S12-M14 | Olay defteri + site zaman çizelgesi | S12-B11 | 3 | M | 1 | **7** | Site notu ekleme audit'lenir. Detayda audit, deploy ve not satırları zaman sırasıyla tek listede görünür |
| S12-M15 | Uzak bakım + log kuyruğu | S12-B12 | 3 | M | 2 | **6** | Site detayındaki log paneli son 200 satırı `SecretRedactor`'dan geçirerek gösterir (Plane-only). CMS komutları ancak handoff tamamlanınca eklenir |
| S12-M18 | Runbook bağlantılı hata ekranları | S12-B17 | 2 | S | 1 | **6** | Teşhis kodu, app-health issue ve dikkat kartı tıklanabilir runbook linki verir. Kırık anchor testi var |
| S12-M06 | CMS kanal terfisi (release train) | S12-B06 | 4 | L | 4 | **5** | "beta'da N gündür sağlıklı" aday listesi çıkar. Dalga planı sağlık kapısıyla ilerler. İlk hatada durur |
| S12-M05 | Staging / preview klon | S12-B04, S12-B06 | 3 | L | 3 | **4** | Klon kendi MySQL/Redis'i ve preview host'uyla açılır. TTL dolunca purge edilir. Klon aynı `coolify_app_uuid`'e bağlanamaz |
| S12-M12 | Salt-okunur API / CLI | S12-B18 | 2 | M | 3 | **3** | `ops:fleet-report --json` secret içermez. API ancak M13 tamamlanınca açılır, token ile ve `viewer` yetkisiyle |
| S12-M10 | Operatör playbook motoru | S12-B19 | 3 | XL | 4 | **2** | Aksiyon kaydı üstünde en az 1 çok adımlı playbook (ör. "agent secret onarımı") adım adım ilerler ve durdurulabilir |
| S12-M22 | Domain bitiş (RDAP) | S12-B03 | 2 | S | 2 | 5 | Kapsam dışı — bilinçli karar gerekir |
| S12-M23 | Müşteriye açık status sayfası (D3) | S12-B02 | 2 | M | 3 | 3 | Kapsam dışı — bilinçli karar gerekir |
| S12-M24 | Site bazlı viewer görünürlüğü (C6) | S12-B10 | 2 | M | 4 | 2 | Kapsam dışı — bilinçli karar gerekir |

Meta öneriler (modül değil, sıralama ve paketleme):

| ID | Öneri | Çözdüğü bulgu | Etki (1–5) | Efor (S/M/L/XL) | Risk (1–5) | Öncelik puanı | Kabul kriteri |
|----|-------|---------------|-----------|-----------------|-----------|---------------|---------------|
| S12-O01 | Yeni tablo açan her adaydan (M01, M02, M03, M14, M17) önce A8 prune spec'i: `Prunable` + `model:prune` schedule | S12-B01, S12-B02, S12-B14 | 4 | S | 1 | **10** | Her yeni tablonun modeli `Prunable`. `routes/console.php`'ta `model:prune` girişi var |
| S12-O02 | M19 ve M20 tek spec olsun ("Sistem ve otomasyon"): ikisi de Settings/System sayfası ve heartbeat tablosunu paylaşıyor | S12-B15, S12-B16 | 3 | S | 1 | **8** | Tek spec dosyası. İki modülün route'ları ortak `/system` önekini kullanır |
| S12-O03 | M06 ve M07 ortak `rollouts` + `rollout_targets` şemasını kullansın (`target_type = theme\|channel`). Tema izi önce | S12-B05, S12-B06 | 3 | S | 2 | **7** | Kanal izi yeni tablo açmadan aynı şemayla çalışır |
| S12-O04 | Yeni zamanlanmış girişlerin hepsi `withoutOverlapping()->onOneServer()` + heartbeat satırı (M20) ile gelsin | S12-B16 | 3 | S | 1 | **8** | `routes/console.php`'ta `onOneServer`'sız giriş 0 |

## 4. Otonomi fırsatları

| Akış | Bugünkü seviye (L0–L5) | Hedef | Tetik | Ön koşul | Güvenli otomatik adım | Doğrulama | Geri alma | Guardrail/bütçe | Kill switch | Audit | Bildirim |
|------|-----------------------|-------|-------|----------|----------------------|-----------|-----------|-----------------|-------------|-------|----------|
| Alarm eskalasyonu (M01) | L1 (tek mail) | L4 | 2 ardışık unhealthy; 30 dk sonra hâlâ unhealthy | S4-O02 durum makinesi; M09 bakım bayrağı | Kanal(lar)a bildirim; 30 dk sonra 2. kademe | `ops_notifications.status=sent` | Yok (bildirim salt bilgi) | Site başına saatte ≤ 3 bildirim; flapping bastırma | `ops.notifications.enabled` (M19) | `notification.sent/failed` | Mail + webhook |
| TLS/DNS taraması (M03) | L0 | L2 | Saatlik schedule | SSRF guard (S4-O19, 10'a bağlı, Doğrulanmadı) | Salt okuma probe, sonucu `site_domains`'e yazar | `last_tls_checked_at` ≤ 70 dk | Yok (salt okuma) | Host başına 8 sn, tempolu; saatte ≤ 1 tur | `ops.tls_watch.enabled` | Değişiklikte `domain.tls_status_changed` | < 14 gün kalınca M01 |
| Drift taraması (M17) | L0 | L2, sonra L3 (tek tık "hizala") | Saatlik schedule | S2-O04 (uuid tekilliği), S6-O04 | Yalnız tespit + `drift_findings` | Aynı bulgu 2 turda tekrar ederse "kalıcı" | L3 hizalama sonrası önceki değer audit `before`'da | Coolify RateGuard; tur başına ≤ 200 `getApp` | `ops.drift.enabled` | `drift.detected/resolved` | Yeni Kritik drift → M01 |
| Deploy dondurma (M09) | L0 | L4 | Pencere başlangıcı | M19 bayrakları DB'de | `CoolifyDeployGate`'te otomatik/toplu deploy reddi | Pencere içinde otomatik deploy 0 | Pencereyi bitir ya da Super Admin override | Pencere ≤ 7 gün | Pencereyi iptal et | `freeze.started/ended/override` | Başlarken ve biterken M01 |
| Tema kanarya (M07) | L4 korumasız (S5-B04) | L5 | Default branch push'u (S5-O01 sonrası) | S5-O01, S5-O05, S5-O06 | Dalga dalga update + smoke + soak | Dalgada smoke fail 0 ve health ok | Başarısızlıkta dalga hedeflerine `sync-rollback`; plan `halted` | Dalga ≤ N site; soak ≥ X dk; günde ≤ 2 plan | Plan başına duraklat; global `themes.auto_update` (M19) | `theme.rollout.wave_*` | Halt → M01 |
| Plane heartbeat (M20) | L0 | L2 | Dakikalık schedule | — | `ops_heartbeats` satırı yazar | Son yazım ≤ 2 dk | Yok | — | Yok (her zaman açık) | Yok (gürültü) | 2×poll aşılırsa banner + M01 |
| Haftalık özet (M21) | L0 | L4 | Pazartesi 08:00 | M02 verisi | Rapor maili | `ops_notifications` satırı | Yok | Haftada 1 | `ops.digest.enabled` | `digest.sent` | Mail |

## 5. Yeni özellik fikirleri — aday kartları

Kullanıcı kısaltmaları: SA = Super Admin, OP = Operator, VW = Viewer. Kartlar, her Dalga 1 raporunun §5'inden ve önceki rapordan gelen fikirleri birleştiriyor. Her kartın "Birleşen" satırında hangi kaynakların birleştiği yazıyor.

### 5.1 Plane içi adaylar (Uygun)

#### S12-M01 — Uyarı ve bildirim merkezi · 10 · Uygun
| Problem | Kanıt | Kullanıcı | Boyut | Risk | Etki |
|---|---|---|---|---|---|
| Alarm tek kanaldan, senkron ve kayıtsız gidiyor. Gürültülü (429, `needs_secret`) ve kör noktalı | S12-B01; S4-B01, S4-B02, S7-B03, S7-B04, S7-B05, S7-B18, S7-B24 | SA (kanal ayarı), OP (akış, yeniden gönderme) | M | 2 | 5 |
- **MVP kapsar:** `ops_notifications` tablosu ve kuyruklu `SendOpsNotificationJob` (S7-O04). Tek bir imzalı webhook kanalı (Slack/Telegram uyumlu JSON, S7-O16). N-ardışık eşik ve tekrar hatırlatma (D2). `/notifications` listesi ve yeniden gönder.
- **Kapsamaz:** SMS/pager, nöbet rotası takvimi, kişisel olay×kanal matrisi (M13 gelince v2).
- **Bağımlılık:** S4-O02 (sağlık durum makinesi), S12-O01 prune, M09 (bastırma), M19 (kill switch).
- **Birleşen:** Önceki D1, D2, Boşluk-B5 · [08](08-mail-ve-bildirimler.md) §5 "bildirim merkezi" + "abonelik matrisi" · [09](09-ui-ux-i18n-erisilebilirlik.md) §5 "kalıcı bildirim geçmişi".

#### S12-M16 — Env kataloğu değişiklik kapısı · 10 · Uygun
| Problem | Kanıt | Kullanıcı | Boyut | Risk | Etki |
|---|---|---|---|---|---|
| Katalog değişikliği onaysız, filo çapında `DELETE env` olarak uygulanıyor | S12-B13; S3-B01 (Kritik), S3-B10, S3-B26; `CoolifyAppEnvSync.php:151-189` | SA (onay), OP (fark görünümü) | M | 2 | 5 |
- **MVP kapsar:** S3-O01 korkuluğu: tombstone, kaldırmada "bekleyen değişiklik", 30 gün şifreli saklama. Bunun üstüne Settings → Deamon Git'te kanal başına fark ekranı (eklenen / kaldırılan / tür değişen anahtar ve etkilenecek site sayısı). Super Admin onayı ve audit.
- **Kapsamaz:** Müşteri env değerlerinin tamamını Plane'de tutan genel bir "secret kasası". Blast radius büyüyor (`docs/security.md:29-31`), §5.4'te reddedildi.
- **Bağımlılık:** Ayrı spec açılmamalı, S3-O01 spec'i genişletilmeli. CMS handoff ([04](04-coolify-deploy-ve-teshis.md) §6: anahtar kaldırma bir "göç" adımı olmalı).
- **Birleşen:** [04](04-coolify-deploy-ve-teshis.md) §5 "Env kataloğu fark/onay ekranı" · görev listesindeki "env/secret kasası ve fark görünümü".

#### S12-M19 — Otomasyon kontrol paneli · 9 · Uygun
| Problem | Kanıt | Kullanıcı | Boyut | Risk | Etki |
|---|---|---|---|---|---|
| L4 işler (auto-fix, core tema restart, auto-rebind, tema auto-update) yalnız env ile kapatılabiliyor ve Activity'de görünmüyor | S12-B15; S8-B04, S3-B11, S1-B17, S5-B05; `config/ops.php:116,157,230` | SA (anahtarlar), OP/VW (görünürlük) | M | 1 | 4 |
- **MVP kapsar:** DB'de duran bayraklar (env varsayılan, DB üstün), bayrak başına saatlik/günlük bütçe sayacı, son 7 günün otomatik işlem sayısı, Activity'de "Sistem" aktör filtresi ve "Otomatik" rozeti (S8-O01), S1-O23 sistem aktörü sözleşmesi.
- **Kapsamaz:** Kural motoru, yeni otomasyon tipleri.
- **Bağımlılık:** S1-O23 (önkoşul). M01, M07, M09 ve M17'nin kill switch'leri bu panelde yaşar.
- **Birleşen:** [09](09-ui-ux-i18n-erisilebilirlik.md) §5 "Otomasyon sayfası" · [02](02-mimari-ve-kod-sagligi.md) §5 "Tek ops action kaydı" (yalnız meta bayrak kısmı) · Önceki A7 (Kısmen).

#### S12-M20 — Plane öz-sağlık ve entegrasyon paneli · 9 · Uygun
| Problem | Kanıt | Kullanıcı | Boyut | Risk | Etki |
|---|---|---|---|---|---|
| Worker, scheduler ya da entegrasyon kimliği bozulursa bunu görecek yer yok. `failed_jobs` görünmüyor | S12-B16; S4-B22, S1-B09, S3-B14, S7-B20, S5-B03; `bootstrap/app.php:19` | SA, OP | M | 1 | 4 |
- **MVP kapsar:** Heartbeat ve banner (S4-O16). `/system` sayfası: kuyruk derinliği, `failed_jobs` listesi ve retry (E1), zamanlanmış görevlerin son koşuları, entegrasyon kartları (Coolify son 2xx / 429 / cooldown / 401, Cloudflare, GitHub, Hostinger token geçerliliği), son webhook alımları (Coolify, GitHub) ve son 100 teslimat günlüğü.
- **Kapsamaz:** Horizon/Pulse (§5.4), dış APM.
- **Bağımlılık:** S12-O04. GitHub teslimat günlüğü, S5-O03'ün `github_webhook_deliveries` tablosundan okunur ([06](06-temalar-ve-rollout.md) §3). Bu tablo yoksa yalnız son alım zamanı gösterilir.
- **Birleşen:** Önceki D6, E1 · [04](04-coolify-deploy-ve-teshis.md) §5 "Bağlantı sağlık kartı" + "`failed_jobs` görünümü" · [06](06-temalar-ve-rollout.md) §5 "Webhook teslimat günlüğü" · [02](02-mimari-ve-kod-sagligi.md) §5 "Kontrat anlık görüntüleri".

#### S12-M03 — TLS / DNS gözetimi · 9 · Uygun
| Problem | Kanıt | Kullanıcı | Boyut | Risk | Etki |
|---|---|---|---|---|---|
| Sertifika bitişi, DNS sapması ve bekleyen zone yaşı izlenmiyor. TLS ve DNS hataları "timeout" olarak görünüyor | S12-B03; S6-B16, S6-B17, S4-B08, S4-B19; `SiteLiveProbe.php:48` | OP, VW | M | 1 | 4 |
- **MVP kapsar:** Her operatör hostu için saatlik, tempolu probe. TLS `notAfter`, issuer ve SAN okunur. DNS A değeri beklenen origin'le karşılaştırılır. Hata sınıfları `dns/tls/timeout/http`. Pending zone yaşı izlenir. 14 günden az kalan sertifika için dikkat kartı. Salt okunur MX/SPF/DMARC göstergesi.
- **Kapsamaz:** DNS yazma ya da düzeltme, domain kayıt bitişi (M22), proxied/SSL modu (§5.4).
- **Bağımlılık:** SSRF guard (S4-O19 → 10 raporu; Doğrulanmadı, rapor henüz yok). M01'e alarm verir. S6-O14 ile aynı iş, tek spec.
- **Birleşen:** Önceki B8, Boşluk-B10 · [05](05-agent-saglik-ve-izleme.md) §5 "TLS bitiş kolonu" · [07](07-domain-cloudflare-dns.md) §5 "Toplu DNS'i kontrol et" + "Host detay çekmecesi" (veri kaynağı) · [08](08-mail-ve-bildirimler.md) §5 "Mail sağlık kartı".

#### S12-M13 — Plane hesapları + 2FA + bildirim tercihleri · 9 · Uygun
| Problem | Kanıt | Kullanıcı | Boyut | Risk | Etki |
|---|---|---|---|---|---|
| Kullanıcı ekleme, çıkarma ve şifre sıfırlama SSH ile yapılıyor. 2FA yok. Alarm alıcısı rol gözetmeden seçiliyor | S12-B09; `config/fortify.php:162-164`; `PlatformOpsMailer.php:104-107`; `app/Enums/OpsRole.php:7-9` | SA | L | 2 | 5 |
- **MVP kapsar:** `/users` ekranı: davet (şifre belirleme linki), rol değiştirme, devre dışı bırakma, oturumları düşürme. Fortify `resetPasswords` ve `twoFactorAuthentication`, `super_admin` için 2FA zorunlu. Alarm alıcıları yalnız aktif kullanıcılardan ve rol ya da tercihe göre seçilir.
- **Kapsamaz:** SSO/OIDC, müşteri hesabı (Kapsam dışı).
- **Bağımlılık:** Önkoşulu yok. M01'in kişisel tercihleri ve M12 API token'ı buna bağlı. Kilitlenmeye karşı CLI kurtarma komutu gerekli.
- **Birleşen:** Önceki B1, B2, C2 (kısmi), 1.3-f · [08](08-mail-ve-bildirimler.md) §5 "Kişisel abonelik matrisi" (v2).

#### S12-M17 — Drift dedektörü · 9 · Uygun
| Problem | Kanıt | Kullanıcı | Boyut | Risk | Etki |
|---|---|---|---|---|---|
| Plane'in aynası Coolify, Cloudflare ya da CMS gerçeğinden sessizce ayrışıyor | S12-B14; S2-B04 (Kritik), S2-B13, S2-B15, S3-B16, S3-B18, S4-B04, S5-B17, S6-B05; `create_sites_table.php:33` | OP (inceleme), SA (hizalama) | L | 2 | 5 |
- **MVP kapsar:** `ops:detect-drift` salt-okunur çalışır. Kontroller: (1) aynı `coolify_app_uuid`'e bağlı birden çok site, (2) Coolify dalı ile `channel`/`desired_channel` uyumu, (3) pin ve auto-deploy aynası, (4) domain `verified_at` ile Coolify fqdn uyumu, (5) Cloudflare zone durumu, (6) Coolify'da artık olmayan uygulama (yetim). Sonuçlar `drift_findings` tablosuna ve Fleet'teki kartta.
- **Kapsamaz:** Otomatik hizalama (L4). CMS'in kurulu tema SHA'sı ve çalışan commit SHA'sı kontrolü (CMS handoff). Cloudflare yetim kayıt taraması (v2).
- **Bağımlılık:** S2-O04 (tekillik düzeltmesi önce), S6-O04/O05 (domain kısmı aynı iş, tek spec), S12-O01.
- **Birleşen:** [03](03-sites-ve-yasam-dongusu.md) §5 "Tutarlılık (drift) paneli" · [07](07-domain-cloudflare-dns.md) §5 "Yetim kayıt bulucu" (v2) · [06](06-temalar-ve-rollout.md) §5 "SHA pin/hold yönetimi" (sapma görünümü kısmı).

#### S12-M09 — Bakım penceresi + deploy dondurma takvimi · 8 · Uygun
| Problem | Kanıt | Kullanıcı | Boyut | Risk | Etki |
|---|---|---|---|---|---|
| Durdurulmuş ya da bakımdaki site alarm üretiyor. Kampanya döneminde otomatik ve toplu deploy engellenemiyor | S12-B08; S2-B09, S4-B02, S7-B17; `DispatchSiteHealthChecksJob.php:19-27`; `CoolifyDeployGate.php:57` | OP (site bakımı), SA (dondurma) | M | 2 | 4 |
- **MVP kapsar:** Site başına `maintenance_until` ve neden; mail, otomatik düzeltme ve tema auto-update bu sürede susar (S2-O08 bunun alt kümesi). Filo, bağlantı ya da kanal düzeyinde dondurma pencereleri; kapı otomatik ve toplu deploy'u reddeder, tekli deploy Super Admin onayıyla yapılır. Pencere bitince özet gönderilir.
- **Kapsamaz:** Takvim entegrasyonu (iCal), müşteriye duyuru.
- **Bağımlılık:** M19 (bayrak ve override), M01 (özet).
- **Birleşen:** Önceki B6, B11 · [03](03-sites-ve-yasam-dongusu.md) §5 "Bakım modu" · [04](04-coolify-deploy-ve-teshis.md) §5 "Deploy dondurma penceresi" · [05](05-agent-saglik-ve-izleme.md) §5 "Bakım modu / sessize alma" · [06](06-temalar-ve-rollout.md) §5 "Donma penceresi" · [08](08-mail-ve-bildirimler.md) §5 "Bakım penceresi".

#### S12-M02 — Sağlık geçmişi + SLA/güvenilirlik raporu · 8 · Uygun
| Problem | Kanıt | Kullanıcı | Boyut | Risk | Etki |
|---|---|---|---|---|---|
| Uptime, "ne zamandan beri düşük" ve deploy başarı oranı bilgisi yok | S12-B02; S4-B13; `create_fleet_daily_snapshots_table.php:14-21` | OP, VW | M | 2 | 4 |
- **MVP kapsar:** `site_health_events` (from→to, reason, at) (S4-O12). Site detayında 30 günlük şerit ve uptime yüzdesi. Listede "düşük süresi" kolonu. Fleet'te deploy başarı oranı ve p50/p95 kartı (D4), en sık 5 teşhis kodu. Agent gecikmesi (`last_health_latency_ms`).
- **Kapsamaz:** Sözleşmesel SLA ve kredi hesabı (faturalama, Kapsam dışı), müşteriye açık rapor (M23).
- **Bağımlılık:** S4-O02, S12-O01.
- **Birleşen:** Önceki D4, D5 · [04](04-coolify-deploy-ve-teshis.md) §5 "Deploy istatistik kartı" + "Site deploy zaman çizelgesi" (veri) · [05](05-agent-saglik-ve-izleme.md) §5 "Sağlık zaman şeridi" + "Agent gecikme metriği" · [06](06-temalar-ve-rollout.md) §5 "Tema sağlık skoru" (v2).

#### S12-M07 — Tema kanarya rollout · 8 · Uygun
| Problem | Kanıt | Kullanıcı | Boyut | Risk | Etki |
|---|---|---|---|---|---|
| Push'tan sonra bütün opt-in kurulumlar tek dalgada güncelleniyor. Durdurma yok | S12-B05; S5-B04, S5-B05, S5-B21; `GitHubWebhookHandler.php:71,90` | OP, SA | L | 3 | 5 |
- **MVP kapsar:** S5-O04 `theme_rollouts` planı (S12-O03 ile ortak şema). Dalga sırası kanarya site(ler)i, alpha, beta, main. Dalga arası soak süresi ve smoke/health kapısı. Duraklat, sürdür, iptal. Webhook doğrudan fan-out yerine plan açar. Elle "N geride kurulumu güncelle" seçeneği (S5-O18).
- **Kapsamaz:** Değişiklik önizlemesi (GitHub compare, v2), tema sağlık skoru.
- **Bağımlılık:** S5-O01, S5-O05, S5-O06 (Kritik/Yüksek düzeltmeler önce), M19, M01.
- **Birleşen:** Önceki A5 · [06](06-temalar-ve-rollout.md) §5 "Rollout planı sayfası" + "Kanarya site".

#### S12-M21 — Haftalık ops / nöbet özeti · 8 · Uygun
| Problem | Kanıt | Kullanıcı | Boyut | Risk | Etki |
|---|---|---|---|---|---|
| Fleet KPI'ları yalnız anlık. Haftalık bakış yok | S12-B01, S12-B02; `PlatformNotificationCatalog.php:14` (yalnız müşteri raporu) | SA, OP | S | 1 | 3 |
- **MVP kapsar:** `ops_weekly_digest`. İçerik: yeni siteler, başarısız deploy'lar, unhealthy geçişleri, en çok düşen 5 site, otomatik işlem sayısı, bekleyen mailbox istekleri, açık drift'ler.
- **Kapsamaz:** Müşteriye giden rapor.
- **Bağımlılık:** M02 (veri), M01 (teslim).
- **Birleşen:** Önceki A9 · [05](05-agent-saglik-ve-izleme.md) §5 · [08](08-mail-ve-bildirimler.md) §5 "Haftalık ops özeti".

#### S12-M08 — Kapasite paneli · 7 · Uygun
| Problem | Kanıt | Kullanıcı | Boyut | Risk | Etki |
|---|---|---|---|---|---|
| Sunucu doluluğu ve kapı reddinin nedeni görünmüyor | S12-B07; `create_coolify_connections_and_inventory.php:32-39`; `Dto/CoolifyServer.php:10-14`; `config/ops.php:165` | OP | M | 1 | 3 |
- **MVP kapsar:** Yalnız yerel verilerle: sunucu başına site sayısı, durum dağılımı, açık deploy ve kuyrukta bekleyen sayısı. Create formunda "en az yüklü sunucu" önerisi.
- **Kapsamaz:** CPU, RAM ve disk metrikleri. Coolify `GET /servers/{uuid}/resources` ya da metrik ucu var mı, Doğrulanmadı ([04](04-coolify-deploy-ve-teshis.md) §5). Coolify API dokümanıyla ve Http::fake'li bir spike ile teyit edilmeli. Maliyet (para) hesabı yok.
- **Bağımlılık:** Envanter senkronunun zamanlanması (A1 kalan kısmı).
- **Birleşen:** Önceki B9 · [04](04-coolify-deploy-ve-teshis.md) §5 "Sunucu kapasite kartı" + "Plane tarafı deploy kuyruğu" (görünürlük kısmı).

#### S12-M11 — Müşteri kartı · 7 · Uygun
| Problem | Kanıt | Kullanıcı | Boyut | Risk | Etki |
|---|---|---|---|---|---|
| Siteyi müşteri iletişimine bağlayan bir kayıt yok. `notes` alanı aşırı yüklü | S12-B10; `Site.php:62,884` | OP, SA | M | 1 | 3 |
- **MVP kapsar:** `customers` tablosu (ad, iletişim e-postası, telefon, etiketler, sözleşme bitiş tarihi ve not). Site→customer ilişkisi, Sites'ta müşteri filtresi. A4/S2-O18 davet e-postası buradan ön doldurulur. Müşteri başına site listesi ve sağlık özeti (M02 verisiyle).
- **Kapsamaz:** Faturalama ve tutar (Kapsam dışı), müşteri girişi, müşteri bazlı yetki (M24).
- **Bağımlılık:** Yok. S2-O18 bununla birlikte değer kazanır.
- **Birleşen:** Önceki B5 · [03](03-sites-ve-yasam-dongusu.md) §5 "Müşteri kartı" · görev listesindeki "müşteri bazlı raporlama" (M02 verisiyle, v2).

#### S12-M14 — Olay defteri + site zaman çizelgesi · 7 · Uygun
| Problem | Kanıt | Kullanıcı | Boyut | Risk | Etki |
|---|---|---|---|---|---|
| Operatör bağlamı ve "neden" kaydedilmiyor. Durum geçişleri, deploy'lar ve notlar tek bir akışta görülemiyor | S12-B11; S1-B16, S2-B19; `create_audit_logs_table.php:12-23` | OP, VW (okuma) | M | 1 | 3 |
- **MVP kapsar:** `site_notes` (kullanıcı, metin, sabitleme). Activity'de `note` türü. Site detayında zaman çizelgesi sekmesi (audit, deploy, not ve M02 varsa sağlık olayları). Onay modalına isteğe bağlı "neden" alanı (C3).
- **Kapsamaz:** Postmortem şablonu, dış incident aracı.
- **Bağımlılık:** C3 için S1-B16 merkezi audit yazıcısı (58 çağrı) önerilir.
- **Birleşen:** Önceki B10, C3 · [03](03-sites-ve-yasam-dongusu.md) §5 "Olay notu" + "Yaşam döngüsü zaman çizelgesi" · [04](04-coolify-deploy-ve-teshis.md) §5 "Site deploy zaman çizelgesi".

#### S12-M18 — Runbook bağlantılı hata ekranları · 6 · Uygun
| Problem | Kanıt | Kullanıcı | Boyut | Risk | Etki |
|---|---|---|---|---|---|
| Hata ekranında runbook yolu düz metin olarak duruyor | S12-B17; `lang/tr/deploy_diagnosis.php:23`; `show.blade.php:180` | OP | S | 1 | 2 |
- **MVP kapsar:** `RunbookLink` yardımcısı (config'teki taban URL + dosya + anchor). Teşhis kodu, app-health issue kodu ve dikkat kartı eşlemesi. Anchor'ların varlığını kontrol eden test.
- **Kapsamaz:** Runbook içeriğini UI'da render etmek.
- **Bağımlılık:** S4-B07/B08 düzeltmeleri. Yanlış sınıflama yanlış runbook açar.
- **Birleşen:** Önceki B12.

#### S12-M06 — CMS kanal terfisi (release train) · 5 · Uygun
| Problem | Kanıt | Kullanıcı | Boyut | Risk | Etki |
|---|---|---|---|---|---|
| Hangi beta sürümünün main'e hazır olduğu elle takip ediliyor. Toplu geçiş hatalı çalışıyor | S12-B06; S2-B01, S2-B02, S3-B03, S4-B03; `ChannelSwitcher.php:342` | SA | L | 4 | 4 |
- **MVP kapsar:** Sürüm × kanal matrisi (S4-O03 düz kolon). "beta'da N gün, sağlık temiz" aday listesi. Ortak `rollouts` şemasıyla dalgalı kanal geçişi ve ilk hatada durma.
- **Kapsamaz:** CMS release notları, otomatik terfi (L4).
- **Bağımlılık:** S2-O01, S3-O06, S3-B03 düzeltmesi, S4-O03, S12-O03, M04 (main'den düşürmede yedek).
- **Birleşen:** Önceki A6.

#### S12-M05 — Staging / preview klon · 4 · Uygun (DB kopyası CMS gerekli)
| Problem | Kanıt | Kullanıcı | Boyut | Risk | Etki |
|---|---|---|---|---|---|
| Kanal ya da tema denemesi doğrudan canlı sitede yapılıyor | S12-B06; `app/Services/Cloudflare/PreviewHostname.php:28`; `config/ops.php:147` | OP | L | 3 | 3 |
- **MVP kapsar:** Mevcut siteden klon: aynı repo, `beta`/`alpha` dalı, ayrı MySQL/Redis (ADR-2), preview host, boş CMS. TTL dolunca purge. Aynı anda en fazla N klon.
- **Kapsamaz:** DB ve medya kopyası (M04 ve CMS gerekli), müşteriye önizleme linki.
- **Bağımlılık:** S2-O04 ve S2-O09 (purge sertleştirme) önce, M08 (kapasite).
- **Birleşen:** Önceki B7.

#### S12-M12 — Salt-okunur API / CLI · 3 · Uygun
| Problem | Kanıt | Kullanıcı | Boyut | Risk | Etki |
|---|---|---|---|---|---|
| Filo verisini dışarıdan tüketmenin bir yolu yok | S12-B18; `routes/api.php` yok | SA | M | 3 | 2 |
- **MVP kapsar:** Önce CLI: `ops:fleet-report --json`. Yeni bir kimlik doğrulama yüzeyi açmaz. API (`/api/v1/sites`, `/fleet/kpis`) M13 geldikten sonra kişisel token, `viewer` yetkisi ve IP allowlist ile açılır.
- **Kapsamaz:** Yazma uçları.
- **Bağımlılık:** M13. `RestrictOpsByIp` listesi boşsa açık (`app/Http/Middleware/RestrictOpsByIp.php:20-23`). API için bu davranış değiştirilmeli.
- **Birleşen:** Önceki B13.

#### S12-M10 — Operatör playbook motoru · 2 · Uygun (ertele)
| Problem | Kanıt | Kullanıcı | Boyut | Risk | Etki |
|---|---|---|---|---|---|
| Runbook adımları elle uygulanıyor. İş tipleri sabit `match` içinde | S12-B19; `OpsJobRunner.php:34-49`; S1-B17 | OP | XL | 4 | 3 |
- **MVP kapsar:** Şimdilik yok. Önce S1-O23 (sistem aktörü) ve [02](02-mimari-ve-kod-sagligi.md) §5 "Tek ops action kaydı" tamamlanmalı. Sonra tek bir çok adımlı playbook pilotu yapılabilir.
- **Kapsamaz:** Serbest betik ve SSH (ADR-6, Yasak).
- **Birleşen:** [02](02-mimari-ve-kod-sagligi.md) §5 "Tek ops action kaydı" + "Kısmi başarı sonuç nesnesi" · görev listesindeki "operatör görev kuyruğu".

### 5.2 CMS gerekli adaylar

#### S12-M04 — Yedek / geri yükleme orkestrasyonu · 7 · CMS gerekli
| Problem | Kanıt | Kullanıcı | Boyut | Risk | Etki |
|---|---|---|---|---|---|
| Kanal düşürme ve purge öncesinde yedek alınmıyor. Plane yedeğin var olup olmadığını bilmiyor | S12-B04; `docs/runbooks/channel-switch.md:29`; `ControlPlaneAgentContract.php:20-53` | SA, OP | L | 4 | 5 |
- **MVP kapsar (Plane):** `site_backups` meta tablosu (zaman, boyut, konum etiketi). Main'den düşürme ve purge öncesinde "son 24 saatte yedek var mı" kapısı. Tetik ve listeleme agent üzerinden yapılır. Dosya Plane'e gelmez.
- **Kapsamaz:** Geri yükleme (v2, ayrı onay akışı), dışarıya kopyalama (S3/offsite).
- **Bağımlılık:** CMS agent `POST …/backup`, `GET …/backups` (F3). Doğrulanmadı: Coolify'ın zamanlanmış DB yedeği compose içindeki `mysql` servisine uygulanabiliyor mu. Coolify API dokümanıyla teyit edilmeli. Uygulanabiliyorsa CMS'e gerek kalmadan Plane-only bir yol açılır.
- **Birleşen:** Önceki Boşluk-B8, F3.

#### S12-M15 — Uzak bakım komutları + log kuyruğu · 6 · CMS gerekli (log kısmı Uygun)
| Problem | Kanıt | Kullanıcı | Boyut | Risk | Etki |
|---|---|---|---|---|---|
| Cache temizlemek, migrate durumuna bakmak, kuyruğu yeniden başlatmak ve log okumak için Coolify UI'a gidiliyor | S12-B12; `CoolifyClient.php:309`; `ControlPlaneAgentContract.php:20-53` | OP | M | 2 | 3 |
- **MVP kapsar:** Plane-only kısım: site detayında "Loglar" paneli. Mevcut `getApplicationLogs` kullanılır, `SecretRedactor` uygulanır, OP rolüne açıktır. Coolify restart zaten var. CMS kısmı: allowlist'teki bakım aksiyonları (F1) ve `laravel.log` kuyruğu (F2), onay ve audit ile.
- **Kapsamaz:** Serbest artisan veya shell komutu (ADR-6).
- **Bağımlılık:** CMS handoff (§6). Log panelinde secret sızıntısını önlemek için C4 genel secret taraması testi.
- **Birleşen:** Önceki F1, F2, Boşluk-B11.

### 5.3 Modül sayılmayan §5 fikirleri → sahibi

| Fikir | Kaynak | Neden modül değil | Sahibi |
|-------|--------|-------------------|--------|
| Mimari kural testleri, sorgu bütçesi testi | [02](02-mimari-ve-kod-sagligi.md) §5 | Test altyapısı | 02 |
| Kalıcı silme önizlemesi, toplu işlem önizlemesi | [03](03-sites-ve-yasam-dongusu.md) §5 | S2-O09'un UI'ı | 03 |
| Plane tarafı deploy FIFO kuyruğu | [04](04-coolify-deploy-ve-teshis.md) §5 | Deploy kapısı iyileştirmesi | 04 |
| "Neden sağlıksız?" açıklayıcısı | [05](05-agent-saglik-ve-izleme.md) §5 | Verdict sunumu | 05 |
| Sürüm histogramı kartı (Önceki B3 Kısmen) | [05](05-agent-saglik-ve-izleme.md) §5 | S4-O03'ün kartı | 05 |
| Grace'li secret rotasyonu (Önceki B4) | 15 | Düzeltme (S4-O05/O06) | 05 |
| Değişiklik önizlemesi (GitHub compare) | [06](06-temalar-ve-rollout.md) §5 | M07 v2 | 06 |
| Domain taşıma sihirbazı | [07](07-domain-cloudflare-dns.md) §5 | S6-O17'nin UI'ı | 07 |
| DNS planı önizleme (diff) | [07](07-domain-cloudflare-dns.md) §5 | S6-O01 ile birlikte | 07 |
| Mailbox isteğinden oluşturma | [08](08-mail-ve-bildirimler.md) §5 | Karar gerekiyor (08 §7) | 08 |
| Yoğunluk tercihi, satır aksiyonu kısayolu | [09](09-ui-ux-i18n-erisilebilirlik.md) §5 | UI ince ayarı | 09 |
| `ops:i18n-audit` komutu | [09](09-ui-ux-i18n-erisilebilirlik.md) §5 | Araç | 09 |
| Kısmi başarı sonuç nesnesi | [02](02-mimari-ve-kod-sagligi.md) §5 | M10'un önkoşulu | 02 |
| Tema sağlık skoru | [06](06-temalar-ve-rollout.md) §5 | M02 v2 | 06 |
| Host detay çekmecesi | [07](07-domain-cloudflare-dns.md) §5 | M03 verisinin görünümü; S8-B18 | 07/09 |
| Önceki F4 (kuyruk/cron sağlığı, Kısmen) | 15 | Health payload genişletmesi | §6 (M02/M20 tüketir) |
| Önceki F5 (modül envanteri) | 15 | Health payload alanı + liste filtresi | 05 + CMS handoff |
| Önceki F6 (CMS admin oturumlarını düşür) | 15 | Admin sekmesine bir aksiyon | 03 + CMS handoff |
| Önceki F7 (içerik istatistikleri) | 15 | M21 v2 girdisi | CMS handoff |

### 5.4 Kapsam dışı adaylar ve reddedilen fikirler

| ID / fikir | Karar | Gerekçe ve kanıt |
|------------|-------|------------------|
| S12-M22 Domain kayıt bitişi (RDAP, salt okuma) | Kapsam dışı — bilinçli karar gerekir | Registrar alanı "Sonraki" (`.cursor/prompts/referans/_Scope-Constraints.md:62`). [07](07-domain-cloudflare-dns.md) §5 de aynı sonuca varıyor. Yeni bir dış çağrı gerektiriyor. Karar çıkarsa M03'e eklenebilir (S, risk 2) |
| S12-M23 Müşteriye açık status sayfası (Önceki D3) | Kapsam dışı — bilinçli karar gerekir | Plane "müşteri paneli değil" (`_Scope-Constraints.md:17`), müşteri self-service v2+ (`:60`). Bir public yüzey Plane'in blast radius'unu artırır (`docs/security.md:29-31`). Operatörlere yönelik görünüm M02'de karşılanıyor |
| S12-M24 Site bazlı viewer görünürlüğü (Önceki C6) | Kapsam dışı — bilinçli karar gerekir | Multi-tenant sınırına yakın (`_Scope-Constraints.md:17,19`), 15 de "dikkat" diyor. Ancak M11 ve M13 sonrasında ayrı bir kararla ele alınabilir |
| Multi-tenant SaaS | Reddedildi | `_Scope-Constraints.md:17,19` (HARD) |
| Plugin/tema marketplace, Modül Mağazası | Reddedildi | `_Scope-Constraints.md:61-62` |
| Müşteri self-servis paneli | Reddedildi | `_Scope-Constraints.md:60`; ADR-5 (`:31`) |
| Faturalama, SLA kredisi, maliyet (para) hesabı | Reddedildi | `_Scope-Constraints.md:62`. M02 ve M08 bu nedenle para içermiyor |
| Mailcow API ve mailbox provision | Reddedildi | ADR-8/9 (`_Scope-Constraints.md:34-35,64`); `docs/related-infra.md:9,16` |
| Tema ZIP upload | Reddedildi | ADR-4 (`_Scope-Constraints.md:30,59`) |
| DNS registrar API (yazma) | Reddedildi | `_Scope-Constraints.md:62` |
| Coolify SSH/exec ile bakım veya playbook | Reddedildi | ADR-6 (`_Scope-Constraints.md:32,66`). M10 ve M15 yalnız API ve agent kullanır |
| Paylaşımlı MySQL/Redis ile ucuz staging | Reddedildi | `_Scope-Constraints.md:58`. M05 site başına ayrı DB kullanır |
| Horizon / Pulse / Sentry | Reddedildi | Önceki rapor §5 (`docs/plans/2026-09-14-plane-review-and-proposals.md:218`). Yerine M20 |
| Playwright / Dusk / jsdom E2E | Reddedildi | ADR-11 (`docs/decisions/README.md:15`) |
| Plane'in kendi Coolify uygulamasını Plane'den deploy etmek | Reddedildi | Önceki rapor §5 (`…proposals.md:217`) |
| Genel secret kasası (bütün müşteri env değerlerini Plane'de tutmak) | Reddedildi (v1) | Plane ele geçirilirse etki bütün filoya yayılır (`docs/security.md:29-31`). M16 yalnız anahtar kümesini ve tombstone'u tutar, değerleri 30 gün şifreli saklar |
| Cloudflare proxied + SSL Full Strict yönetimi | Kapsam dışı (karar) | [07](07-domain-cloudflare-dns.md) §5; spec v1'de `proxied` kapalı |
| Creative-render / APK builder otomasyonu | Reddedildi | `_Scope-Constraints.md:63` |

### 5.5 İlk 5 — neden şimdi

| Sıra | Aday | Puan | Neden şimdi | Önerilen spec |
|------|------|------|-------------|---------------|
| 1 | S12-M01 Uyarı ve bildirim merkezi | 10 | Down ve deploy_failed alarmları bugün hem gürültülü (S4-B01) hem kaybolabiliyor (S7-B04: gönderim hatası yutuluyor, durum yine de ilerliyor). Sonraki bütün L4 otomasyonların bildirim kanalı bu modül. Önceki D1/D2 10 gündür Yapılmadı | `docs/superpowers/specs/2026-10-XX-ops-alert-center-design.md` |
| 2 | S12-M16 Env kataloğu değişiklik kapısı | 10 | Tek Kritik veri kaybı yolu (S3-B01), her deploy'da ve saatlik katalog senkronunda (`routes/console.php:23-26`) tetiklenebiliyor. Önce korkuluk, sonra ekran | S3-O01 spec'ini genişlet: `docs/superpowers/specs/2026-10-XX-env-catalog-change-gate-design.md` |
| 3 | S12-M19 Otomasyon kontrol paneli | 9 | Üç L4 akış (auto-fix, core tema restart, auto-rebind) canlıda açık. Kapatmak redeploy istiyor (S8-B04). M07 ve M09 bu panele bağlı | `docs/superpowers/specs/2026-10-XX-automation-control-panel-design.md` (S12-O02: M20 ile tek spec) |
| 4 | S12-M20 Plane öz-sağlık ve entegrasyon paneli | 9 | 18 job'un hiçbirinde `failed()` yok (S1-B09). Scheduler ölürse bütün filo "stale" görünüyor ama uyarı çıkmıyor (S4-B22). Yeni zamanlanmış işler (M03, M17, M21) heartbeat olmadan kör çalışır | Aynı spec: `docs/superpowers/specs/2026-10-XX-plane-system-health-design.md` |
| 5 | S12-M03 TLS / DNS gözetimi | 9 | Salt okunur ve düşük riskli. Önceki B8 iki raporda da açık. S4-B08 nedeniyle TLS hataları bugün "timeout" olarak görünüyor | `docs/superpowers/specs/2026-10-XX-tls-dns-watch-design.md` (S6-O14 ile tek spec) |
| (6) | S12-M13 Plane hesapları + 2FA | 9 | Eşitlik kuralında 6. sırada (L, risk 2). 15'te 1. sırada ve güvenliğin önkoşulu. §7-S1'e bakın | `docs/superpowers/specs/2026-10-XX-plane-accounts-2fa-design.md` |

## 6. CMS handoff

| Aday | CMS'ten gereken | Plane tarafı bağımlılığı |
|------|-----------------|--------------------------|
| S12-M04 | `POST /internal/control/v1/backup` (DB dump + storage arşivi, volume'da kalır) ve `GET …/backups` (meta). İmza mevcut HMAC ile | Kanal düşürme ve purge kapısı |
| S12-M15 | `POST …/maintenance/{action}` allowlist'i (cache clear, config cache, queue restart, storage link, migrate status salt okunur) ve `GET …/logs?lines=200` (redakte edilmiş) | Bakım sekmesi |
| S12-M17 | Health veya `GET /themes` yanıtında kurulu tema `sha` alanı, çalışan commit SHA'sı ([06](06-temalar-ve-rollout.md) §6, [04](04-coolify-deploy-ve-teshis.md) §6) | Tema SHA drift ve "doğru sürüm ayakta" kontrolü |
| S12-M16 | `.env.production.example`'dan anahtar kaldırma bir göç adımı olarak yapılmalı; isteğe bağlı `#@deprecated` yönergesi ([04](04-coolify-deploy-ve-teshis.md) §6) | Fark ekranında "deprecated" etiketi |
| S12-M02, S12-M20 | Health payload'ında kuyruk derinliği, başarısız job sayısı, son schedule zamanı ve `contract_version` (F4, S4-O21) | Site düzeyinde "CMS worker'ı ölü" sinyali |
| S12-M05 | DB kopyası için M04 uçları. Klonun yayında olmaması için boş CMS'in `draft` yayın durumuyla açıldığının teyidi | Klonun kazara indekslenmemesi |
| S12-M06 | Sürüm notu ya da uyumluluk bayrağı (ör. `requires_env[]`) (Doğrulanmadı: bugün yok) | Terfi aday listesi |
| Önceki F5 / F6 / F7 | Health payload'ında `modules[]` ve `stats{}`; `POST …/admins/{id}/logout` | Modül filtresi, admin oturum kapatma, M21 v2 içerik sayıları |

## 7. Açık sorular

| # | Soru | Önerilen default |
|---|------|------------------|
| S1 | M13 (hesaplar + 2FA), M01'in önüne alınsın mı? Puanlar eşit (9 ve 10). Güvenlik raporu (10/11, henüz yok) Kritik bulgu çıkarırsa sıra değişir | M01 ile paralel. M13 kişisel tercihlerin önkoşulu, M01 MVP'si rol tabanlı alıcıyla başlasın |
| S2 | M01 webhook kanalı Slack mi, Telegram mı, generic mi? | Generic, imzalı JSON (Slack Incoming Webhook biçimi uyumlu). Telegram v2 |
| S3 | M04: Coolify'ın zamanlanmış DB yedeği mi, CMS agent mı? | Önce Coolify API spike'ı (Doğrulanmadı). Uygun değilse CMS agent. Main'den düşürme kapısı iki durumda da Plane'de |
| S4 | M11: sözleşme bitiş tarihi faturalamaya kayar mı? | Yalnız tarih ve not. Tutar ya da para birimi alanı yok |
| S5 | M05 klon limiti ve TTL | En fazla 3 eşzamanlı klon, 72 saat TTL, yalnız OP ve SA |
| S6 | M17'de hizalama (L3) aynı spec'te mi olsun? | Hayır. MVP yalnız tespit, hizalama 2 hafta gözlemden sonra |
| S7 | M22 RDAP kapsam dışı kalsın mı? | Kalsın. Talep gelirse M03'e salt okuma olarak eklensin (ADR güncellemesiyle) |
| S8 | M08 metrikleri için Coolify'da resources/metrics ucu var mı? | Doğrulanmadı. Spec öncesinde Coolify API dokümanı ve Http::fake'li spike. Yoksa MVP yalnız yerel sayımlarla |
