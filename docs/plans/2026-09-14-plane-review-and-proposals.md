# Deamon Plane — Durum incelemesi ve öneri raporu

**Tarih:** 2026-09-14
**Dal:** `alpha` (HEAD `242ae15`)
**Kapsam:** Yalnızca `deamon-plane`. CMS (`codron-co/deamon`) tarafına düşen işler ayrı başlıkta işaretlendi.
**Amaç:** Kod değişikliği yapmadan, mevcut yüzeyleri envanterlemek; boşlukları, otomasyon fırsatlarını ve yeni özellik adaylarını önceliklendirmek.

Bu rapor bir plan değil, karar girdisidir. Seçilen maddeler `docs/superpowers/specs/` altında spec + plan olarak açılmalıdır.

---

## 1. Mevcut durum (envanter)

Sayılar bu tarihteki koddan alındı.

| Ölçü | Değer |
|------|-------|
| Route | 184 |
| Ops controller | 29 |
| Servis sınıfı | ~90 (`app/Services/*`) |
| Kuyruk job'u | 13 |
| Artisan komutu | 4 (`ops:import-coolify-apps`, `ops:sync-env-catalog`, `ops:sync-theme-catalog`, `ops:heal-throttled-deploys`) |
| Zamanlanmış görev | **1** commitli (`DispatchSiteHealthChecksJob`, 5–15 dk). Çalışma ağacında commitsiz ikinci giriş var: `ops:sync-env-catalog` saatlik (başka oturum, 2026-09-14 ~04:50) |
| Audit action çeşidi | ~138 |
| PHPUnit | 856 test, 4658 assertion, yeşil |
| Node test | `tests/js/ops-contracts.test.js` (jsdom yok, ADR-11) |

### 1.1 Var olan yüzeyler

| Alan | Var olan |
|------|----------|
| **Fleet** | KPI kartları (toplam, kanal, unhealthy, başarısız deploy penceresi, deploying, agent secret), dikkat kartları, kısayollar, Ctrl+K palet |
| **Sites** | CRUD, provision, kanal geçişi (sürüm kapısı + onay), deploy / redeploy / pin / follow HEAD / auto-deploy, compose pack migrasyonu, live probe, app-health teşhis + tek tık fix, agent secret inject, yayın durumu, kaydedilmiş görünümler, kolon tercihleri, toplu işlemler (throttle-dirençli fan-out) |
| **Site detay** | Overview, domains, Cloudflare zone/DNS, deployments, temalar, adminler (generate / manual / **invite**), mail (Hostinger + yazılım maili), audit |
| **Coolify** | N bağlantı, envanter senkron (server / project / env / git source allowlist), deploy kuyruğu görünürlüğü, rate guard + retry + cooldown, webhook (HMAC veya `?token=`), env defaults kataloğu (Settings) |
| **Cloudflare** | N hesap, zone oluşturma, DNS şablonu, preview wildcard, DNS onayı sonrası domain rebind |
| **Domains** | Kayıt defteri, toplu bind / clear |
| **Themes** | GitHub App Manifest + N installation, katalog senkron, site'e git ile kurulum / güncelleme / activate / sync, push webhook → opt-in auto update, `minimum_deamon_version` kapısı |
| **Mail** | Hostinger mail sunucuları, site bağlama, CMS ters proxy (mailbox CRUD, mailbox istekleri), yazılım SMTP + bildirim kataloğu + per-site override, test maili, unsubscribe |
| **Activity** | Job + deploy + audit birleşik liste, CSV export, detay sayfaları |
| **Güvenlik** | Fortify login (yalnız login), spatie roller (`super_admin` / `operator` / `viewer`), `OPS_IP_ALLOWLIST`, HMAC agent, encrypted secret kolonları, `SecretRedactor`, `ProductionDebugGuard`, confirm matrisi |

### 1.2 Otomasyon durumu

Aşağıdaki işler **yalnızca operatör tetiklemesiyle** çalışır. Zamanlayıcıda tek giriş sağlık taraması.

