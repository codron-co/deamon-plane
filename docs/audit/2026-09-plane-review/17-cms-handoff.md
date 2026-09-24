# 17 — CMS handoff (istek listesi)

> **ADR-12 notu (2026-09-24, denetim sonrası karar):** Site env'i 6 önyükleme anahtarına iner; yeni zorunlu env anahtarı eklenmez, yeni ayarlar agent ile gelir; `APP_ENV` her zaman `production` ve kanal geçişi yalnızca dalı değiştirir. Bu belgedeki env/kanal önerileri bu kararla birlikte okunmalı — [ADR-12](../../decisions/adr-12-site-config-from-plane.md).

**Tarih:** 2026-09-24 · **HEAD:** 8484d37 · **Hazırlayan:** Orkestratör (Dalga 3)
**Kapsam:** Plane önerilerinin `codron-co/deamon` (CMS) tarafında gerektirdiği sözleşme, endpoint, payload ve sürüm değişiklikleri. **Bu dosya yalnız istek listesidir; CMS kodu önermez.** Her madde CMS repo'sunda ayrı spec ile ele alınmalıdır.
**Kaynak:** 02–14 raporlarının §6 bölümleri. Tekrarlar birleştirildi.

## Özet

- **Plane'in Şimdi fazı CMS'e bağımlı değil.** Hafta 1'in Kritik düzeltmelerinin (P01–P08) hepsi yalnız Plane'de yapılabilir. CMS istekleri doğrulama sinyallerini zenginleştirir ve Sonra/Belki fazındaki modüllerin önünü açar.
- En değerli üç istek:
  1. **Health payload genişletmesi:** çalışan commit SHA'sı, aktif tema SHA'sı, `contract_version`/`capabilities[]`, kuyruk/cron sağlığı. Bu sinyaller otomatik düzeltmelerin kapalı döngüde (L5) doğrulanabilmesi için gerekli.
  2. **Env göç kuralı:** `.env.production.example`'dan anahtar kaldırma/yeniden adlandırma bir sürüm boyunca "deprecated" ile yapılmalı. P04 ile birlikte S3-B01 riskini iki taraftan kapatır.
  3. **Yedek uçları:** site DB + storage yedeği (P59). Önce Coolify zamanlanmış yedek spike'ı yapılacak; o yeterliyse bu istek düşer.
- Doğrulanmamış varsayımlar tabloda "Doğrula" etiketiyle ayrıldı. Bunlar CMS ekibinin evet/hayır ile cevaplayabileceği sorulardır.

## 1. İstekler

