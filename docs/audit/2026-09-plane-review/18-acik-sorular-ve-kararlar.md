# 18 — Açık sorular ve kararlar

**Tarih:** 2026-09-24 · **HEAD:** 8484d37 · **Hazırlayan:** Orkestratör (Dalga 3)
**Kaynak:** 02–14 raporlarının §7 bölümleri (yaklaşık 80 soru). Birleştirildi; yalnız kullanıcının vermesi gereken kararlar tutuldu. Kod okumasıyla cevaplanabilen sorular ilgili rapora bırakıldı.

## Nasıl kullanılır

Her karar için bir **önerilen default** var. Aksi söylenmezse backlog bu default'la ilerler. "Engelliyor" sütunu, karar verilmeden başlayamayan backlog maddelerini gösterir.

## 1. Hafta 1'i etkileyen kararlar (önce bunlar)

| # | Karar | Seçenekler | Önerilen default | Etkisi | Engelliyor | Kaynak |
|---|-------|-----------|------------------|--------|------------|--------|
| K01 | Operator'a güven modeli: içeriden gelen tehdit kapsamda mı? | (a) Evet: filo sırları ve geri alınamaz işlemler Super Admin'de; (b) Hayır: bugünkü iki basamaklı model | **(a)** | 16 route SA'ya geçer (purge, bağlantı/zone silme, secret rotate, SMTP/DeskRon sırları). Operatörler günlük işlerde etkilenmez | P03, P08 | S9 §7, S2 §7-1, S6 §7-7 |
| K02 | Toplu kalıcı silmenin sınırı | (a) Sayı sınırı (10) + slug onayı; (b) Toplu purge tamamen kaldırılsın | **(a)** | `all=1` ile filo çapında silme imkânsızlaşır | P03 | S2 §7-1 |
| K03 | Env anahtar kaldırması otomatik mi, onaylı mı? | (a) Ekleme otomatik, kaldırma SA onaylı, secret/generated hiç; (b) Prune tamamen kapalı; (c) Bugünkü gibi otomatik | **(a)** | Kritik S3-B01 kapanır; operatör bekleyen kaldırmaları görür | P04 | S3 §7, S11 §7-6 |
| K04 | DNS şablonu mevcut zone'daki yabancı TXT/MX'e dokunabilir mi? | (a) Hayır, yalnız eksikse ekle; değişiklik diff önizlemeli açık "Uygula" ile; (b) Bugünkü gibi ez | **(a)** | Müşteri SPF/DMARC/MX kayıtları korunur | P06 | S6 §7-2 |
| K05 | Tema auto-update hangi ref'i izlesin? | (a) Yalnız default branch; (b) Kurulumun ref'i (branch başına `latest_sha`) | **(a)** v1 | Başka ref'e pinli kurulumlar auto-update almaz; UI bunu söyler | P02 | S5 §7-1 |
| K06 | CMS < 1.2.32 veya sürümü bilinmeyen sitede auto-update | (a) Atla + audit + UI uyarısı; (b) Operatör onayıyla üzerine yaz | **(a)** | Bu siteler CMS güncellenene kadar tema güncellemesini elle alır | P02 | S5 §7-5 |
| K07 | Plane DB yedeği nereye gider? | (a) Aynı host, ayrı volume (ilk adım); (b) Host dışı (yeni dış servis) | **(a)** hemen, (b) ayrı karar | (a) Konteyner/volume kaybına karşı korur, host kaybına karşı korumaz. Host düzeyinde VPS snapshot'ı olup olmadığı sunucu sahibine sorulmalı | P07 | S10 §7 |
| K08 | DB unique index (`coolify_app_uuid`) mı, uygulama kuralı mı? | (a) Önce kural (`withTrashed`), sonra canlıda ikiz yoksa unique index; (b) Doğrudan index | **(a)** | Migration canlıda ikiz kayıt varsa düşer; önce tespit sorgusu çalıştırılmalı | P05 | S2-O04 |

## 2. Şimdi fazını etkileyen kararlar

