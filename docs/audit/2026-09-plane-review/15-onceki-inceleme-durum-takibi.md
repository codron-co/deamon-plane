# 15 — Önceki inceleme durum takibi

**Tarih:** 2026-09-24 · **HEAD:** 8484d37 · **Hazırlayan:** S0

Kaynak: [../../plans/2026-09-14-plane-review-and-proposals.md](../../plans/2026-09-14-plane-review-and-proposals.md) (HEAD `242ae15`). Aradaki 66 commit: [01-envanter.md §13](01-envanter.md#13-git-log-242ae15head).
Durum: **Yapıldı** / **Kısmen** / **Yapılmadı** / **Geçersiz** (rapor anındaki iddia yanlış veya madde artık anlamsız). Kanıt komutları: `grep -rn <anahtar> app routes config database resources tests`, `git log --oneline -S "<anahtar>"`.
Boş grep = ilgili anahtar kelimenin `app/ routes/ config/ database/migrations/ resources/views/ tests/` altında hiç geçmemesi.

## 1. Bilinen açık kalanlar (rapor §1.3)

| Önceki ID | Başlık | Durum | Kanıt | Not |
|-----------|--------|-------|-------|-----|
| 1.3-a | Coolify webhook imzasız (`?token=`) | Yapılmadı | `app/Http/Middleware/VerifyCoolifyWebhook.php:36-38`, `app/Support/CoolifyWebhookSignature.php:67,79` | Token modu hâlâ kabul ediliyor; kapatma anahtarı yok (Öneri-C1) |
| 1.3-b | Commitsiz env catalog dilimi | Yapıldı | `4095c20`; `routes/console.php:23-26`; `app/Services/Coolify/EnvCatalog/*` | — |
| 1.3-c | Import sonrası agent secret manuel | Yapılmadı | `CoolifyFleetImporter.php` içinde inject/secret yok; `SiteAgentSecretSweep` yalnız manuel toplu (`app/Services/Sites/SiteAgentSecretSweep.php` sınıf yorumu) | Öneri-A3 ile aynı |
| 1.3-d | CMS runtime'da `git` yok → tema kurulumu ölü | Doğrulanmadı (CMS handoff) | Ledger 2026-09-23 kaydı canlıda tema kurulumunu anlatıyor (`docs/plans/progress-ledger.md:7`) | CMS imajında kontrol edilmeli; Plane işi değil |
| 1.3-e | Plane'in kendi canlı deploy'u atlanmış | Doğrulanmadı | Ledger canlı webhook hedefi olarak Plane host'unu veriyor (`docs/plans/progress-ledger.md:28`) | Canlıya istek atılmadı |
| 1.3-f | Şifre sıfırlama / 2FA / kullanıcı yönetimi yok | Yapılmadı | `config/fortify.php:162-164` `features => []`; `users` route'u yok (`php artisan route:list`) | Boşluk-B2/B3 |

## 2. Boşluklar (rapor §2.2)

| Önceki ID | Başlık | Durum | Kanıt | Not |
|-----------|--------|-------|-------|-----|
| Boşluk-B1 | Zamanlayıcı neredeyse boş | Kısmen | `routes/console.php:16-42` 4 giriş (health, env catalog, deskron reconcile, fleet snapshot) | Envanter, tema katalog, live probe, heal-throttled, reconcile, prune hâlâ yok; `onOneServer` hiçbirinde yok |
| Boşluk-B2 | Plane hesap yönetimi yok | Yapılmadı | `config/fortify.php:162`; seeder tek admin `database/seeders/OpsAdminSeeder.php` | Kendi şifresini değiştirme var (`routes/web.php` `PUT /account/password`) — önceden de vardı |
| Boşluk-B3 | 2FA yok | Yapılmadı | `grep -rni twoFactor app config` → yalnız CMS admin göstergesi `app/Services/Agent/AdminAgentResult.php:114` | — |
| Boşluk-B4 | Veri tutma politikası yok | Yapılmadı | `grep -rn Prunable app` boş; `model:prune` schedule yok | `fleet_daily_snapshots` yeni büyüyen tablo eklendi |
| Boşluk-B5 | Bildirim yalnız e-posta | Yapılmadı | `app/Services/Mail/SiteHealthMailNotifier.php:16-40`; `grep -rni "telegram\|slack" app` boş | — |
| Boşluk-B6 | Coolify webhook imzasız modu | Yapılmadı | 1.3-a ile aynı | — |
| Boşluk-B7 | Sürüm dağılımı görünmüyor | Kısmen | `8fbf4b9`; `app/Models/Site.php:845` `CMS_FILTERS = ['outdated','unknown']`, `:1089-1105` kanal bazlı karşılaştırma | Liste filtresi var; histogram kartı, `<`/`>=` operatörü, düz kolon yok |
| Boşluk-B8 | Yedek yok | Yapılmadı | `grep -rni backup app` boş | CMS agent yüzeyi gerekir (F3) |
| Boşluk-B9 | Agent secret rotasyonu yok | Geçersiz (kısmen) | Tek adımlı rotate rapordan önce vardı: `app/Http/Controllers/Ops/SiteController.php:677-687`, `SiteAgentSecretInjector.php:16` `$rotate`; ilk commit `6708350` (242ae15'in atası) | Grace süresi / çift secret / toplu rotate yok → Öneri-B4 açık |
| Boşluk-B10 | TLS / DNS doğrulama yok | Yapılmadı | `grep -rn "peer_certificate\|openssl_x509\|cert_expir" app` boş | — |
| Boşluk-B11 | Canlı CMS için bakım aracı yok | Kısmen | Coolify üzerinden app restart `app/Services/Sites/SiteAppHealthFixer.php:302-310`; container log okuma `app/Services/Coolify/CoolifyClient.php:305` (teşhis içinde) | Cache clear / migrate status / kuyruk uzunluğu yok (F1/F4) |

## 3. Öneriler (rapor §3)

### A. Otomasyon

| Önceki ID | Başlık | Durum | Kanıt | Not |
|-----------|--------|-------|-------|-----|
| A1 | Gece bakım zamanlayıcısı | Kısmen | `routes/console.php:16-42` | Coolify envanter, tema katalog, live probe, heal-throttled eklenmedi; `onOneServer` yok; zamanlanmış işler `OpsBackgroundJob` satırı açmıyor (Doğrulanmadı) |
| A2 | Deployment reconcile | Yapılmadı | Schedule'da yok (`routes/console.php`) | `6983901`, `e6a00c7` satır iyileştirmesi yalnız poll/okuma anında |
| A3 | Import sonrası otomatik agent secret | Yapılmadı | `--inject-secret` bayrağı yok (`ImportCoolifyAppsCommand.php:14-16`) | Env sync mevcut secret'ı yazıyor, üretmiyor (`CoolifyAppEnvSync.php:295`) |
| A4 | Provision sonrası ilk admin daveti | Yapılmadı | `grep -rni "customer_email\|seed_admin" app` boş | Manuel davet var (`site.admin.password_invite_sent`) |
| A5 | Tema auto-update kademeli yayılım | Yapılmadı | `grep -rni "rollout_plan\|staged" app` boş | Smoke + otomatik geri alma eklendi (`baa9010`) — risk azaltıcı ama kademeli değil |
| A6 | Kanal terfi otomasyonu | Yapılmadı | `grep -rni "release.?train" app` boş | CMS `outdated` filtresi (`8fbf4b9`) aday listesi için kısmi girdi |
| A7 | Self-healing kuralları | Kısmen | `d804395`: `DeploymentFailureClassifier.php:23` `AUTO_SAFE`, `DeploymentDiagnoser.php:116-151`, `config/ops.php:230`; `CoreThemeHealer.php:23-33` otomatik restart | App-health issue kodları için opt-in otomatik düzeltme yok (`SiteAppHealthFixer` manuel/toplu) |
| A8 | Veri tutma (prune) | Yapılmadı | Boşluk-B4 | — |
| A9 | Weekly ops digest | Yapılmadı | `PlatformNotificationCatalog.php:14` yalnız müşteri `weekly_visitor_report`; `ops_weekly_digest` yok | — |

### B. Yeni özellikler

| Önceki ID | Başlık | Durum | Kanıt | Not |
|-----------|--------|-------|-------|-----|
| Öneri-B1 | Plane kullanıcı yönetimi | Yapılmadı | `config/fortify.php:162`; `/users` route yok | — |
| Öneri-B2 | 2FA (TOTP) | Yapılmadı | Boşluk-B3 | B1'e bağlı |
| Öneri-B3 | Sürüm dağılımı görünümü | Kısmen | Boşluk-B7 | Fleet kartı / dikkat kartı yok |
| Öneri-B4 | Agent secret rotasyonu (grace) | Yapılmadı | `app/Support/ControlPlaneAgentSignature.php:15-26` tek secret ile imza | Tek adımlı rotate var (Boşluk-B9) |
| Öneri-B5 | Müşteri kartı (`customers`) | Yapılmadı | `grep -rn "customers\|customer_id" app database` boş | — |
| Öneri-B6 | Bakım modu / stopped siteleri hariç tut | Kısmen | App-health stopped'ı down saymıyor `SiteAppHealthInspector.php:245`; ama `DispatchSiteHealthChecksJob.php:19-27` tüm siteleri tarıyor, `SiteHealthMailNotifier.php:18-40` status bakmıyor | Planlı bakım bayrağı yok |
| Öneri-B7 | Staging klon | Yapılmadı | İlgili kod yok | Spec gerektirir |
| Öneri-B8 | TLS ve DNS gözetimi | Yapılmadı | Boşluk-B10 | — |
| Öneri-B9 | Sunucu kapasite kartı | Yapılmadı | `CoolifyClient` yalnız `listServers`; `/resources`, `/validate` çağrısı yok | — |
| Öneri-B10 | Incident / not defteri | Yapılmadı | Yalnız serbest `sites.notes` (`resources/views/ops/sites/_form.blade.php:387`) | — |
| Öneri-B11 | Deploy penceresi / dondurma | Yapılmadı | `grep -rni "freeze" app config` boş | — |
| Öneri-B12 | Runbook'ları UI'a bağla | Kısmen | Deploy teşhisinde düz metin runbook referansı: `lang/tr/deploy_diagnosis.php:23`, `resources/views/ops/deployments/show.blade.php:180` | Tıklanabilir link / `RunbookLink` helper yok |
| Öneri-B13 | Salt-okunur Plane API | Yapılmadı | `api/v1` route yok (`route:list`) | B1'e bağlı |

### C. Güvenlik ve erişim

| Önceki ID | Başlık | Durum | Kanıt | Not |
|-----------|--------|-------|-------|-----|
| C1 | Webhook `?token=` modunu kapatılabilir yap | Yapılmadı | 1.3-a | — |
| C2 | Oturum politikası | Kısmen | Süre zaten 120 dk (`config/session.php:35`, `.env.example:36`) — öneriden (4 sa) kısa | "Diğer oturumları kapat" ve son giriş listesi yok (`grep -rn logoutOtherDevices app` boş) |
| C3 | Audit'e "neden" alanı | Yapılmadı | `audit_logs` migration'da `reason` yok; `grep -rn "'reason'" app` yalnız flash çevirisi | — |
| C4 | Genel secret tarama testi | Kısmen | Yüzey bazlı testler: `tests/Feature/Agent/SiteAgentHealthTest.php:259`, `tests/Feature/Mail/SiteMailProxyTest.php:62,139` | Tüm Blade'i tarayan genel test yok |
| C5 | Rol matrisi dokümanı + test | Yapılmadı | `tests/Feature/Ops/RoleGateTest.php` 242ae15'te de vardı, yalnız Settings için 3 test (`:22,32,42`) | Route→rol üreten test yok; route'larda `role:` middleware'i hiç yok ([01 §2.3](01-envanter.md#23-middleware)) |
| C6 | Site bazlı görünürlük | Yapılmadı | İlgili kod yok | B5'e bağlı; multi-tenant sınırına yakın — dikkat |

### D. Gözlemlenebilirlik ve bildirim

| Önceki ID | Başlık | Durum | Kanıt | Not |
|-----------|--------|-------|-------|-----|
| D1 | Telegram / Slack webhook kanalı | Yapılmadı | `PlatformNotificationCatalog.php` kanal boyutu yok; `config/logging.php:76` yalnız log kanalı | — |
| D2 | Alarm bastırma + eskalasyon | Yapılmadı | `SiteHealthMailNotifier.php:18-40` yalnız ok↔unhealthy kenar geçişi | N-ardışık eşik ve 30 dk tekrar yok |
| D3 | Dahili status sayfası | Yapılmadı | `/status/{slug}` route yok | — |
| D4 | Deploy süresi / başarı oranı | Yapılmadı | `grep -rn "p95\|success_rate" app` boş | Satır başına süre etiketi var (`tests/Unit/Models/DeploymentDurationLabelTest.php`) |
| D5 | Sağlık geçmişi | Yapılmadı | `site_health_samples` tablosu yok | Fleet düzeyi günlük snapshot var (`8dfec88`, `fleet_daily_snapshots`) — site bazlı değil |
| D6 | Plane kendi sağlığı | Yapılmadı | Yalnız `/up` (`bootstrap/app.php` `health: '/up'`) | — |

### E. Operasyon kalitesi ve teknik borç

| Önceki ID | Başlık | Durum | Kanıt | Not |
|-----------|--------|-------|-------|-----|
| E1 | Kuyruk işçisi görünürlüğü / failed_jobs UI | Yapılmadı | `failed_jobs` yalnız `config/queue.php:126` ve migration'da | Jobs widget'taki "failed" filtresi `OpsBackgroundJob` içindir, `failed_jobs` değil |
| E2 | Contract fixture'larını CMS ile paylaş | Yapılmadı | `tests/Fixtures/` yalnız `deamon/env-production.example` | — |
| E3 | Ledger'ı bölmek | Yapılmadı | `docs/plans/progress-ledger.md` 555 satır tek dosya | — |
| E4 | Pint `line_ending` gürültüsü | Geçersiz (kısmen) | `.gitattributes` `* text=auto eol=lf` ilk commit'ten beri var (`d09721e`) | Gürültü kaynağı çalışma ağacı: 79 dosya CRLF, index 0 (`git ls-files --eol`), `core.autocrlf=true` — yerel `git add --renormalize` / autocrlf ayarı |
| E5 | `_tmp/` ve `tools/coolify-spike/` | Kısmen | `_tmp/` rapordan önce de gitignore'da (`.gitignore:13-14`, `7d6d738`), izlenmiyor | `tools/coolify-spike/` 3 dosya hâlâ izleniyor |
| E6 | Uzun controller'lar | Yapılmadı (kötüleşti) | `SiteController` 1051 → 1355, `SiteCoolifyOpsController` 518 → 549 satır | Fırsatçı bölme uygulanmadı; `Site.php` 915 → 1250 |
| E7 | E2E duman testi | Yapılmadı | `.github/` yok; CI yok | ADR-11 kararı geçerli |

### F. CMS agent'a ihtiyaç duyanlar

Plane tarafı durumu; CMS işi "CMS handoff" (§5).

| Önceki ID | Başlık | Durum | Kanıt | Not |
|-----------|--------|-------|-------|-----|
| F1 | Uzak bakım komutları | Yapılmadı | `ControlPlaneAgentContract.php` bakım yolu yok | Coolify restart var (Boşluk-B11) |
| F2 | Log kuyruğu | Kısmen | Coolify container log'u teşhiste okunuyor: `CoolifyClient.php:305`, `config/ops.php` `diagnosis.log_lines` | Site detayda genel log görünümü ve CMS `laravel.log` yok |
| F3 | DB yedeği tetikle + indir | Yapılmadı | Boşluk-B8 | — |
| F4 | Kuyruk / cron sağlığı | Kısmen | `AgentHealthResult.php:43,76` `queue_ok` + yeni `core_theme_in_sync` (`baa9010`) | Kuyruk uzunluğu, son schedule, failed job sayısı yok |
| F5 | Modül envanteri | Yapılmadı | health payload'da `modules` alanı okunmuyor | — |
| F6 | Admin oturumlarını düşür | Yapılmadı | `SiteAgentClient` admin metotlarında logout yok | — |
| F7 | Site içerik istatistikleri | Yapılmadı | `stats` alanı okunmuyor | A9'a bağlı |

## 4. Özet

| Durum | Adet |
|-------|------|
| Yapıldı | 1 |
| Kısmen | 13 |
| Yapılmadı | 47 |
| Geçersiz (kısmen) | 2 |
| Doğrulanmadı | 2 |
| **Toplam** | **65** (1.3: 6, Boşluk: 11, A: 9, B: 13, C: 6, D: 6, E: 7, F: 7) |

Gözlem: 242ae15 sonrası 66 commit'in hemen hepsi raporda olmayan işlere gitti (Sites UI yenilemesi, tema koruma/smoke, deploy teşhisi, Deskron, domain/mail düzeltmeleri). Dalga 1 önceliklerinden (A1, A8, B1, B3, A2) yalnız A1 ve B3 kısmen ilerledi.

### Şimdi yapılmalı ama hâlâ açık — ilk 10

| # | Önceki ID | Neden şimdi | Efor (rapor) |
|---|-----------|-------------|--------------|
| 1 | Öneri-B1 + 1.3-f | Operatör ekleme/çıkarma/şifre sıfırlama hâlâ SSH + tinker; güvenliğin önkoşulu | M |
| 2 | Öneri-B2 | Tüm fleet tek şifre faktörüyle korunuyor | M |
| 3 | A8 / Boşluk-B4 | `audit_logs`, `deployments`, `ops_background_jobs` + yeni `fleet_daily_snapshots` sınırsız büyüyor | S |
| 4 | A1 (kalan kısım) + `onOneServer` | Envanter/katalog/live probe/heal-throttled hâlâ elle; yeni filtreler (stale health, outdated) bayat veriye dayanıyor | S |
| 5 | A2 | `in_progress` kalan satırlar sadece sayfa açıkken iyileşiyor | S |
| 6 | Öneri-B6 | Stopped siteler hâlâ taranıp down maili üretebilir | S |
| 7 | C1 / 1.3-a | Token modu açık; HMAC-only anahtarı ucuz | S |
| 8 | C5 | 203 route, route düzeyinde rol yok; yetki yalnız controller içi — matris testi regresyonu yakalar | S |
| 9 | D1 + D2 | Down/deploy failed uyarısı yalnız mail, eskalasyon yok | M |
| 10 | E1 | `failed_jobs` görünmüyor; 18 job'un çoğu `tries=1`, başarısızlık sessiz | S |

## 5. CMS handoff

| Önceki ID | CMS tarafı |
|-----------|------------|
| 1.3-d | CMS runtime imajında `git` varlığı teyidi |
| F1, F2, F3 | `/internal/control/v1/maintenance/*`, `logs`, `backup(s)` uç noktaları |
| F4, F5, F7 | health payload genişletme: kuyruk uzunluğu, son schedule, `modules[]`, `stats{}` |
| F6 | `POST …/admins/{id}/logout` |
| Öneri-B4 | Grace dönemi için CMS'in iki secret'ı kabul etmesi (Plane tek taraflı da yapabilir — tasarım kararı) |
