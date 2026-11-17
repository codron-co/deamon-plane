# Güvenlik (filo güvenliği)

> CMS tarafı: `deamon` deposundaki `security-alerts` modülü (tasarım: `docs/superpowers/plans/2026-11-17-security-alerts-module.md`, §10 Plane tarafı). Bu sayfa Plane'in yarısıdır: toplama, ekranlar, filo tepkisi, e-posta uyarısı, MCP.

Plane her sitenin güvenlik özetini imzalı agent ile çeker, olayları kendi veritabanında 90 gün tutar, aynı IP'nin birden çok siteye vurmasını öne çıkarır ve bir IP'yi bütün sitelerde engelleyip kaldırabilir. WAF değildir: görünürlük, uyarı ve basit tepki.

## Kurallar

- **CPU:** filo tek bir sunucuyu paylaşır. Toplama siteleri tek tek, her site `ops.security.spacing_seconds` (4 sn) arayla sıraya alınır; bir turda en çok `max_sites_per_run` (60) site, bir site için en çok `max_pages_per_run` (3) sayfa (200 olay). Tüm filo aynı anda vurulmaz.
- **Bir site kırılırsa** diğerleri etkilenmez: hata/zaman aşımı imleç satırına yazılır (`consecutive_failures`, `last_error`), job asla hata atmaz. 3 ardışık hatadan sonra site saatte bir yoklanır.
- **Eski CMS** (uç nokta 404/405): `supported=false`, hata sayılmaz, 24 saatte bir yeniden sorulur.
- **Sır ve gövde saklanmaz.** Olaya yalnız sözleşmedeki alanlar girer; yolun sorgu dizgisi kesilir (token'lar orada gezer); sitenin agent secret'ını yansıtan cevap atılır.
- **Test sitesi önce:** filo engeli/izin listesi önce `test` sitesine gider; o almazsa kimseye dokunulmaz.

## Veri (`database/migrations/2026_11_17_100000_create_security_fleet_tables.php`)

| Tablo | Ne tutar |
|---|---|
| `site_security_events` | site başına olay; `(site_id, event_uid)` tekil, tekrar yoklama kopya üretmez. Saklama 90 gün (`ops.security.retention_days`, en az 7). |
| `site_security_cursors` | site başına imleç, son yoklama/cevap, `supported`, `module_enabled`, hata sayacı, sitenin sayaç bloğu (`summary`: 24s/7g, en çok vuran IP'ler). |
| `fleet_ip_blocks` | filo engeli: `uid` (siteye giden idempotency anahtarı), `ip`/CIDR, `expires_at`, `released_at`, siteden siteye sonuç (`applied`). |
| `fleet_allowlist` | filo izin listesi: `uid`, `ip`/CIDR, `label`, `removed_at`, `applied`. |

`security:prune` (günlük 03:20) 5.000'lik parçalarla siler; `--days` en az 7'ye sabitlenir (yazım hatası tabloyu boşaltamaz).

## Toplama

`DispatchSecurityCollectionJob` (her 10 dk, `ops-security-collect`) aktif ve agent secret'lı siteleri sıralar (test sitesi önce, sonra en uzun süredir yoklanmayan), her biri için `CollectSiteSecurityJob`'u gecikmeli kuyruğa atar. `SecurityCollector`:

1. `GET /internal/control/security/summary?since=<imleç>` imzalı (HMAC, health ile aynı başlıklar; GET'te gövde `""`).
2. Olaylar `SecurityEventSanitizer`'dan geçer (tip, uzunluk, IP geçerliliği, gelecekteki zaman, yol sorgusu).
3. Yeni olaylar toplu eklenir (`insertOrIgnore`), imleç ancak sayfa yazıldıktan sonra ilerler. `has_more` ise sıradaki sayfa (üst sınır var).
4. Yeni olaylar `SecurityAlertNotifier`'a gider.

`/health` yanıtındaki küçük `security` bloğu (`module_enabled`, `critical_24h`, `blocks_active`) `AgentHealthResult` ve `SiteHealthChecker` ile `sites.last_health_payload.security` içine yazılır; modülü kapalı olduğunu bildiren siteye bir saat boyunca summary sorulmaz.

## Site uç noktası sözleşmesi (CMS'in sağlaması gereken)

Hepsi `EnsureControlPlaneSignature` ile imzalı, `/internal/control/security/...` (v1 altında değil).

`GET /internal/control/security/summary?since=<cursor>` → 200:

```json
{
  "module_enabled": true,
  "generated_at": "2026-11-17T10:00:00Z",
  "counts": {
    "24h": {"total": 12, "by_severity": {"0": 2, "1": 9, "2": 1}, "by_type": {"probe": 9, "honeypot": 1}},
    "7d":  {"total": 80, "by_severity": {"0": 10, "1": 65, "2": 5}, "by_type": {"probe": 60}}
  },
  "top_ips": [{"ip": "203.0.113.7", "events": 12, "critical": 1, "rules": ["sensitive_probe"]}],
  "blocks_active": 0,
  "cursor": "opaque-string-max-255",
  "has_more": false,
  "events": [{
    "uid": "stable-row-id (<=64, [A-Za-z0-9._:-])",
    "occurred_at": "2026-11-17T09:58:00Z",
    "type": "probe", "severity": 1, "rule": "sensitive_probe",
    "ip": "203.0.113.7", "country": "US",
    "user_agent": "python-httpx/0.27", "method": "GET", "path": "/.env", "status": 403, "count": 1
  }]
}
```

`events` = imleçten sonraki en çok 200 olay, eskiden yeniye; `since` yoksa en eski olaydan başla. Modül kapalıysa `module_enabled:false` ve boş `events`. `severity` 0 bilgi, 1 uyarı, 2 kritik. `path` sorgusuz olmalı; istek gövdesi, çerez, başlık yok.

`POST /internal/control/security/block` gövde `{"uid","ip","reason","expires_at"|null,"source":"plane"}` → `{"ok":true,"state":"blocked|already_blocked"}`.
`POST /internal/control/security/unblock` gövde `{"uid","ip"}` → `{"ok":true,"state":"released|not_blocked"}`.
`POST /internal/control/security/allowlist` gövde `{"uid","action":"add|remove","ip","label"}` → `{"ok":true,"state":"added|exists|removed|absent"}`.

Yazan uç noktalar `uid` ve `ip` üzerinden idempotent olmalı; izin listesi her zaman engelden üstündür. 404/405 = "bu CMS'te yok" (Plane hata saymaz), 401/403 = imza reddi, 429 = Plane retry-after ile bekler, 422 = geçersiz gövde.

## Ekranlar (Güvenlik menüsü, `routes/ops/security.php`)

| Adres | İçerik |
|---|---|
| `/security` | filo özeti: 24s/7g olay ve kritik, en çok hedef alınan siteler, en aktif IP'ler, çapraz site kartları, kapsam |
| `/security/correlation` | aynı IP ≥2 sitede (uyarı ya da daha ağır) kartları; 24s/7g/30g |
| `/security/events` | olay akışı: yazdıkça arama (200 ms), süzgeç (huni ikonu, açık süzgeçte nokta), sıralama; yalnız liste bölgesi yenilenir (`ListFragment`, `history.replaceState`) |
| `/security/sites/{site}` | site ayrıntısı: modül/desteklenme, son yoklama, hata, sağlık bloğu, sayaçlar, son olaylar |
| `/security/blocks` | açık filo engelleri, izin listesi, ekle/kaldır |

Okumak her rolün (viewer dahil). Engelle/kaldır/izin ekle `canWriteOps` (operator, super admin); izin listesinden çıkarmak yalnız super admin (koruma kaldırır). Onay penceresi sayfa içi (`data-confirm`), `confirm()`/`alert()`/`location.reload()` yok.

## Filo tepkisi

`FleetSecurityResponse` panelin ve MCP'nin ortak yolu: niyeti yazar (adres başına tek kayıt, ikinci çağrı "yeniden dene"), `security.fleet_block|unblock|allowlist_add|allowlist_remove` denetim satırını yazar, tek arka plan işi (`security.fleet_apply`) kuyruğa atar. `FleetSecurityApplier` siteleri tek tek dolaşır: `test` önce, o başarısızsa iş durur ve hata verir, diğer sitelere dokunulmaz; sonra kalanlar sırayla (`apply_spacing_ms` arayla). Tamamlanan site tekrar çağrılmaz; kaçanlar tekrar çalıştırmada tamamlanır.

Güvenlik duvarları: özel/loopback/ayrılmış adresler (proxy, Plane, sunucu) engellenemez; IPv4 için /16, IPv6 için /32'den geniş aralık engellenemez; izin listesiyle çakışan adres engellenemez.

## E-posta uyarısı

Tek kanal e-posta (WhatsApp/Telegram yok). `SecurityAlertNotifier` kritik olayda ve çapraz site taramasında süper adminlere (`PlatformOpsMailer::sendToSuperAdmins`, `security_alert` anahtarı, abonelikten çıkış bağlantılı) tek özet e-posta kuyruğa atar. Bastırma: kritik olay site+kural+IP başına 30 dk, çapraz site IP başına 6 saat (`ops.security.alert_window_minutes`, `cross_site_window_minutes`); izin listesindeki IP çapraz site uyarısı üretmez; bir toplama turu en çok bir e-posta atar. Platform e-postası hazır değilse olay yine kaydedilir, e-posta atlanır.

## MCP

Okuma (`read`): `list_security_events`, `get_site_security`, `get_fleet_security_summary`, `list_fleet_allowlist`. Yazma (`write`, kullanıcı rolü de `canWriteOps` olmalı, `confirmed: true` şart): `block_ip_fleet`, `unblock_ip_fleet`, `add_fleet_allowlist`. Hepsi `FleetSecurityResponse` üzerinden denetim satırı yazar ve `job.id` döner (`list_jobs`). Ayrıntı: [mcp.md](mcp.md).

## Yapılandırma (`config/ops.php` → `security`)

`poll_minutes`, `max_sites_per_run`, `spacing_seconds`, `timeout_seconds`, `max_pages_per_run`, `unsupported_retry_hours`, `backoff_after_failures`, `retention_days`, `alert_window_minutes`, `cross_site_window_minutes`, `cross_site_min_sites`; env: `OPS_SECURITY_POLL_MINUTES`, `OPS_SECURITY_MAX_SITES_PER_RUN`, `OPS_SECURITY_SPACING_SECONDS`, `OPS_SECURITY_TIMEOUT`, `OPS_SECURITY_RETENTION_DAYS`.

## Bilinen sınırlar

- Siteler açılırken (yeni provision) mevcut filo engelleri otomatik uygulanmaz; engel bir sonraki "yeniden dene" ile gider.
- Süre dolan engeli siteler kendisi kaldırır; Plane yalnız `expires_at` ile "açık" saymayı bırakır.
- Anlık itme (`POST /api/site-agent/security-alert`) bu aşamada yok; kritik olay en geç bir yoklama aralığında (10 dk) görünür.