| # | Karar | Seçenekler | Önerilen default | Etkisi | Engelliyor | Kaynak |
|---|-------|-----------|------------------|--------|------------|--------|
| K09 | Kill switch kaynağı | (a) DB (SA + audit), env "taban": env `false` iken DB açamaz; (b) Yalnız env | **(a)** | Settings → Otomasyon bölümü; env bayrakları production'da `config:cache` yüzünden Plane yeniden başlamadan değişmiyor | P13 | S11 §7-1, S8 §7 |
| K10 | AUTO_SAFE kapsamı | (a) `bind_domains` çıkar; `redeploy` yalnız aynı commit + force'suz + gecikmeli; koşulsuz otomatikte kalanlar `restart_app`, `inject_secret(rotate=false)`, `sync_deployments`, `check_health`; koşullu otomatik: `sync_env` (yalnız ekleme modu), `redeploy` (aynı commit, force'suz, gecikmeli); (b) Bugünkü liste | **(a)** | HEAD'i izleyen sitede otomatik redeploy'un yeni commit çıkarması engellenir | P13 | S3 §7, S4 §7-9, S11 §1.5 |
| K11 | Otomasyon bütçesi ve varsayılan | (a) Açık; bağlantı başına saatte 5; aynı kod 15 dk'da ≥ 3 sitede görülürse filo çapında dur; (b) Varsayılan kapalı | **(a)** mevcut kurallar için; yeni L4 kuralları 14 gün gölge modla başlar | Mevcut otomasyonlar çalışmaya devam eder ama sınırlı | P13, P33 | S3 §7, S11 §7-3 |
| K12 | Stopped siteler taransın mı? | (a) Agent taraması yok; Coolify inspect günde 1; (b) Bugünkü gibi | **(a)** | Sahte down alarmı ve gereksiz yük kalkar; beklenmedik `running` günlük yakalanır | P11 | S2 §7-5, S4 §7-3 |
| K13 | N-ardışık alarm eşiği | 1 / 2 / 3 | **2** (10 dk poll ile ≈ 20 dk) | Alarm gecikmesi ~10 dk artar, flapping biter | P11 | S4 §7-2 |
| K14 | Down/up maili müşteri alıcısına da gitsin mi? | (a) Hayır, eşik ve bastırma gelene kadar yalnız ops; (b) Evet | **(a)** | Müşteri gürültüsü azalır; müşteri için ayrı açık seçim sonra | P11, P32 | S4 §7-1, S7 §7 |
| K15 | Başarısız kanal geçişinde Coolify dalı geri alınsın mı? | (a) Deploy hiç başlamadıysa otomatik geri al, başladıysa "ayrışmış" göster + tek tık hizala; (b) Hiç | **(a)** | Plane ↔ Coolify ayrışması biter | P14 | S2 §7-2, S3 §7 |
| K16 | Arşiv Coolify uygulamasını durdurmalı mı? | (a) Arşiv yalnız `stopped` sitede; `active` sitede "önce durdur" seçeneği; (b) Bugünkü gibi | **(a)** | Arşivli ama çalışan izlenmeyen uygulama kalmaz | P29 | S2 §7-3 |
| K17 | 2FA zorunluluğu | (a) SA ve Operator zorunlu, Viewer isteğe bağlı; (b) Yalnız SA; (c) Herkes | **(a)** | Tek parola faktörü kalkar; kurtarma kodu akışı gerekir | P21 | S9 §7, S12 §7-S1 |
| K18 | Hesaplar + 2FA (P21) bildirim merkezinin (P32) önüne mi? | (a) Paralel; P32 MVP'si rol tabanlı alıcıyla başlar; (b) P21 önce | **(a)** | İki spec aynı anda açılır | P21, P32 | S12 §7-S1 |
| K19 | Coolify webhook `?token=` modu | (a) Bağlantı başına `hmac_only` anahtarı; token modu kalır ama webhook yalnız "Coolify'dan yeniden oku" tetikler, token aylık döner ve loglarda maskelenir; (b) Token modunu kapat (imzalayan proxy gerekir) | **(a)** | Sahte webhook durum yazamaz | P28 | S3 §7, S9 §7 |
| K20 | Toplu bind ve `bind_domains` redeploy tetiklemeli mi? | (a) Evet; tempolu, force'suz, site başına tek deploy; (b) Hayır, yalnız PATCH | **(a)** | Toplu bind'de deploy yükü artar; "Bağlı" rozeti doğru olur | P31 | S6 §7-1 |
| K21 | Tekli redeploy/pin/follow da ops job kuyruğuna mı? | (a) Evet, kısa ops job; (b) Senkron kalsın | **(a)** | Web isteği süresi düşer, kilit tutarlı olur | P10, P52 | S3 §7 |

## 3. Sonra fazını etkileyen kararlar