| İş | Bugün nasıl tetikleniyor |
|----|--------------------------|
| Coolify envanter senkronu | `/coolify/{connection}` → Sync |
| Tema katalog senkronu | Themes → Sync veya `ops:sync-theme-catalog` |
| Live HTTP probe (favicon + status) | Sites → Live sync |
| Coolify deployment reconcile | Site detay / `/jobs` açıkken yeniden poll |
| Env catalog senkronu | `ops:sync-env-catalog` — **devam eden iş:** CMS `.env.production.example` kanal başına, GitHub push webhook + saatlik schedule (commitsiz) |
| Throttled deploy iyileştirme | `ops:heal-throttled-deploys` |
| App-health teşhis | Sağlık taraması ile birlikte (`InspectSiteAppHealthJob`) — bu otomatik |
| Platform mail push | Kaydetme veya Push butonu |
| Domain bind sweep | Domains → toplu bind |

### 1.3 Bilinen açık kalanlar (ledger / morning report)

- Coolify Notifications webhook imzasız (`?token=` kabul ediliyor); poll yedek.
- Çalışma ağacında bu rapordan bağımsız, commitsiz bir dilim var (env catalog per-channel: `app/Services/Coolify/EnvCatalog/*`, `2026_09_15_000001` migration, `routes/console.php`). Rapor commitli HEAD'i esas alır.
- Import sonrası agent secret enjeksiyonu manuel (toplu buton var, otomatik değil).
- CMS runtime imajında `git` yok → tema kurulumu canlıda ölü (CMS repo işi).
- Plane'in kendi canlı Coolify deploy'u tarihsel olarak atlanmış.
- Plane kullanıcıları için şifre sıfırlama / 2FA / kullanıcı yönetimi UI yok (Fortify `features` boş; `OpsAdminSeeder` tek giriş).

---

## 2. Tespitler

### 2.1 Güçlü yönler

1. **Sınır disiplini.** Plane, CMS ve tema authoring'e sızmıyor; agent kontratı tek dosyada kilitli, sürüm eşikleri yazılı.
2. **Secret hijyeni.** Encrypted kolonlar, `hidden`, redaktör, echo kontrolü (`adminPayloadEchoesPassword`), audit'te şifre yok. Test paketi bunu doğruluyor.
3. **Coolify'a karşı dayanıklılık.** Rate guard, cooldown, retry, bulk requeue, deploy gate ve kuyruk önbelleği bir arada. 429 sorunu üretimde kapatılmış.
4. **Test kültürü.** 856 test, hepsi `Http::fake`. Canlıya dokunmayan test politikası dokümante.
5. **Operatör ergonomisi.** Palet, kısayollar, kayıtlı görünümler, freshness rozetleri, empty-state'ler, confirm matrisi tek primitive.

### 2.2 Boşluklar (kanıtla)

| # | Boşluk | Kanıt | Etki |
|---|--------|-------|------|
| B1 | Zamanlayıcı neredeyse boş | `routes/console.php` tek job | Envanter, katalog, live probe, deploy reconcile eskiyor; "stale" rozetleri operatör tıklamadan kaybolmuyor |
| B2 | Plane hesap yönetimi yok | Fortify `features => []`, `users` route yok, seeder tek admin | Yeni operatör eklemek = SSH + tinker; şifre unutan operatör kilitleniyor; ayrılan kişi devre dışı bırakılamıyor |
| B3 | 2FA yok | Fortify 2FA kapalı | Plane ele geçirilirse tüm fleet (security.md "blast radius") — tek şifre faktörü ile korunuyor |
| B4 | Veri tutma politikası yok | `Prunable` yok, `ops_background_jobs` / `deployments` / `audit_logs` büyüyor | Aylar içinde Activity sorguları ve CSV export yavaşlar |
| B5 | Bildirim kanalı yalnız e-posta | `SiteHealthMailNotifier` tek kanal | Down / deploy failed uyarıları gece kutuda kalıyor |
| B6 | Coolify webhook imzasız modu | security.md "Residual" | Token sızarsa sahte deploy durumu yazılabilir (yalnız durum, mutasyon yok — düşük risk ama açık) |
| B7 | Sürüm dağılımı görünmüyor | `deamon_version` sitede saklı, fleet'te yalnız KPI yok | "Hangi siteler 1.2.18 altında?" sorusu liste filtresi değil |
| B8 | Yedek yok / hatırlatma yalnız kopya | channel-switch runbook "Plane snapshot almaz" | Kanal düşürme öncesi veri kaybı riski operatör disiplinine bağlı |
| B9 | Agent secret rotasyonu yok | `SiteAgentSecretInjector` yalnız ilk enjeksiyon | Sızan secret için tek yol elle Coolify env + Plane kolon |
| B10 | Sertifika / DNS doğrulama yok | Cloudflare zone durumu var, TLS son kullanma yok | Süresi dolan sertifika ilk müşteriden duyulur |
| B11 | Canlı CMS için "artisan" düzeyinde araç yok | Agent kontratı: health, themes, mail, admins, site/status | Cache temizle, migrate durumu, kuyruk uzunluğu, log kuyruğu için Coolify UI'a gidiliyor |