| ID | İstek (CMS tarafı) | Plane gerekçesi | Kaynak | Backlog | Öncelik | Tür |
|----|--------------------|-----------------|--------|---------|---------|-----|
| C01 | Env göç kuralı: `.env.production.example`'da secret/generated anahtar kaldırma ve yeniden adlandırma önce yeni anahtarı ekler, eskisini en az bir sürüm tutar; isteğe bağlı `#@deprecated` yönergesi | Katalogdan tek satır düşmesi filo çapında secret silinmesine yol açıyor | S3-B01, S3 §6, S12-M16 | P04 | Yüksek | Süreç |
| C02 | Health payload'ında **çalışan commit SHA'sı** | Otomatik redeploy/fix sonrası "doğru sürüm ayakta" doğrulaması (L5) | S3 §6, S11 §6 | P33, P35 | Yüksek | Payload |
| C03 | Health veya `GET /themes` yanıtında **aktif tema `sha`** ve `customizations` özeti | Tema SHA sapması, kanarya doğrulaması | S5 §6, S12-M17 | P35, P38 | Yüksek | Payload |
| C04 | `contract_version` ve `capabilities[]` | Sürüm eşiklerini tek haritada tutmak, guard koşulları | S4-O21, S11 §6 | P51, P33 | Orta | Payload |
| C05 | Kuyruk derinliği, `failed_jobs` sayısı, son schedule çalışma zamanı (önceki F4) | "CMS worker'ı ölü" sinyali, sağlık geçmişi | S4 §6, S10 §6, S12 §6 | P36 | Orta | Payload |
| C06 | `platform_mail_config_hash`, `deskron_configured`, `mail_plugin_enabled` (secret içermeyen) | Push'un gerçekten uygulandığını doğrulama | S7 §6 | P20, P33 | Orta | Payload |
| C07 | Opsiyonel önceki secret kabulü (`CONTROL_PLANE_AGENT_SECRET_PREVIOUS` veya `_NEXT`) | Kesintisiz rotasyon. Plane-only fallback ile de çalışır | S4-O06, S9 §6 | P45 | Düşük | Sözleşme |
| C08 | İmza reddinde sabit JSON (`error: bad_signature`), WAF 403'ünden ayırt edilebilir | Sağlık sınıflandırması | S4-O07 | P51 | Düşük | Sözleşme |
| C09 | Her `/internal/control/v1/*` ucu için örnek istek/yanıt ve hata gövdesi JSON'ları (önceki E2) | Plane'de fixture tabanlı sözleşme testleri | S1-O04 | P52 | Orta | Doküman |
| C10 | `themes/update`: ref'te olmayan veya SHA olmayan değerde açık 422; aynı SHA'da `unchanged:true` no-op | Hatalı SHA teşhisi, idempotent fan-out | S5 §6 | P02, P19 | Orta | Sözleşme |
| C11 | Site yedek uçları: `POST …/backup`, `GET …/backups` (önceki F3) | Kanal düşürme / purge öncesi yedek kapısı | S10 §6, S12-M04 | P59 | Belki | Endpoint |
| C12 | Bakım allowlist'i `POST …/maintenance/{action}` ve redakte `GET …/logs?lines=200` (önceki F1, F2) | Uzak bakım sekmesi | S12-M15 | P60 | Belki | Endpoint |
| C13 | Uyumluluk bayrağı (ör. `requires_env[]`) | Release train aday listesi | S12-M06 | P61 | Belki | Payload |
| C14 | HMAC v2: kanonik metne metot + yol + yön; bir sürüm boyunca v1 ve v2 birlikte | İmzanın başka uca yeniden kullanılmasını engellemek | S7-O08, S9-O12 | P62 | Belki | Sözleşme |
| C15 | DeskRon için site başına kısıtlı anahtar (DeskRon + CMS) | Paylaşılan `master_key`'in etki alanını daraltmak | S7-B07, S9-O14 | P55 | Sonra | Sözleşme |
| C16 | Mail proxy 404 ("bu siteye ait değil") yanıtının CMS mailbox ekranında anlamlı gösterimi | P01 sonrası kullanıcı deneyimi | S9 §6 | P01 | Düşük | UI |
| C17 | Mailbox istek ekranında 409 (yinelenen) ve `fulfilled_mailbox_id`; `provider_unauthorized` mesajının imza hatasından ayrı gösterimi | Mailbox akışı netliği | S7 §6 | P48 | Düşük | UI |
| C18 | Health yanıtında env/secret yankısı olmaması garantisi (Plane kontrolü 9 çağrının 2'sinde) | Secret sızıntısı savunması | S1-B05, S9 §6 | P52 | Orta | Sözleşme |
| C19 | Önceki F5/F6/F7: `modules[]`, `stats{}` ve `POST …/admins/{id}/logout` | Modül filtresi, içerik sayıları, admin oturumu kapatma | S12 §6 | P51, P32 | Belki | Payload / Endpoint |
| C20 | Klon senaryosunda boş CMS'in `draft` yayın durumuyla açılması | Preview klonun kazara indekslenmemesi | S12 §6 | P63 | Belki | Davranış |

## 2. Doğrulanması gereken CMS davranışları (evet/hayır soruları)

| ID | Soru | Neden önemli | Kaynak |
|----|------|--------------|--------|
| Q01 | Nonce, throttle'dan **önce** mi saklanıyor? Önceyse 429 sonrası aynı nonce'la retry sahte `bad_signature` üretir | P41 tasarımı | S4-B15 |
| Q02 | İmzalı Plane health istekleri throttle dışında mı? | 429 kaynaklı sahte down alarmı | S4-B01 |
| Q03 | `site/identity` isimde hangi dönüşümleri yapıyor? Normalize ediyorsa identity heal her poll'da sonsuz POST döngüsüne girer | S4-B18 | S4 §6 |
| Q04 | Secret değişimi restart gerektiriyor mu? Restart'sız okuma mümkün mü? | P16/P45 kesinti süresi | S4-B06 |
| Q05 | Yeni zorunlu env anahtarı eksikken CMS nasıl açılıyor (hızlı hata mı, varsayılan mı)? **ADR-12 ile cevaplandı:** yeni zorunlu env anahtarı eklenmez; yeni ayar agent ile gelir ve CMS'te kod varsayılanı vardır. Katalogdan çıkacak `APP_ENV` / `DEAMON_SITE_NAME` için C01 göç kuralı geçerli. | P57 tetikleme kararı | S3-B26 |
| Q06 | `themes/update` 10 sn agent timeout'u içinde bitiyor mu, arka planda mı çalışıyor? | P19 smoke/süre bütçesi | S5 §6 |
| Q07 | Runtime imajında `git` var mı? (önceki 1.3-d) | Tema kurulum/güncelleme akışının canlıda çalışması | S5 §6, 15 |
| Q08 | `CONTROL_PLANE_HOST_ALLOWLIST` kontrolü hangi CMS sürümünde kaldırıldı? | Plane "min CMS sürümü" uyarısı | S7-B23 |
| Q09 | Alias hostlardan birincil domaine kanonik yönlendirme veya `rel=canonical` var mı? | Yinelenen içerik / SEO | S6 §6 |
| Q10 | Birincil domain değişince önbelleğe alınmış mutlak URL'ler (sitemap, medya) redeploy sonrası yenileniyor mu? | Domain değişikliği güvenliği | S6 §6 |
| Q11 | 1.2.32 öncesi CMS'ler ne zaman güncellenecek? | P02'deki atlamaların (auto-update yapılmayan siteler) azalması | S5 §6 |

## 3. Sürüm kapıları özeti (Plane'in bugün bildiği)

| Yetenek | CMS sürümü | Plane'de kullanım | Kaynak |
|---------|-----------|-------------------|--------|
| Agent imza başlıkları | 1.1.43 | Tüm agent çağrıları | `docs/architecture.md` |
| Admin password invite | 1.2.18 | Site adminleri, P51 (provision sonrası davet) | Önceki A4 |
| Tema overwrite/merge | 1.2.21 | Tema sync | `b78e548` |
| Tema dosya özelleştirmelerini koruma | 1.2.32 | Tema update, P02 kapısı | `16adae4`, `app/Models/Site.php:672` |

Bu tablo C04 (`capabilities[]`) geldiğinde tek haritaya taşınmalı (P51).