| # | Karar | Seçenekler | Önerilen default | Etkisi | Engelliyor | Kaynak |
|---|-------|-----------|------------------|--------|------------|--------|
| K22 | İkinci bildirim kanalı | (a) İmzalı genel webhook (Slack/Telegram uyumlu), ilk hedef Telegram; (b) Yalnız Slack; (c) Yalnız mail | **(a)** | Gece alarmları mail kutusunda kalmaz | P32 | S7 §7, S12 §7-S2 |
| K23 | Eskalasyon eşikleri | — | 2 ardışık hata; 30 dk ve 2 sa hatırlatma; günde ≤ 3 | Alarm yorgunluğu sınırlanır | P32 | S7 §7 |
| K24 | Plane ops alarmı ile yazılım SMTP'si ayrılsın mı? | (a) Ayrı "ops alarmları açık" anahtarı + isteğe bağlı ayrı SMTP; (b) Aynı | **(a)** | S7-B07 blast radius'u kısmen düşer | P32, P55 | S7 §7 |
| K25 | DeskRon `master_key` ve SMTP parolasının her CMS'e dağıtılması | (a) Kısa vadede kabul + rotasyon runbook'u + site başına kimlik fizibilitesi; (b) Hemen site başına anahtar | **(a)** | Risk bilinçli kabul edilir ve belgelenir | P55 | S7 §7, S9 |
| K26 | Tema kanarya dalgaları | (a) Kanala göre alpha → beta → main, dalga 3 site, soak 30 dk, ilk smoke hatasında dur; (b) Yüzdeye göre | **(a)** | Kötü tema commit'i en fazla 3 alpha sitesini etkiler | P38 | S5 §7-2 |
| K27 | Otomatik restart `main`'de mesai dışında çalışsın mı? | (a) Evet (kesinti düzeltir); deploy sınıfı eylemler main'de sessiz saatte ertelenir, site down ise çalışır; (b) Hayır | **(a)** | Gece kesintileri kendiliğinden kapanır | P33 | S11 §7-4 |
| K28 | Watchdog uzak durumu değiştirebilir mi? | (a) Hayır: yalnız Coolify okuma + yerel satır düzeltme (L4); redeploy/restart L3'te kalır; (b) Evet | **(a)** | Watchdog güvenli ve geri alınabilir | P09 | S10 §7 |
| K29 | Proxied (turuncu bulut) ve SSL Full Strict | (a) v1 dışında kalsın, ayrı karar; (b) Plane yönetsin | **(a)** | Bot yükü değerlendirmesi ayrı yapılır | — | S6 §7-8, S12 §5.4 |
| K30 | Mobil hedef | (a) Fleet, Sites liste/detay ve Activity mobilde kullanılır; formlar yalnız kırılmadan okunur; (b) Masaüstü only | **(a)** | P56 kapsamı belirlenir | P56 | S8 §7 |
| K31 | PHP hedef sürümü | 8.3 / 8.4 | **8.4**, 2026-12-31 öncesi canlıda | 8.2 güvenlik desteği bitiyor | P40 | S1 §7, S9 |
| K32 | Yedek orkestrasyonu yolu (P59) | (a) Önce Coolify API spike'ı; uygunsa Plane içinden, değilse CMS agent; (b) Doğrudan CMS | **(a)** | CMS bağımlılığı gereksizse düşer | P59 | S12 §7-S3 |

## 4. Kapsam kararları (bilinçli karar gerekir)

| # | Konu | Önerilen default | Gerekçe |
|---|------|------------------|---------|
| K33 | Domain bitiş takibi (RDAP) | Kapsam dışı kalsın; talep gelirse P34'e salt okuma olarak eklensin (ADR güncellemesiyle) | Registrar verisi v1 kapsamında değil |
| K34 | Müşteriye açık status sayfası | Kapsam dışı | v1 = iç operasyon (`docs/architecture.md` sabit karar 4) |
| K35 | Site bazlı viewer görünürlüğü | Kapsam dışı | Multi-tenant eğilimi (`AGENTS.md` sert limit) |
| K36 | Müşteri kartında sözleşme bitişi | Yalnız tarih ve not; tutar/para birimi yok | Faturalama kapsam dışı |

## 5. Sunucu sahibine sorulacaklar (bilgi, karar değil)

Bunlar canlı sisteme bakmadan cevaplanamaz. Bu denetim canlıya istek atmadı.

| # | Soru | Neden |
|---|------|-------|
| B01 | Host düzeyinde VPS snapshot'ı var mı, sıklığı ne? | K07 ve felaket kurtarma RTO'ları ([11](11-operasyon-altyapi-gozlemlenebilirlik.md)) |
| B02 | Canlıda `REDIS_QUEUE_RETRY_AFTER` ve `LOG_LEVEL` override'ı var mı? (Yalnız anahtar adı; değer dokümana girmez) | P10 değişmezi |
| B03 | Plane Cloudflare proxy'si arkasında mı? | S9-B06: `ip()`, IP allowlist ve login throttle doğruluğu |
| B04 | Webhook `?token=` değeri proxy access log'larına düşüyor mu? | S9-B05 |
| B05 | Coolify compose deploy'unda eski ve yeni konteyner örtüşüyor mu, `stop_grace_period`'a uyuluyor mu? | P10 zarif kapanış |
| B06 | Canlı veritabanında aynı `coolify_app_uuid`'e bağlı birden fazla site var mı? | K08 migration güvenliği, S2-B04'ün canlı etkisi |