---

## 3. Öneriler

Efor: **S** (< ~1 gün), **M** (odaklı dilim), **L** (spec + birden fazla dilim).
Bağımlılık sütunu "CMS" diyorsa `codron-co/deamon` tarafında agent yüzeyi gerekir.

### A. Otomasyon (zamanlayıcı + tetikleyici)

| # | Öneri | Neden | Nasıl (kısa) | Efor | Bağımlılık |
|---|-------|-------|--------------|------|------------|
| A1 | **Gece bakım zamanlayıcısı** | B1 | `routes/console.php`: Coolify envanter senkronu (saatlik), tema katalog senkronu (saatlik), live probe (30 dk), `ops:heal-throttled-deploys` (15 dk). Env catalog senkronu zaten devam eden işte saatlik ekleniyor; aynı desen kullanılsın. Hepsi `withoutOverlapping` + `onOneServer`; rate guard zaten var. `OpsBackgroundJob` satırı açsın ki Activity'de görünsün | S | Devam eden env-catalog dilimi commitlensin |
| A2 | **Deployment reconcile** | Webhook kaçırılınca satır `in_progress` kalıyor | 10 dk'da bir "20 dk'dan eski açık deployment" için `PollDeploymentJob` yeniden kuyrukla; `/jobs` sayfasındaki mantığı scheduler'a taşı | S | — |
| A3 | **Import sonrası otomatik agent secret** | Ledger "manuel" | Import `--apply` sonrasında secret üret + Coolify env'e yaz + redeploy'u **opsiyonel** `--inject-secret` bayrağıyla yap; UI'da import edilen siteler için "eksik secret" dikkat kartı zaten var | M | — |
| A4 | **Provision sonrası ilk admin daveti** | Bugün provision bitince adminler sekmesine gidilip davet basılıyor | Provision başarı adımına "seed admin'e invite gönder" opsiyonu (site formunda müşteri e-postası alanı); CMS 1.2.18 `password-invite` zaten var | S | CMS ≥ 1.2.18 canlı |
| A5 | **Tema auto-update kademeli yayılım** | Push webhook tüm opt-in sitelere aynı anda | Önce `alpha` kanal siteleri, X saat sağlık temizse `beta`, sonra `main`; başarısızlıkta dur. `ThemeRolloutService` üstüne "rollout planı" satırı | L | — |
| A6 | **Kanal terfi otomasyonu (release train)** | Bulk channel var ama elle | "Bu beta sürümü N gün sorunsuz → main adaylarını listele" görünümü + tek tık bulk pin/channel; sürüm eşiği `DEAMON_MAIN_MINIMUM_VERSION` zaten var | M | — |
| A7 | **Self-healing kuralları** | App-health fix tek tık | Belirli düşük riskli issue kodları (`wrong_env` statik değerler, `domain_unbound` DNS aktifse) için "otomatik düzelt" bayrağı; her düzeltme audit + opt-in | M | — |
| A8 | **Veri tutma (prune)** | B4 | `Prunable`: `ops_background_jobs` 30 gün, `deployments` 180 gün (site başına son 25 kalsın), `audit_logs` 365 gün arşiv (CSV'ye at, sonra sil). Config'de süreler | S | — |
| A9 | **Weekly ops digest** | Fleet KPI anlık | Pazartesi 08:00 mail: yeni siteler, başarısız deploy'lar, unhealthy geçişleri, sürüm dağılımı, açık mailbox istekleri. `PlatformOpsMailer` + bildirim kataloğuna `ops_weekly_digest` | M | — |

### B. Yeni özellikler (Plane içi)

| # | Öneri | Neden | Nasıl (kısa) | Efor | Bağımlılık |
|---|-------|-------|--------------|------|------------|
| B1 | **Plane kullanıcı yönetimi** | B2 | `/users`: liste, davet (e-posta ile şifre belirleme linki — bugün CMS'e yaptığımızın aynısı), rol değiştir, devre dışı bırak, oturumları düşür. Fortify `resetPasswords` aç. Super Admin only. Audit | M | — |
| B2 | **2FA (TOTP) + zorunluluk** | B3 | Fortify `twoFactorAuthentication`; Super Admin için zorunlu, diğerleri için ayar. Recovery kod indirme yok (kopyala) | M | B1 |
| B3 | **Sürüm dağılımı görünümü** | B7 | Sites listesine `version` filtresi (`<`, `>=`, `=`), Fleet'e "sürüm histogramı" kartı, "en son main sürümü"nün altındakiler dikkat kartı. Veri `last_health_payload.deamon_version`'da var; ADR-10 gibi verdict kolonu (`deamon_version` düz kolon) | S | — |
| B4 | **Agent secret rotasyonu** | B9 | Site detay → "Secret'ı döndür": yeni secret üret, Coolify env yaz, redeploy, eski secret'ı 1 saat "grace" olarak kabul et (Plane iki secret ile imzalamayı dener), sağlık OK olunca eskiyi sil. Toplu versiyonu ayrı dilim | M | — |
| B5 | **Müşteri kartı** | Sites'ta müşteri kimliği yok (`notes` serbest metin) | `customers` tablosu: ad, iletişim e-postası, telefon, sözleşme bitişi, etiketler; site → customer. Invite maili için varsayılan adres buradan gelir. Fatura yok (kapsam dışı) | M | — |
| B6 | **Bakım modu** | Activate / Deactivate (Coolify start/stop) var; ama `DispatchSiteHealthChecksJob` tüm siteleri tarıyor, `stopped` site down sayılıp mail üretebiliyor | `stopped` ve `archived` siteleri sağlık / app-health taramasından ve down bildiriminden hariç tut; "planlı bakım" bayrağı (başlangıç–bitiş) ile aktif siteyi de geçici sustur; Fleet unhealthy KPI'sı bunları saymasın | S | — |
| B7 | **Staging klon (preview)** | Kanal geçişi canlı sitede yapılıyor | Aynı repo, `beta` dalı, preview wildcard host (`{adj}-{noun}.codron.co` altyapısı var), ayrı MySQL/Redis; süre sonunda otomatik purge. DB kopyası **yok** (v1), boş CMS | L | — |
| B8 | **TLS ve DNS gözetimi** | B10 | Live probe'a sertifika bitiş tarihi + issuer ekle (`stream_context` peer cert), 14 gün altı dikkat kartı; Cloudflare zone `pending` 7 günü aşarsa uyarı; NS kaydı beklenenle uyuşmuyorsa "DNS drift" | S | — |
| B9 | **Sunucu kapasite kartı** | Deploy gate `max_concurrent_per_server=1` ama sunucu yükü görünmüyor | Coolify `GET /servers/{uuid}/resources` + `/validate` sonuçlarını envanter senkronunda sakla; Coolify sayfasında sunucu başına site sayısı / disk / durum. Yeni site oluştururken "en az yüklü sunucu" önerisi | M | Coolify API alanları doğrulanmalı |
| B10 | **Incident / not defteri** | Audit makine kaydı; operatör bağlamı yok | Site ve fleet düzeyinde "not" satırı (kim, ne zaman, markdown'sız düz metin), Activity'de `note` türü, dikkat kartında son not. `sites.notes` alanının zaman damgalı hali | S | — |
| B11 | **Deploy penceresi / dondurma** | Bulk deploy her an tetiklenebilir | Settings: "dondurma penceresi" (ör. Cuma 18:00 – Pazartesi 08:00); pencere içinde deploy/kanal butonları Super Admin onayı ister; scheduler'daki otomatik deploy'lar ertelenir | S | — |
| B12 | **Runbook'ları UI'a bağla** | `docs/runbooks/*` yalnızca repo'da | Dikkat kartı / hata flash'ı ilgili runbook'a link versin (`docs/runbooks/agent-secret-inject.md` vb. GitHub blob URL'si); tek bir `RunbookLink` helper | S | — |
| B13 | **Salt-okunur Plane API + token** | Dış raporlama / Grafana / Telegram bot | `GET /api/v1/sites`, `/fleet/kpis`, `/deployments` — Sanctum yerine mevcut Fortify + kişisel token tablosu (encrypted), `viewer` yetkisi, IP allowlist ile aynı guard. Yazma yok | M | B1 |

### C. Güvenlik ve erişim

| # | Öneri | Neden | Nasıl (kısa) | Efor |
|---|-------|-------|--------------|------|
| C1 | **Coolify webhook `?token=` modunu kapatılabilir yap** | B6 | Settings: "yalnız HMAC" anahtarı; token modu açıkken güvenlik sayfasında uyarı chip'i | S |
| C2 | **Oturum politikası** | Ops paneli | `SESSION_LIFETIME` kısalt (4 saat), "diğer oturumları kapat", son giriş IP/UA listesi hesap sayfasında | S |
| C3 | **Audit'e "neden" alanı** | Tehlikeli işlemler (purge, kanal düşürme, admin sil) | Confirm modalında opsiyonel "neden" metni → `audit_logs.reason` | S |
| C4 | **Secret tarama testi genişlet** | Yeni yüzeyler eklendikçe | Tüm Blade çıktısı için "encrypted kolon değeri HTML'de yok" genel testi (bugün Settings/Coolify/GitHub'da tek tek) | S |
| C5 | **Rol matrisi dokümanı + test** | 3 rol, ~184 route | `SitePolicy` vb. üzerinden "route → rol" tablosunu üreten test; docs/security.md'ye tablo | S |
| C6 | **Yetki: site bazlı görünürlük (opsiyonel)** | Dış operatör / bayi senaryosu | `viewer` rolüne site listesi kısıtı (customer etiketi ile). Multi-tenant değil; yalnız filtre | M |

### D. Gözlemlenebilirlik ve bildirim

| # | Öneri | Neden | Nasıl (kısa) | Efor |
|---|-------|-------|--------------|------|
| D1 | **Telegram / Slack webhook kanalı** | B5 | `PlatformNotificationCatalog`'a kanal boyutu (`mail`, `telegram`, `slack`); tek `OutboundWebhookNotifier` (imzalı JSON POST); site down / up / deploy failed / secret missing için | M |
| D2 | **Alarm bastırma + eskalasyon** | Down maili her taramada değil, ama tekrar da etmiyor | "N tarama üst üste down" eşiği, "30 dk sonra hâlâ down" ikinci bildirim, "bakım modunda sessiz" | S |
| D3 | **Status sayfası (dahili)** | Müşteriye "site ayakta mı" | `/status/{slug}` imzalı URL, yalnız live probe + son deploy zamanı; Plane URL'si ifşa edilmeden ayrı host'ta yayınlanabilir | M |
| D4 | **Deploy süresi / başarı oranı metrikleri** | KPI'lar anlık | `deployments.started_at/finished_at`'tan p50/p95 süre, 30 günlük başarı oranı; Fleet kartı + Activity filtresi | S |
| D5 | **Sağlık geçmişi** | Yalnız son payload saklanıyor | `site_health_samples` (site, zaman, status, latency, version) 30 gün; site detayda sparkline (dataviz kuralları) | M |
| D6 | **Plane kendi sağlığı** | Plane'i kim izliyor? | `/up` var; kuyruk gecikmesi, son scheduler çalışması, Coolify cooldown durumu için `/internal/plane-health` (IP allowlist) + Coolify healthcheck'i buna bağla | S |

### E. Operasyon kalitesi ve teknik borç

| # | Öneri | Neden | Nasıl (kısa) | Efor |
|---|-------|-------|--------------|------|
| E1 | **Kuyruk işçisi görünürlüğü** | 13 job, Horizon yok | Horizon eklemek yerine `queue:monitor` + `failed_jobs` listesi Activity'de "başarısız job" sekmesi + retry butonu | S |
| E2 | **Contract testleri CMS ile paylaş** | Agent kontratı iki repoda elle senkron | CMS repo'daki OpenAPI/JSON örneklerini `tests/fixtures/agent/*.json` olarak Plane'e kopyalayan script; `AdminAgentResult` gibi parser'lar fixture'lardan test edilsin | M |
| E3 | **Ledger'ı bölmek** | `progress-ledger.md` uzun, tek dosya | Aylık arşiv (`progress-ledger-2026-09.md`), index'te son 10 giriş | S |
| E4 | **Pint `line_ending` gürültüsü** | Morning report'ta tekrar eden | `.gitattributes` `* text=auto eol=lf` + tek seferlik normalize commit | S |
| E5 | **`_tmp/` ve `tools/coolify-spike/`** | Repo kökünde geçici dizin | `_tmp` gitignore'a; spike'ı `docs/plans` altında referansla bırak, kodu arşiv dalına | S |
| E6 | **Uzun controller'lar** | `SiteController`, `SiteCoolifyOpsController` çok sorumluluklu | Yeni özellik eklerken action-class'a böl (mevcut `Services/Sites/*` deseni); refactor için ayrı dilim açma, fırsatçı yap | — |
| E7 | **E2E duman testi (opsiyonel)** | ADR-11 Playwright'ı reddetti | Karar korunur; ama staging Plane'e karşı **tek** bir "login → site listesi → detay" curl/HTTP testi CI'da koşabilir (`Http::fake` değil, gerçek staging) | S |

### F. CMS agent'a ihtiyaç duyanlar (cross-repo; önce CMS spec)

| # | Öneri | Plane tarafı | CMS tarafı |
|---|-------|--------------|------------|
| F1 | **Uzak bakım komutları** (B11) | Site detay "Bakım" sekmesi: cache clear, config cache, queue restart, storage link, migrate status (salt-okunur). Audit + confirm | `POST /internal/control/v1/maintenance/{action}` allowlist |
| F2 | **Log kuyruğu** | Son 200 satır `laravel.log` / kuyruk hataları; `SecretRedactor` ile | `GET …/logs?lines=200` (redakte) |
| F3 | **DB yedeği tetikle + indir** (B8) | Kanal düşürme öncesi zorunlu "yedek al" adımı; yedek Coolify volume'da kalır, Plane yalnız meta (boyut, zaman) saklar | `POST …/backup`, `GET …/backups` |
| F4 | **Kuyruk / cron sağlığı** | `queue_ok` var; kuyruk uzunluğu, son schedule çalışma zamanı, başarısız job sayısı | health payload genişletme |
| F5 | **Modül / eklenti envanteri** | Site detayda kurulu CMS modülleri ve sürümleri; fleet'te "X modülü olan siteler" filtresi | health payload `modules[]` |
| F6 | **Admin oturumlarını düşür** | Admin sekmesinde "oturumları kapat" (şifre sıfırlamadan) | `POST …/admins/{id}/logout` |
| F7 | **Site içerik istatistikleri** | Weekly digest'e sayfa / ürün / sipariş sayısı | health payload `stats{}` |

---

## 4. Öncelik önerisi

Etki × efor ve mevcut açıklar üzerinden ilk döngü.

### Dalga 1 (hemen, düşük risk)

1. **A1 Gece bakım zamanlayıcısı** — en büyük "boşluk/efor" oranı; tüm freshness rozetleri anlamlı hale gelir.
2. **A8 Prune politikası** — büyümeden önce.
3. **B1 Kullanıcı yönetimi + Fortify şifre sıfırlama** — operasyonel zorunluluk.
4. **B3 Sürüm dağılımı** — veri zaten var, filtre + kart.
5. **A2 Deployment reconcile** — `/jobs` mantığını scheduler'a taşımak.

### Dalga 2

6. **B2 2FA** (B1 sonrası).
7. **D1 Telegram/Slack** + **D2 bastırma**.
8. **B4 Agent secret rotasyonu**.
9. **A4 Provision sonrası davet** (CMS 1.2.18 canlıya çıkınca).
10. **B8 TLS/DNS gözetimi**.

### Dalga 3 (spec gerektirir)

- **A5 kademeli tema yayılımı**, **A6 release train**, **B7 staging klon**, **B13 salt-okunur API**, **F1–F3 CMS bakım/yedek yüzeyleri**.

---

## 5. Yapılmaması gerekenler (kapsam bekçisi)

Ana plan ve AGENTS.md ile tutarlı; bu rapor bunları önermiyor:

- Multi-tenant SaaS, marketplace, faturalama, müşteri self-service paneli (B5 müşteri kartı yalnız operatör kaydıdır).
- Tema ZIP yükleme, CMS modül geliştirme, CMS admin Blade işi.
- Mailcow API (ADR-8 "coming soon" kalır).
- Playwright / jsdom eklemek (ADR-11).
- Plane'in canlı Coolify uygulamasını Plane'den mutasyona uğratmak (deploy-plane runbook'u).
- Horizon / Pulse / Sentry gibi ağır bağımlılıklar; önce E1 ve D6 ile mevcut araçlar.

---

## 6. Sonraki adım

Seçilen maddeler için sıra: spec (`docs/superpowers/specs/YYYY-MM-DD-<konu>-design.md`) → plan → dilim. A1, A8, B3 tek spec altında "Fleet hijyeni" olarak toplanabilir; B1 + B2 "Plane hesapları" olarak ayrı spec.
