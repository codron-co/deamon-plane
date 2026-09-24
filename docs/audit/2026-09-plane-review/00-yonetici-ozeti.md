# 00 — Yönetici özeti

**Tarih:** 2026-09-24 · **HEAD:** 8484d37 (`alpha`) · **Hazırlayan:** Orkestratör
**Yöntem:** 13 alt-ajan, 4 dalga, salt-okunur kod denetimi. Canlı sisteme istek atılmadı. Ayrıntı: [README](README.md).

## Tek paragraf

Plane işlevsel olarak zengin ve test kültürü güçlü: 203 route, 126 servis ve 1080 yeşil test var. Önceki incelemeden (2026-09-14) bu yana 66 commit geldi, ama o raporun 65 maddesinden yalnız 1'i tamamlandı, 13'ü kısmen yapıldı. Asıl risk yeni özelliklerde değil, **insan onayı olmadan çalışan mevcut otomasyonların korkuluksuz olmasında**. Bunlar bugün her Plane deploy'unda katalogda olmayan env'leri siliyor, auto-update açık kurulumlara her branch push'unu taşıyor ve DNS şablonu uygulanan zone'larda müşteri kayıtlarını eziyor. Önerilen yol: **ilk hafta 7 kök Kritik bulguyu kapatmak** (hepsi yalnız Plane'de yapılabilir, S/M boy), ardından watchdog → bildirim → otomasyon kapısı zinciriyle otonomiyi güvenli zemine oturtmak. Bu zincirden sonra L1,4 olan ortalama otonomi seviyesi 90 günde L3,5'e çıkabilir.

## Sayılar

| Ölçü | Değer |
|------|-------|
| Bulgu satırı | **279** (tekilleştirilmemiş; Dalga 2 satırlarının bir kısmı Dalga 1 bulgularını yeniden ifade eder): Kritik 9 (**7 kök neden**), Yüksek 77, Orta 132, Düşük 61. Sayım Dalga 1 önemleriyle yapıldı; S9'un yeniden puanlaması ([10 §0.2](10-guvenlik-yetki-denetim.md)) yansıtılmadı |
| Öneri | 258 kaynak satır → **68** birleşik backlog maddesi ([16](16-yol-haritasi-ve-backlog.md)) |
| Kanıt kontrolü | 1.235 `dosya:satır` referansı betikle aralık kontrolünden geçti; 15 kritik kanıt orkestratör tarafından elle açılıp doğrulandı |
| Önceki inceleme | 65 madde: 1 yapıldı · 13 kısmen · 47 yapılmadı · 2 geçersiz · 2 doğrulanmadı ([15](15-onceki-inceleme-durum-takibi.md)) |
| Otonomi | Bugün **L1,38** (guardrail'e göre düzeltilmiş L1,11) → 90 gün hedefi **L3,53** ([13](13-otonomi-yol-haritasi.md)) |

## En kritik 10 bulgu

| # | ID | Önem | Bulgu | Backlog |
|---|----|------|-------|---------|
| 1 | S3-B01 | Kritik | Her Plane deploy'u katalogda olmayan Coolify env'lerini siliyor. CMS örnek env dosyasından tek satır düşerse kanal çapında `DB_PASSWORD` ve agent secret gider; Plane'de kopyaları yok | P04 |
| 2 | S7-B01 | Kritik | Mail proxy'si mailbox sahipliğine bakmıyor: bir site başka sitenin posta kutusu parolasını değiştirebilir veya kutuyu silebilir | P01 |
| 3 | S5-B01 | Kritik | Hangi branch'e push edilirse edilsin katalog SHA'sı değişiyor; `feature/*` commit'i auto-update açık canlı sitelere kuruluyor | P02 |
| 4 | S5-B06 | Kritik | Auto-update, CMS < 1.2.32 sitelerde müşterinin düzenlediği tema dosyalarını sessizce eziyor | P02 |
| 5 | S6-B01 | Kritik | DNS şablonu müşterinin mevcut SPF/TXT, `_dmarc` ve MX kayıtlarını içeriğe bakmadan eziyor | P06 |
| 6 | S2-B04 | Kritik | Aynı Coolify uygulaması iki siteye bağlanabiliyor; birini purge etmek ötekinin canlı verisini siliyor | P05 |
| 7 | S10-B13 | Kritik | Plane'in kendi MySQL'i yedeklenmiyor; kayıpta tüm bağlantı kimlikleri ve site agent secret'ları gider | P07 |
| 8 | S9-B01 · S2-B10 | Yüksek | 16 tehlikeli işlem (tekli/toplu purge, bağlantı/zone silme, secret rotate) Operator'a açık; operatör tek onayla tüm filoyu kalıcı silebilir | P03 |
| 9 | S3-B06/B07 · S1-B09 | Yüksek | Deploy satırları ve job'lar takılı kalabiliyor (`failed()` yok, watchdog yok); tek takılı satır bir Coolify bağlantısındaki bütün deploy'ları durdurur | P09, P10 |
| 10 | S4-B01/B02 · S7-B04 | Yüksek | Alarm sinyali güvenilmez: 429 ve stopped siteler sahte "site düştü" maili üretiyor, gerçek alarmın gönderim hatası ise sessizce yutulup bir daha denenmiyor | P11, P12 |

## En değerli 10 öneri

| # | Madde | Puan | Efor | Neden |
|---|-------|-----:|------|-------|
| 1 | P01 Mail proxy sahiplik kontrolü | 12 | S | Siteler arası yetki açığını tek kontrolle kapatır |
| 2 | P05 Attach tekilliği + hedef kilidi | 11 | S | Yanlış purge ile müşteri verisi kaybını önler |
| 3 | P02 Tema webhook güvenliği (default branch + 1.2.32 kapısı + idempotency) | 10 | M | İki Kritik bulguyu ve replay riskini kapatır |
| 4 | P04 Env prune korkuluğu | 10 | M | Filo çapında secret kaybını önler |
| 5 | P03 `ops.danger` Gate (SA) | 10 | M | Geri alınamaz işlemleri tek rol arkasına alır |
| 6 | P06 DNS şablonu üzerine yazmaz | 10 | M | Müşteri mail teslimatını korur |
| 7 | P07 Plane DB yedeği + APP_KEY prosedürü | 10 | M | Plane'in tek hata noktasını kapatır |
| 8 | P09 Watchdog + `failed()` | 10 | M | 11 takılı durum türünü kendiliğinden onarır |
| 9 | P12 Bildirim outbox | 10 | M | Alarm kaybını bitirir; otomasyon bildiriminin tabanı |
| 10 | P13 Otomasyon kapısı + kill switch + bütçe | 10 | M | Mevcut L4 otomasyonları sınırlar, Settings'ten kapatılabilir yapar |

## Otonomi hedefi

- **Bugün:** 45 operasyonel akıştan 18'i L0, 12'si L1. 6 akış L4'te ama hiçbiri zorunlu 9 alanı (tetik, ön koşul, eylem, doğrulama, geri alma, bütçe, kill switch, audit, bildirim) taşımıyor.
- **Önkoşul:** 19 maddelik otonomi güvenlik tabanı (T01–T19). Bunlar kapanmadan yeni otomasyon eklenmemeli.
- **90 gün:** L0 = 0, L4 = 23, L5 = 5. Yeni L4 kuralları önce 14 gün gölge modda çalışır, sonra alpha → beta → main sırasıyla açılır.
- **İlk 3 otomasyon adımı:** (1) geri alınamaz otomatiği durdur (P04, P02); (2) otomasyon kapısı + bütçe + kill switch (P13); (3) watchdog ve doğrulama çerçevesi (P09 → P33).

## En değerli 5 yeni modül adayı

| Aday | Backlog | Puan | Not |
|------|---------|-----:|-----|
| Uyarı ve bildirim merkezi | P12 → P32 | 10 | Önceki D1/D2 hâlâ açık |
| Env kataloğu değişiklik kapısı | P04 | 10 | Kritik S3-B01'in kalıcı çözümü |
| Otomasyon kontrol paneli + Plane öz-sağlık paneli | P13, P24 | 9–10 | Tek spec önerildi |
| TLS/DNS gözetimi | P34 | 9 | Öneri-B8 hâlâ açık |
| Plane hesapları + 2FA | P21 | 9 | Öneri-B1/B2; güvenliğin önkoşulu |

## 30 / 60 / 90 gün

| Ufuk | Hedef | Maddeler |
|------|-------|----------|
| **İlk hafta** | 7 kök Kritik bulgu kapalı; geri alınamaz işlemler SA'da; otonomi tabanının küçük dilimleri | P01–P08 + P17, P09 (doğru satır), P13 (salt-okunur kill switch) |
| **30 gün** | Otonomi tabanı: watchdog, kuyruk ayrımı, sinyal doğruluğu, bildirim outbox, otomasyon kapısı; P21 spec'i başlamış | P09–P13, ardından P14–P31 (puan sırasıyla) |
| **60 gün** | Kalan Şimdi maddeleri; bildirim merkezi, doğrulama + gölge mod, TLS/DNS, sağlık geçmişi, bakım penceresi | P32, P33, P34, P36, P37 |
| **90 gün** | Drift dedektörü, tema kanarya rollout, 500 site ölçeği, PHP 8.4 (2026-12-31 öncesi) | P35, P38, P39, P40 |

## Kullanıcıdan beklenen kararlar

Hafta 1'i etkileyen 8 karar ([18 §1](18-acik-sorular-ve-kararlar.md)): K01 Operator güven modeli, K02 toplu purge sınırı, K03 env kaldırma onayı, K04 DNS şablonu, K05 auto-update ref'i, K06 1.2.32 öncesi siteler, K07 yedek hedefi, K08 unique index. Hepsinin önerilen default'u var; aksi söylenmezse backlog bu default'larla ilerler.
