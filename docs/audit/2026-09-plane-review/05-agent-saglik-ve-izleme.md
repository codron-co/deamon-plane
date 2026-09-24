# 05 — Site agent, sağlık ve izleme

**Tarih:** 2026-09-24 · **HEAD:** 8484d37 · **Hazırlayan:** S4 — Site agent, sağlık ve izleme denetçisi (Dalga 1)
**Kapsam:** `app/Services/Agent/**`, `app/Services/Fleet/FleetDashboardKpis`, `app/Services/Sites/{SiteAppHealth*,SiteAgentSecretInjector,SiteAgentSecretSweep,SiteIdentityPusher,SiteLiveProbe,SiteListSummary (trend)}`, `app/Support/{ControlPlaneAgentSignature,OpsFreshness}`, `app/Support/Lists/FleetAttentionQuery`, `FleetController`, `SiteAppHealthController`, `CheckSiteHealthJob`, `DispatchSiteHealthChecksJob`, `InspectSiteAppHealthJob`, `ops:snapshot-fleet` + `FleetDailySnapshot`, `SiteHealthMailNotifier` + `PlatformOpsMailer`, `EnsureSiteMailSignature` (yalnız HMAC penceresi), ilgili view/JS/testler · **Kapsam dışı:** tema agent POST'ları (S4/M4), Coolify deploy teşhisi (M2), SSRF ayrıntısı (S9), CMS middleware kodu (CMS handoff).

## Özet

- **Alarm yolu gürültülü ve kör noktalı.** `SiteHealthMailNotifier` her `ok=false` sonucu "Unhealthy" sayıyor: 429 (`rate_limited`), `no_base_url` ve `needs_secret` de "Siteniz düştü" maili tetikliyor. KPI tarafı ise bu durumları muaf tutuyor (S4-B01). Site durumuna göre bastırma yok (stopped/deploying/provisioning). Tek bir başarısız poll bile mail gönderiyor. Bildirim geçmişi de tutulmuyor (S4-B02, S4-B13).
- **Başarısız bir poll bilinen gerçekleri siliyor.** Payload baştan yazıldığı için `deamon_version` ve `active_theme_id` kayboluyor. Sonuçta `ChannelSwitcher` sürüm kapısı ("missing does not block") tek bir timeout ile aşılabiliyor (S4-B03).
- **Agent secret yaşam döngüsünde üç açık var.** (1) Rotate yeni secret'ı DB'ye Coolify yazımından *önce* kaydediyor; Coolify hata verirse DB ile CMS ayrışıyor (S4-B05). (2) Rotate/inject sonrası uygulama yeniden yüklenmiyor ve grace yok (S4-B06). (3) HTML 200 dönen `agent_not_registered` siteleri "doğrulandı" sayılıyor (S4-B04).
- **Hata sınıflandırması operatörü yanlış işleme yönlendiriyor.** Her 401/403 `bad_signature` sayılıyor, Cloudflare WAF'ın 403 HTML yanıtı da buna dahil. DNS, TLS ve bağlantı reddi hataları "timeout" olarak görünüyor (S4-B07, S4-B08).
- **En değerli öneriler.** Mail yolunu KPI ile hizalamak (S4-O01), durum makinesi + N-ardışık eşik + bakım/deploy bastırması (S4-O02), rotate'i önce Coolify'a yazıp sonra DB'ye kaydedecek şekilde düzeltmek (S4-O05) ve Plane heartbeat'i (S4-O16). Bunlar kapalı döngü otonominin (§4) önkoşulu.

## 1. Mevcut durum

### 1.1 Akışlar

```mermaid
flowchart TD
  S[schedule:work<br/>routes/console.php:16 */10] --> D[DispatchSiteHealthChecksJob<br/>tüm Site::query()]
  D -->|her site| C[CheckSiteHealthJob unique 240s, timeout 30s]
  D -->|coolify_app_uuid dolu| I[InspectSiteAppHealthJob unique 240s, timeout 45s]
  C --> H[SiteHealthChecker::check]
  H --> A[SiteAgentClient::health<br/>HMAC GET /internal/control/v1/health]
  H --> V[SiteFilterVerdict::apply → save]
  H -->|ok| ID[SiteIdentityPusher::healFromHealth]
  H -->|ok| CT[CoreThemeHealer::healFromHealth → Coolify restart]
  H --> M[SiteHealthMailNotifier::afterHealthCheck → PlatformOpsMailer senkron SMTP]
  I --> INS[SiteAppHealthInspector::inspect live<br/>Coolify getApp + listEnvs]
  INS --> V2[applyApp → save]
  UI[Site detay: Check health / App health / Fix] --> H
  UI --> F[SiteAppHealthFixer::fix / fixAll / fixMany]
  F --> INS
  SN[ops:snapshot-fleet 23:50] --> FS[(fleet_daily_snapshots)]
```

- Manuel: `POST /sites/{site}/health` → `SiteController::checkHealth` (`app/Http/Controllers/Ops/SiteController.php:647-667`), senkron.
- Rotate/inject: `SiteController::injectAgentSecret` (`:673-691`); toplu: `SiteAgentSecretSweep` (yalnız secret'ı olmayan siteler, `app/Services/Sites/SiteAgentSecretSweep.php:30-44`).
- Live probe: yalnız manuel (`SiteCoolifyOpsController.php:238,298`, `OpsJobRunner.php:57`); schedule'da yok (`routes/console.php`).
- Env kataloğu değişince yeniden teşhis: `CoolifyEnvCatalogSync.php:139-151` → `InspectSiteAppHealthJob` (ADR-10 invalidasyonu, doğru çalışıyor).

### 1.2 Ana dosyalar

| Dosya | Sorumluluk | Satır | Test |
|-------|-----------|-------|------|
| `app/Services/Agent/SiteAgentClient.php` | İmzalı GET/POST, hata sınıflandırma | 693 | `tests/Feature/Agent/SiteAgentHealthTest.php`, `AgentNotRegisteredHealthTest.php` |
| `app/Services/Agent/AgentHealthResult.php` | Payload parse, özet | 163 | aynı |
| `app/Services/Agent/SiteHealthChecker.php` | Kaydet, allowlist, heal kancaları, mail | 96 | `HealthAppFilterTest.php:242` |
| `app/Services/Agent/SiteHealthEvaluator.php` | unhealthy/stale/displayStatus | 113 | `tests/Unit/Agent/SiteHealthEvaluatorTest.php` (3 test) |
| `app/Services/Agent/CoreThemeHealer.php` | core_theme_stale → otomatik restart | 68 | `tests/Feature/Themes/ThemeStorefrontGuardTest.php:139,173` |
| `app/Services/Agent/Concerns/RetriesThrottledAgentRequests.php` | 429 / bağlantı retry | 60 | `tests/Unit/Agent/RetriesThrottledAgentRequestsTest.php` |
| `app/Support/ControlPlaneAgentSignature.php` | HMAC canonical / imza | 51 | `tests/Unit/Agent/ControlPlaneAgentSignatureTest.php` |
| `app/Services/Sites/SiteFilterVerdict.php` | ADR-10 kolonları + SQL predicate | 104 | `tests/Feature/Ops/HealthAppFilterTest.php` |
| `app/Services/Sites/SiteAppHealthInspector.php` | Issue kataloğu, canlı Coolify teşhisi | 436 | `tests/Feature/Sites/SiteAppHealthTest.php` (16) |
| `app/Services/Sites/SiteAppHealthFixer.php` | Fix sırası, tek/toplu fix | 368 | aynı + `SiteListAppHealthCountCostTest.php` |
| `app/Services/Sites/SiteAgentSecretInjector.php` | Inject / rotate | 57 | `tests/Feature/Sites/AgentSecretInjectTest.php` (3) |
| `app/Services/Mail/SiteHealthMailNotifier.php` | down/up/sürüm maili | 135 | **yok** |
| `app/Services/Sites/SiteLiveProbe.php` | Ana sayfa GET + favicon | 177 | `tests/Unit/Services/SiteLiveProbeTest.php`, `SiteLiveSyncTest.php` |
| `app/Services/Sites/SiteListSummary.php` | Sayaçlar + günlük snapshot/trend | 85 | `tests/Feature/Sites/FleetSnapshotTrendTest.php` (6) |
| `app/Services/Fleet/FleetDashboardKpis.php` | Fleet KPI + dikkat kartları | 250 | `FleetDashboardTest.php`, `AgentSecretFleetTest.php` |
| `app/Support/OpsFreshness.php` | Tazelik rozeti | 77 | `SiteFreshnessBadgeTest` (M1) |
| `app/Http/Middleware/EnsureSiteMailSignature.php` | Gelen HMAC (mail proxy) | 96 | `tests/Feature/Mail/SiteMailProxyTest.php` |

Hedefli test koşusu: `php artisan test --compact tests/Feature/Agent tests/Unit/Agent tests/Feature/Sites/{AgentSecretInject,FleetSnapshotTrend,SiteAgentReasonLabel,SiteAppHealth,SiteLiveSync}Test.php tests/Feature/Ops/{AgentSecretFleet,FleetDashboard,HealthAppFilter}Test.php tests/Unit/Services/SiteLiveProbeTest.php` → **115 geçti / 541 assertion / 51 sn**.

### 1.3 Test kapsamı

| Testli | Testsiz |
|--------|---------|
| Canonical string, header adları, nonce uzunluğu (`ControlPlaneAgentSignatureTest`) | `SiteHealthMailNotifier` down/up/sürüm geçişleri (grep: `tests/` içinde `SITE_DOWN`/`last_health_notify_status` testi yok) |
| 429 retry, bağlantı retry opt-in (`RetriesThrottledAgentRequestsTest:22-74`) | Rotate sırasında Coolify hatası (DB ayrışması) |
| timeout, `queue_ok=false`, needs_secret, viewer 403 (`SiteAgentHealthTest:97-185`) | 403 HTML (WAF) ↔ `bad_signature` ayrımı; `{}`/`[]` JSON → ok |
| HTML 200 → `agent_not_registered` (`AgentNotRegisteredHealthTest`) | `agentSecretIsVerified` HTML 200 ile "ok" sayılması |
| ADR-10 verdict yazımı, SQL filtre = kart sayısı (`HealthAppFilterTest`) | Stopped/deploying site için alarm davranışı |
| Snapshot upsert, trend, eski baseline (`FleetSnapshotTrendTest`) | Eksik gün / timezone sınırı |
| Identity heal (`SiteIdentityAgentTest:29,62`) | `fixAll` sırası ve çoklu reinspect maliyeti |
| core_theme restart pencere başına bir kez (`ThemeStorefrontGuardTest:173`) | Job zaman bütçesi (30 sn) aşımı |

### 1.4 Önceki inceleme maddeleri (bu alan)

| Önceki ID | Durum (15-...md) | Bu denetimde teyit / ek |
|-----------|------------------|--------------------------|
| Boşluk-B5 / D1 | Yapılmadı | Teyit: kanal yalnız SMTP (`PlatformOpsMailer.php:32-88`). → S4-O20 |
| Boşluk-B7 / Öneri-B3 | Kısmen | `deamon_version` yalnız JSON'da; hata poll'u siliyor (S4-B03), filtre filoyu PHP'de geziyor (S4-B17) |
| Boşluk-B9 | Geçersiz (kısmen) | **Doğrulandı**: tek adımlı rotate var (`SiteController.php:677-680`, `SiteAgentSecretInjector.php:16,27-28`). Grace/çift secret yok (`ControlPlaneAgentSignature.php:20-29`, `EnsureSiteMailSignature.php:40`). Ek hata: S4-B05 |
| Boşluk-B10 / Öneri-B8 | Yapılmadı | Teyit: live probe sertifika okumuyor (`SiteLiveProbe.php:48-52`). → S4-O19 |
| Öneri-B4 | Yapılmadı | → S4-O06 |
| Öneri-B6 | Kısmen | Teyit + genişletme: stopped'ın yanı sıra deploying/provisioning de alarm üretiyor (S4-B02). Archived = soft delete, taranmıyor (`Site.php:32` `SoftDeletes`) |
| A7 | Kısmen | Otomatik yol yalnız `CoreThemeHealer`; doğrulama ve bildirim yok (§4) |
| A9 | Yapılmadı | Teyit; sağlık özeti için §5 |
| D2 | Yapılmadı | Teyit: yalnız kenar geçişi (`SiteHealthMailNotifier.php:21,37`), N-ardışık eşik yok. → S4-O02 |
| D3 | Yapılmadı | Teyit; §5 |
| D5 | Yapılmadı | Yalnız filo snapshot'ı var (tek an, 23:50). → S4-O12 |
| D6 | Yapılmadı | Worker ölünce tüm filo "stale → unhealthy" oluyor ama kimse uyarılmıyor (S4-B22). → S4-O16 |
| F4 | Kısmen | `queue_ok` + `core_theme_in_sync` var; kuyruk derinliği ve son schedule zamanı yok → CMS handoff |

### 1.5 Sözleşme ve şema referansı

**HMAC (giden, Plane → CMS)**: canonical string `{ts}.{nonce}.{body}`, HMAC-SHA256 hex (`ControlPlaneAgentSignature.php:10-18`). Nonce UUID, timestamp `now()` (`:36-37`). Skew ve replay kontrolü CMS'te; Plane'in `skew_seconds`/`nonce_ttl_seconds` ayarları yalnız gelen mail proxy'si için geçerli (`EnsureSiteMailSignature.php:57,79`). Retry aynı imzayı yeniden kullanıyor (S4-B15).

**Yanıt → sınıf** (`SiteAgentClient.php:25-133`):

| Durum | `status` | `reason` | KPI unhealthy | Mail "down" |
|-------|----------|----------|---------------|-------------|
| Secret yok | `needs_secret` | `needs_secret` | Hayır | **Evet** (önceki ok ise, S4-B01) |
| Base URL yok | `unknown` | `no_base_url` | Hayır | **Evet** |
| `ConnectionException` (DNS/TLS/refused/timeout) | `unhealthy` | `timeout` | Evet | Evet |
| 429 | `unknown` | `rate_limited` | Hayır | **Evet** |
| 401/403 (gövdeden bağımsız) | `unhealthy` | `bad_signature` | Evet | Evet |
| Diğer 4xx/5xx | `unhealthy` | `http_error` | Evet | Evet |
| 2xx HTML, placeholder metni | `unhealthy` | `proxy_fallback` | Evet | Evet |
| 2xx HTML | `unhealthy` | `agent_not_registered` | Evet | Evet |
| 2xx JSON, secret yankısı | `unhealthy` | `http_error` | Evet | Evet |
| 2xx JSON, `queue_ok=false` | `unhealthy` | `queue_unhealthy` | Evet | Evet |
| 2xx JSON, diğer her şey (`{}` dahil) | `ok` | — | Hayır | Up |

**`last_health_payload` şeması** (allowlist `SiteHealthChecker.php:61-74`): `ok`, `status`, `reason`, `deamon_version`, `channel_hint`, `active_theme_id`, `php`, `queue_ok`, `site_status`, `site_name`, `core_theme_in_sync`, `http_status`. Hata durumunda yalnız `ok/status/reason/http_status` kalıyor (S4-B03). Düz kolonlar: `last_health_at`, `health_unhealthy`, `health_verdict_at`, `cms_site_status(_at)`, `last_health_notify_status/at`, `last_notified_deamon_version`. `deamon_version` için düz kolon yok.

**Issue kodu → fix → otomatik güvenlik** (`SiteAppHealthIssue.php:43-61`, `SiteAppHealthInspector.php:96-165,226-372`, `SiteAppHealthFixer.php:28-40,145-157`):

| Issue | Fix | Yan etkisi | Otomatiğe uygun mu |
|-------|-----|-----------|--------------------|
| `missing_app` | — | — | — |
| `dockerfile_pack`, `wrong_compose_file` | `migrate_compose` | Compose yeniden kurulum | Hayır |
| `missing_env`, `wrong_env` | `sync_env` | Env yazar; etkisi için deploy gerekir | Yalnız secret olmayan static anahtarlar, deploy'suz (L4 aday, düşük değer) |
| `missing_agent_secret`, `missing_env:CONTROL_PLANE_AGENT_SECRET` | `inject_secret` | Env yazar (`rotate=false`) | Evet + restart (§4) |
| `deploy_failed` | `redeploy` | Deploy | Hayır |
| `deploy_diagnosed` | sınıflayıcının ilk fix'i (`:180`) | Değişken | M2 zaten `AUTO_SAFE` listesini otomatik uyguluyor (`DeploymentFailureClassifier.php:23`, `DeploymentDiagnoser.php:120`), buna `redeploy` ve `bind_domains` da dahil; app-health politikasıyla hizalanmalı (Açık soru 9) |
| `app_not_running` | `restart_app` | Restart | Evet, bütçeli (§4) |
| `core_theme_stale` | `restart_app` | Restart | Zaten otomatik (`CoreThemeHealer`) |
| `domain_unbound` | `bind_domains` | Coolify FQDN yazar | Hayır (M2/DNS riski) |
| `agent_unhealthy` | `check_health` | Salt okuma | Evet |
| `coolify_unreachable` | — | — | — |
| (sınıflayıcı) | `sync_deployments` | Salt okuma | Evet |
| (sınıflayıcı) | `stop_then_redeploy`, `rollback_last_good`, `follow_head` | Durdurma/pin | Hayır |

**Tazelik eşikleri** (S4-B12):

| Yer | Eşik | Kaynak |
|-----|------|--------|
| Rozet (`x-ops.freshness`) | 2 × poll = 20 dk | `OpsFreshness.php:21-29` |
| KPI/filtre `unhealthy` stale | 30 dk | `config/ops.php:113`, `SiteFilterVerdict.php:91-102` |
| Liste `stale=stale` | 24 sa | `Site.php:868,1032-1036` |
| Live probe rozeti | 20 dk (manuel probe için anlamsız) | `_cell.blade.php:165` |

**Günlük snapshot** (`8dfec88`): `fleet_daily_snapshots(snapshot_date UNIQUE date, total, unhealthy, failed_deploys, app_issues, git_themes)`. 23:50'de `app.timezone` saatiyle çalışıyor (`routes/console.php:38-42`); gün `CarbonImmutable::now(app.timezone)` ile belirleniyor (`SiteListSummary.php:42`). Upsert olduğu için tekrar çalıştırmak zararsız. Eksik gün doldurulmuyor, trend en son önceki satırı kullanıyor ve tarihini açıkça gösteriyor (`:57-83`, test `FleetSnapshotTrendTest:109`). Prune yok (Boşluk-B4). Değer tek bir anın ölçümü; heartbeat yoksa (S4-B22) şişmiş sayı kaydediliyor.

## 2. Bulgular

| ID | Tür | Önem | Bulgu | Kanıt | Etki |
|----|-----|------|-------|-------|------|
| S4-B01 | Hata | Yüksek | Mail bildiricisi `ok=false` olan her sonucu `Unhealthy` sayıyor. 429 `rate_limited`, `no_base_url` ve `needs_secret` sonuçları da, önceki durum `ok` ise "Siteniz düştü" maili gönderiyor; bir sonraki başarılı poll'da "tekrar sağlıklı" maili geliyor. KPI tarafı `unknown`/`needs_secret` durumlarını bilinçli olarak muaf tutuyor. | `app/Services/Mail/SiteHealthMailNotifier.php:19-21,37`; `app/Services/Agent/SiteAgentClient.php:68-75` (429 → `unknown`); `app/Services/Agent/SiteHealthEvaluator.php:28-30` | CMS throttle'ı müşteriye/operatöre sahte kesinti maili olarak yansıyor; alarm güvenilirliği düşüyor |
| S4-B02 | Eksik | Yüksek | Site durumu hesaba katılmadan alarm üretiliyor. Fan-out tüm siteleri tarıyor; `countsAsUnhealthy` stopped, deploying ve provisioning sitelerini de sayıyor; bildirici `status`'a bakmıyor. Tek başarısız poll mail için yeterli; flapping bastırma, eskalasyon ve bakım bayrağı yok. | `app/Jobs/DispatchSiteHealthChecksJob.php:19-27`; `SiteHealthEvaluator.php:14-21`; `SiteHealthMailNotifier.php:16-56`; `grep -rni "maintenance" app/Models/Site.php` → yok | Kapatılan site ve her deploy penceresi down/up mail çifti üretebiliyor; KPI şişiyor. Önceki: Öneri-B6 Kısmen, D2 Yapılmadı |
| S4-B03 | Hata | Yüksek | Başarısız poll payload'u tamamen yeniden yazıyor. `failure()` özeti yalnız `ok/status/reason/http_status` taşıdığı için `deamon_version` ve `active_theme_id` siliniyor. Sürüm kapısı "sürüm yoksa engelleme" kuralıyla çalıştığından tek bir timeout ile `alpha/beta → main` kapısı aşılabiliyor. Tema kapıları siteyi "eski" sayıyor. | `app/Services/Agent/SiteHealthChecker.php:27`; `AgentHealthResult.php:95-100`; `app/Services/Sites/ChannelSwitcher.php:336-338`; `app/Models/Site.php:672-676,686-695` | Sürüm kapısı sessizce devre dışı kalıyor; `cms=outdated` filtresi ve tema rollout kararı bayat/boş veriye dayanıyor |
| S4-B04 | Hata | Yüksek | "Doğrulanmış secret" tanımı `http_status=200` koşulunu da kabul ediyor. HTML 200 dönen `agent_not_registered` (ve 200 dönen `proxy_fallback`) siteleri Agent gizli anahtarı kartında **ok** sayılıyor. | `app/Models/Site.php:1150-1156` (`verifiedAgentSecret`), `:1194-1206` (`agentSecretIsVerified`); `SiteAgentClient.php:110-118` (failure'a 200 geçiyor) | Filo kartı, CMS'te secret tanımlı olmayan siteleri "tamam" gösteriyor; inject iş listesinden düşüyorlar |
| S4-B05 | Hata | Yüksek | Rotate sırasında yeni secret, Coolify env yazımından **önce** DB'ye kaydediliyor. Coolify hata verirse istisna fırlıyor ama DB'deki secret değişmiş kalıyor. Plane yeni secret ile imzalarken CMS eskisiyle çalışıyor: tüm agent çağrıları 401, gelen mail proxy'si de 401 dönüyor. Bu yol testsiz. | `app/Services/Sites/SiteAgentSecretInjector.php:27-38`; `tests/Feature/Sites/AgentSecretInjectTest.php:28,89,135` (yalnız mutlu yol + viewer) | Tek tıkla bir sitenin agent ve mail yüzeyi kopuyor; kurtarmak için yeniden inject gerekiyor |
| S4-B06 | Eksik | Yüksek | Inject/rotate Coolify env'i yazıyor ama uygulamayı restart ya da redeploy etmiyor. CMS yeni değeri bir sonraki deploy'a kadar görmüyor; ilk inject'te agent route'ları hiç kaydedilmiyor (`agent_not_registered`). Grace süresi ve çift secret yok. Rotate ardından gelen poll `bad_signature` → down maili üretiyor. | `SiteAgentSecretInjector.php:32-55` (restart yok); `lang/tr/sites.php:644` ("sağlık kontrolü bozulur"); `ControlPlaneAgentSignature.php:20-29`; `EnsureSiteMailSignature.php:40` (tek secret) | Rotasyon her seferinde planlı bir kesinti yaratıyor; operatör bu yüzden rotasyondan kaçınıyor. Önceki: Boşluk-B9 doğrulandı, Öneri-B4 Yapılmadı |
| S4-B07 | Hata | Orta | Her 401/403 gövdeye bakılmadan `bad_signature` sayılıyor. Cloudflare WAF/bot challenge'ının 403 HTML yanıtı da "imza reddedildi" olarak görünüyor. App-health bu durumda yalnız `check_health` öneriyor; Coolify'daki `CONTROL_PLANE_AGENT_SECRET` değerinin Plane secret'ı ile **eşleşip eşleşmediğini** değil, yalnız varlığını kontrol ediyor. | `SiteAgentClient.php:77-85`; `app/Services/Sites/SiteAppHealthInspector.php:150-151,305-310`; agent trafiği public domain üzerinden gidiyor (`Site.php:560-577`) | Operatör gereksiz yere rotate ediyor (bkz. S4-B06); gerçek secret kayması teşhis edilmiyor |
| S4-B08 | Hata | Orta | `ConnectionException` altındaki her hata (DNS çözümleme, TLS/sertifika, bağlantı reddi, gerçek timeout) `timeout` olarak sınıflanıyor. | `SiteAgentClient.php:50-56` | Sertifika süresi dolmuş ya da DNS'i kaymış bir site "zaman aşımı" diye görünüyor; yanlış runbook satırı açılıyor |
| S4-B09 | Risk | Orta | Sessiz varsayılan: herhangi bir 2xx JSON (`{}`, `[]`, başka bir uygulamanın JSON'u) `queue_ok` alanı olmadığı için **ok** sayılıyor; `deamon_version` zorunlu değil. | `app/Services/Agent/AgentHealthResult.php:43-46` | Yanlış hedefe (ör. yanlış `agent_base_url`) giden poll "sağlıklı" görünüyor; secret "verified" sayılıyor |
| S4-B10 | Risk | Orta | Health job zaman bütçesi aşılabiliyor. Job timeout 30 sn; zincirde agent GET (10 sn + ≤5 sn 429 bekleme + 10 sn), identity POST (aynı bütçe), Coolify restart ve alıcı başına 30 sn timeout'lu senkron SMTP gönderimi (alıcılar: site alıcısı + en fazla 20 kullanıcı) var. Kill, health save'den sonra ama notify-status save'den önce gelirse mail tekrarlanıyor ya da kayboluyor. `send()` false döndüğünde de durum ilerletiliyor, yani başarısız bir alarm tekrar denenmiyor. | `app/Jobs/CheckSiteHealthJob.php:15-19`; `SiteHealthChecker.php:38-49`; `app/Services/Mail/PlatformOpsMailer.php:104-108,148`; `SiteHealthMailNotifier.php:22,53-55` | Kritik anda (SMTP yavaş, CMS 429) alarm ya tekrarlıyor ya kayboluyor |
| S4-B11 | Perf | Orta | Fan-out her 10 dakikada tüm siteler için `CheckSiteHealthJob` ve `InspectSiteAppHealthJob` kuyruğa atıyor (inspect başına en az 2 Coolify API çağrısı). Hepsi tek worker'lı default kuyruğa gidiyor; tempo ayarı yok. Yavaş siteler operatör işlerini (deploy/tema) bekletiyor. Kuyruk birikimi 240 sn'yi aşarsa `uniqueFor` kilidi düşüyor ve aynı site için ikinci job kuyruğa giriyor. | `DispatchSiteHealthChecksJob.php:19-27`; `app/Jobs/InspectSiteAppHealthJob.php:19`; `SiteAppHealthInspector.php:41,47`; `docker/supervisor/supervisord.conf:27` (tek `queue:work`); `grep -rn onQueue app/Jobs` → boş | Coolify host yükü artıyor; olay anında (çok sayıda timeout) kuyruk dakikalarca tıkanıyor |
| S4-B12 | Borç | Orta | Dört ayrı "bayat" tanımı var: rozet 2×poll (20 dk), evaluator 30 dk, liste filtresi `stale=stale` 24 saat. Live probe rozeti de poll penceresini kullanıyor; ancak probe manuel olduğu için 20 dakika sonra hep "bayat" görünüyor. `OpsFreshness` yorumu "live probes ride the same job" diyor; bu doğru değil. | `app/Support/OpsFreshness.php:17-29`; `config/ops.php:113`; `app/Models/Site.php:868,1032-1036`; `resources/views/ops/sites/_cell.blade.php:165` | Aynı site aynı anda bir yerde taze, başka bir yerde bayat görünüyor; operatör rozete güvenmiyor |
| S4-B13 | Eksik | Orta | Sağlık geçmişi yok. Down/up geçişleri audit'e yazılmıyor, "ne zamandan beri düşük" (`failing_since`) ve ardışık hata sayacı tutulmuyor, gönderilen bildirimlerin kaydı yok. Filo snapshot'ı günde tek an (23:50) ölçüyor; gün içindeki kesinti izsiz kalıyor. | `grep -rn "'site.health" app` → boş; `SiteHealthMailNotifier.php:53-54` (yalnız son durum); `app/Services/Sites/SiteListSummary.php:40-48`; `database/migrations/2026_09_23_190000_create_fleet_daily_snapshots_table.php:13-21` | SLA/uptime raporu çıkarılamıyor; olay sonrası analiz yapılamıyor. Önceki: D5 Yapılmadı |
| S4-B14 | Risk | Orta | `fixAll` fix listesini başta bir kez hesaplıyor ve her fix'ten sonra canlı reinspect yapıyor (spec "site başına bir inspect" diyor). `redeploy` sonrasında hemen `check_health` çalışıyor. Tek-site `fix` endpoint'i fix'in gerçekten gerekli olup olmadığını denetlemiyor. Tekil `redeploy`, `stop_then_redeploy` ve `rollback_last_good` düğmelerinde onay yok; `8484d37` ile gelen danger-confirm deseni burada uygulanmamış. | `app/Services/Sites/SiteAppHealthFixer.php:166,169-182`; `docs/superpowers/specs/2026-09-11-bulk-app-health-fixes-design.md` "call inspect once"; `app/Http/Controllers/Ops/SiteAppHealthController.php:34-41`; `resources/views/ops/sites/_app-health.blade.php:29-34` | Gereksiz Coolify çağrıları yapılıyor; bayat UI'dan tek tıkla site durdurulabiliyor ya da pinlenebiliyor |
| S4-B15 | Risk | Düşük | 429 retry, ilk denemede üretilen nonce ve timestamp ile aynı imzayı tekrar gönderiyor. CMS nonce'u throttle'dan önce saklıyorsa ikinci deneme 401 alır ve `bad_signature` olarak sınıflanır. **Doğrulanmadı**: CMS middleware sırasına bakılmalı ya da her denemede nonce'un farklı olduğunu kontrol eden bir `Http::fake` testi yazılmalı. | `SiteAgentClient.php:42-49` (imza closure dışında); `app/Services/Agent/Concerns/RetriesThrottledAgentRequests.php:21-51` | Rollout sırasında sahte `bad_signature` oluşabilir |
| S4-B16 | Güvenlik | Düşük | Gelen HMAC'te (mail proxy) nonce kontrolü `Cache::has` → `Cache::put` şeklinde, atomik değil (TOCTOU). TTL alt sınırı 30 sn ve skew'den bağımsız; `nonce_ttl < 2×skew` yapılandırması replay penceresi açıyor. Ayrıntı S9'da. | `app/Http/Middleware/EnsureSiteMailSignature.php:35-45,57,79` | Eşzamanlı replay dar bir pencerede mümkün |
| S4-B17 | Borç | Düşük | `deamon_version` düz kolon değil, yalnız JSON içinde tutuluyor. `outdated` filtresi her istekte tüm siteleri PHP'ye yükleyip karşılaştırıyor (ADR-10'un ruhuna aykırı). | `app/Models/Site.php:1057` (`self::query()->get(...)` tüm filo), `:1089-1105` | Filo büyüdükçe liste yavaşlıyor; sürüm histogramı yapılamıyor. Önceki: Boşluk-B7 Kısmen |
| S4-B18 | Risk | Düşük | Identity heal, isimler farklı olduğu sürece her poll'da POST atıyor ve bunun için throttle yok. CMS ismi normalize ederse (uzunluk, entity) sonsuz döngü oluşur; `changed=false` ise audit de yazılmaz. **Doğrulanmadı** (CMS normalizasyon kuralı). | `app/Services/Sites/SiteIdentityPusher.php:36,53-66` | Her 10 dakikada gereksiz agent POST'u ve CMS 429 riski |
| S4-B19 | Eksik | Düşük | Live probe yalnız manuel çalışıyor: zamanlanmış değil, siteleri sırayla 8 sn timeout ile yokluyor, redirect'leri takip ediyor (SSRF → S9) ve sertifika bitiş tarihi okumuyor. | `app/Services/Sites/SiteLiveProbe.php:40-52`; `routes/console.php:16-42` (probe yok) | Public erişilebilirlik ve TLS süresi izlenmiyor. Önceki: Boşluk-B10 / Öneri-B8 Yapılmadı |
| S4-B20 | Borç | Düşük | Kritik davranışların testi yok: bildirici geçişleri, rotate hatası, 403 HTML, boş JSON, HTML 200 ile verified sayılması. | `grep -rln "SITE_DOWN\|last_health_notify_status" tests` → yalnız mail override/unsubscribe testleri | S4-B01, B04, B05 ve B09 regresyonları yakalanamaz |
| S4-B21 | Borç | Düşük | `health()` üstündeki docblock `sendWithRetry` parametresini anlatıyor (yanlış yerde). CMS min-sürüm eşikleri dağınık: kodda iki sabit var, diğerleri yalnız dokümanda (identity 1.2.27, core_theme 1.2.30, publish 1.2.16). | `SiteAgentClient.php:19-25`; `app/Services/Agent/ControlPlaneAgentContract.php:18,89,95`; `docs/modules/agent-client.md` | Yeni uç noktada sürüm kapısı unutuluyor; yetenek haritası yok |
| S4-B22 | Eksik | Orta | Plane kendi poller'ını izlemiyor. Worker ya da scheduler ölürse `last_health_at` ilerlemiyor; 30 dk sonra secret'ı olan her site "stale → unhealthy" görünüyor ama ne banner ne mail üretiliyor. Snapshot da bu şişmiş sayıyı kaydediyor. | `app/Services/Sites/SiteFilterVerdict.php:91-102` (zamana bağlı stale); `bootstrap/app.php` yalnız `/up`; `routes/console.php` heartbeat yok | Tüm filo yanlış alarm veriyor ya da gerçek kesinti fark edilmiyor. Önceki: D6 Yapılmadı |

**Önem kırılımı:** Kritik 0 · Yüksek 6 · Orta 9 · Düşük 7 (toplam 22).

## 3. İyileştirme ve güncelleme önerileri

Puan = Etki×2 − Risk + (S=3, M=2, L=1, XL=0).

| ID | Öneri | Çözdüğü bulgu | Etki | Efor | Risk | Puan | Kabul kriteri |
|----|-------|---------------|------|------|------|------|---------------|
| S4-O01 | Bildiricide yalnız `status=unhealthy` sonucu "down" sayılsın; `unknown`/`needs_secret` önceki durumu değiştirmesin | B01 | 4 | S | 1 | **10** | Test: ok → 429 → ok dizisi 0 mail üretir; ok → timeout 1 down maili üretir |
| S4-O02 | Sağlık durum makinesi: `health_fail_streak`, `health_failing_since`; N-ardışık eşik (varsayılan 2). `stopped/deploying/provisioning/draft` ile `maintenance_until` durumunda poll atlanır ya da alarm bastırılır. Geçişler audit'e `site.health_down/up` olarak yazılır | B02, B13 (kısmi) | 5 | M | 2 | **10** | Stopped site 0 mail; deploy sırasındaki tek timeout 0 mail; 2 ardışık hata 1 mail; audit satırı var; KPI stopped'ı saymıyor |
| S4-O05 | Rotate sırası: yeni değeri önce Coolify'a yaz, başarı gelirse DB'ye kaydet; hata olursa DB değişmesin | B05 | 4 | S | 1 | **10** | `Http::fake` 500 → `agent_secret_encrypted` değişmez; hata flash'ı gösterilir |
| S4-O16 | Plane heartbeat: scheduler dakikada bir `ops.heartbeat` yazsın. Heartbeat > 2×poll ise fleet'te banner göster, stale kaynaklı unhealthy'yi "Plane poller durdu" olarak ayrı tut, isteğe bağlı mail gönder | B22 | 4 | S | 1 | **10** | Heartbeat 25 dk eskiyse banner görünür, stale siteler unhealthy KPI'ya eklenmez; test mevcut |
| S4-O03 | Son bilinen değerleri koru: `deamon_version`, `deamon_version_at`, `last_health_ok_at` düz kolon olsun; hata poll'u bunları silmesin. Sürüm kapısı son bilinen değeri okusun | B03, B17 | 4 | M | 2 | **8** | ok(1.2.33) → timeout sonrası `reportedDeamonVersion()` = 1.2.33; `cms=outdated` SQL ile çalışır |
| S4-O04 | Verified secret = `status=ok` (JSON 2xx). `agent_not_registered` ve `proxy_fallback` "unverified" sayılsın | B04 | 3 | S | 1 | **8** | HTML 200 site `unverified` sayacına girer; kart ve filtre aynı sayıyı verir |
| S4-O07 | Sınıflandırmayı genişlet: `dns_error`, `tls_error`, `connection_refused`, `timeout` (cURL errno); JSON olmayan 403 için `edge_blocked`; `bad_signature` yalnız JSON 401/403 için | B07, B08 | 3 | S | 1 | **8** | Her sınıf için bir test; operatör metni (tr/en) mevcut; `edge_blocked` rotate önermiyor |
| S4-O12 | `site_health_events` tablosu: from→to, reason, at, bildirilen kanallar. Site detayında 30 günlük şerit; prune süresi 90 gün | B13 (D5) | 4 | M | 2 | **8** | Her geçiş 1 satır yazar; detayda "düşük: 14:20'den beri" görünür; prune komutu testli |
| S4-O18 | Eksik testler: bildirici geçişleri, rotate hatası, 403 HTML, `{}` payload, HTML 200 verified | B20 | 3 | S | 1 | **8** | 5 yeni test; S4-O01/O04/O05 değişiklikleri bunlarla korunuyor |
| S4-O08 | Payload şema kontrolü: 2xx JSON'da `deamon_version` string değilse `schema_invalid` (unhealthy) döndür | B09 | 3 | S | 2 | **7** | `{}` → unhealthy + `schema_invalid`; gerçek CMS fixture'ı → ok |
| S4-O13 | `fixAll`: her adımdan sonra ihtiyacı yeniden hesapla, sonda tek live reinspect yap; deploy tetikleyen fix sonrasında `check_health` atlansın. Tek-site fix "gerekli mi" kontrolü yapsın. Tekil yıkıcı fix'lere danger-confirm eklensin | B14 | 3 | S | 2 | **7** | fixAll'da Coolify `getApp` ≤ 2 çağrı; gereksiz fix 422 döner; 3 düğmede `data-confirm-danger` var |
| S4-O06 | Grace'li rotasyon (Plane tarafı): `agent_secret_previous_encrypted` + `rotated_at`. Grace süresince giden çağrı 401 alırsa önceki secret ile tekrar denesin, gelen mail proxy iki secret'ı da kabul etsin. Env yazımından sonra Coolify restart yapılsın; grace boyunca alarm bastırılsın | B06 | 4 | M | 3 | **7** | Rotate → restart → ilk poll yeni secret ile 200; grace içinde eski secret'lı CMS isteği 200; grace sonunda önceki secret silinir |
| S4-O09 | Health job'undan yan etkileri çıkar: mail gönderimi (`afterCommit` + outbox), identity push ve core-theme restart ayrı kuyruk job'larına taşınsın; `send()` false dönerse durum ilerlemesin | B10 | 3 | M | 2 | **6** | Check job p95 < 15 sn; SMTP hatası sonrası bir sonraki döngüde mail tekrar denenir; tekrar mail yok |
| S4-O10 | Ayrı `health` kuyruğu + supervisor programı; inspect saatlik (ve olay tetikli), `uniqueFor` poll aralığına eşit | B11 | 3 | M | 2 | **6** | Health backlog'u deploy job'unu geciktirmez (test: kuyruk adı); Coolify çağrıları/saat en az 6 kat azalır |
| S4-O11 | Tek tazelik politikası: tüm eşikler `OpsFreshness` + config'te; live probe'un kendi eşiği olsun; 24 saatlik sabit config'e taşınsın | B12 | 2 | S | 1 | **6** | Aynı site rozet, filtre ve KPI'da aynı "bayat" kararını verir (test) |
| S4-O14 | Retry'de her denemede yeni nonce ve timestamp ile tekrar imzala | B15 | 2 | S | 1 | **6** | Test: iki denemenin `X-Deamon-Nonce` değerleri farklı |
| S4-O15 | Gelen nonce için `Cache::add` (atomik) ve `nonce_ttl ≥ 2×skew` zorlaması | B16 | 2 | S | 1 | **6** | Aynı nonce ile eşzamanlı ikinci istek 401 alır; yanlış config boot'ta reddedilir |
| S4-O19 | Zamanlanmış live probe (saatlik, tempolu) + TLS `notAfter` yakalama; SSRF guard (S9) önkoşul | B19 (B10/B8) | 3 | M | 2 | **6** | `last_live_checked_at` ≤ 70 dk; sertifika bitimi < 14 gün ise dikkat kartına düşer |
| S4-O20 | Ops-only webhook kanalı (Slack/Telegram uyumlu JSON). Müşteri mailleri ile ops alarmları ayrılsın | B02 (D1) | 3 | M | 2 | **6** | Kanal ayarı mevcut; down/up olayı webhook'a gider; müşteri alıcısı ops alarmını almaz (ayar) |
| S4-O17 | Identity heal'e site başına throttle (`Cache::add`, 6 saat); CMS farklı isim yankılarsa log yaz | B18 | 1 | S | 1 | **4** | Aynı fark için 6 saatte en fazla 1 POST |
| S4-O21 | `ControlPlaneAgentContract` içinde yetenek → min-sürüm haritası; docblock düzeltmesi | B21 | 1 | S | 1 | **4** | Tüm CMS uç noktaları haritada; tek `supports(Site, capability)` yardımcısı |

Sıra önerisi: O01 → O05 → O02 → O16 → O03/O04 → O07/O18 (hepsi S/M, birbirinden bağımsız). O06 ve O12, O02'deki durum makinesine bağlı.

## 4. Otonomi fırsatları

Kapalı döngü (L5) önerisi: **tespit** (poll + inspect) → **sınıfla** (S4-O07 nedenleri) → **politika** (izinli fix listesi) → **eylem** → **doğrula** (1–2 poll) → **başarısızsa eskale et** → **bildir + audit**. Önkoşullar: S4-O02 (durum makinesi), S4-O09 (job ayrımı), S4-O12 (olay kaydı), S4-O16 (heartbeat).

İzinli (otomatik) fix'ler: `restart_app`, `inject_secret` (rotate=false), `sync_deployments` (salt okuma), `check_health`. **Otomatik yasak (app-health için):** `redeploy`, `stop_then_redeploy`, `rollback_last_good`, `follow_head`, `migrate_compose`, secret içeren `sync_env`. Tek kill switch: `OPS_AUTOREMEDIATE=false`.

| Akış | Bugün | Hedef | Tetik | Ön koşul | Güvenli otomatik adım | Doğrulama | Geri alma | Guardrail/bütçe | Kill switch | Audit | Bildirim |
|------|-------|-------|-------|----------|----------------------|-----------|-----------|-----------------|-------------|-------|----------|
| Down/up alarmı | L1 (kenar maili, `SiteHealthMailNotifier.php:21`) | L4 | 2 ardışık `unhealthy` | status ∈ {active}; bakım yok; deploy son 10 dk'da yok; heartbeat taze | Alarm üret + teşhis ekle (neden, son sürüm, app issue'lar) | 1 ok poll → up | — (salt bildirim) | Site başına 30 dk'da 1 tekrar; fleet'te 10 dk içinde 5'ten fazla site düşerse tek toplu alarm | `OPS_HEALTH_ALERTS=false` | `site.health_down/up` | Mail + ops webhook (O20) |
| Konteyner düştü (`proxy_fallback` / `app_not_running`) → restart | L3 (`SiteAppHealthFixer.php:302-314`) | L4 | 2 ardışık `proxy_fallback` ya da Coolify `exited/degraded` | status=active; deploy yok; Coolify erişilebilir; son 6 saatte otomatik restart yok | `restartApplication` | 2 poll içinde `ok`; olmazsa eskale et | Yok (restart idempotent); başarısızlıkta otomasyonu site için kapat, insana devret | Site 1/6 sa; filo 5/saat | `OPS_AUTO_RESTART_APP=false` | `site.app_auto_restarted` / `..._failed` | Mail + webhook ("otomatik restart denendi") |
| `core_theme_stale` → restart | L4 (`CoreThemeHealer.php:23-62`; doğrulama ve bildirim yok) | L5 | `core_theme_in_sync=false` | Mevcut koşullar + status≠deploying | Mevcut restart | Sonraki poll `in_sync=true`; değilse issue "yakınsamadı" olarak işaretlensin | — | Mevcut 6 saat penceresi | `OPS_CORE_THEME_AUTO_RESTART` (var) | `site.core_theme_restarted` (var) + `..._unresolved` | Pencere içinde 2. başarısızlıkta mail |
| Plane'de secret var, Coolify env'de yok (`missing_env: CONTROL_PLANE_AGENT_SECRET`) | L3 | L4 | Inspect bu issue'yu 2 kez görür | status=active; deploy yok | `inject(rotate:false)` + restart | Sonraki poll 200 JSON | Env'i önceki değere geri yaz (Coolify'dan okunan) | Site 1/gün | `OPS_AUTOREMEDIATE` | `site.agent_secret_injected` (var) + `origin=auto` | Mail |
| `bad_signature` (JSON 401) | L2 (yalnız `check_health` CTA) | L3 → L4 | JSON 401 ×2 | Coolify env okunabiliyor | Coolify env değerinin hash'ini Plane secret'ıyla karşılaştır; farklıysa "mevcut secret'ı yeniden yaz + restart" (L3 düğmesi; L4 opt-in) | 200 JSON | Env'i eski değerine geri yaz | Site 1/gün | `OPS_AUTOREMEDIATE` | `site.agent_secret_resynced` | Mail |
| Secret rotasyonu | L3 (tek adım, kesintili) | L5 | Operatör ya da takvim (ör. 180 gün) | status=active; son deploy başarılı | Grace'li rotate (O06) → restart | Yeni secret ile 200; grace içinde eski secret reddedilmiyor | Doğrulama başarısızsa önceki secret'ı geri yükle + env'i geri yaz | Filo 3 site/saat | `OPS_SECRET_ROTATION=false` | `site.agent_secret_rotated` + `..._rolled_back` | Özet mail |
| Bayat deploy satırı (`sync_deployments`) | L3 | L4 | `deploy_failed` issue + Coolify'da son deploy `finished` | Coolify erişilebilir | `CoolifyDeploymentSync::sync` (salt okuma) | Issue kalkar | — | Site 1/saat | `OPS_AUTOREMEDIATE` | `site.deployments_synced` | Yok (sessiz) |
| Plane poller sağlığı | L0 | L4 | Heartbeat > 2×poll | — | Banner göster, stale verdict'i bastır | Heartbeat tazelenir | — | — | — | `ops.poller_stalled/resumed` | Mail (global alıcı) |
| Günlük snapshot eksik gün | L0 | L4 | 23:55'te bugünün satırı yok | Heartbeat taze | `ops:snapshot-fleet` (upsert, idempotent) | Satır var | — | Günde 1 | — | Komut çıktısı | Yok |

## 5. Yeni özellik fikirleri (bu alana özgü)

- Site detayında **sağlık zaman şeridi** (30 gün, olay tablosu S4-O12 üzerinden) ve liste kolonu "düşük süresi"; unhealthy kartı süreye göre sıralı (bugün isme göre, `FleetDashboardKpis.php:188`).
- **Bakım modu / sessize alma**: `maintenance_until` + neden; toplu uygulanabilir (Öneri-B6'nın tamamı).
- **Agent gecikme metriği**: `last_health_latency_ms` + p95 rozeti; yavaşlayan CMS'i düşmeden önce görmek için.
- **Sürüm histogramı kartı** (Öneri-B3): S4-O03'teki düz kolon ile SQL `GROUP BY`.
- **TLS bitiş kolonu** ve dikkat kartı (Öneri-B8, S4-O19).
- **Haftalık ops özeti** (A9): uptime %, en çok düşen 5 site, otomatik düzeltme sayısı.
- **Dahili status sayfası** (D3): yalnız operatörlere, olay tablosundan üretilen.
- **"Neden sağlıksız?" açıklayıcısı**: verdict'e katkı veren sinyaller (status=error / stale / reason) tek satırda.

## 6. CMS handoff

| Konu | CMS tarafında gereken | İlgili |
|------|----------------------|--------|
| Grace'li rotasyon | Opsiyonel `CONTROL_PLANE_AGENT_SECRET_PREVIOUS` kabulü (Plane-only fallback ile de çalışır) | S4-O06, Öneri-B4 |
| Env yeniden yükleme | Secret değişiminin restart gerektirdiğinin teyidi; mümkünse restart'sız secret okuma | S4-B06 |
| 401 hata şekli | İmza reddinde sabit JSON (`error: bad_signature`), WAF 403'ünden ayırt edilebilir olsun | S4-O07 |
| Nonce ↔ throttle sırası | Throttle'ın nonce saklamadan önce çalıştığının teyidi | S4-B15 |
| Health payload genişletme | `contract_version`/`capabilities[]`, kuyruk derinliği, failed job sayısı, son schedule zamanı | S4-O21, F4 |
| Yanıt bütünlüğü | Opsiyonel yanıt imzası (aynı HMAC ile gövde); sahte "ok" riskine karşı | S4-B09 |
| İsim normalizasyonu | `site/identity` isimde hangi dönüşümleri yapıyor; yankı kuralı | S4-B18 |
| Health throttle | İmzalı Plane health isteklerinin throttle dışında tutulması (429 gürültüsü) | S4-B01 |

## 7. Açık sorular

| # | Soru | Önerilen default |
|---|------|------------------|
| 1 | Down/up mailleri müşteri alıcısına (`platform_mail_recipient`) da gitsin mi? | Hayır, N-ardışık eşik ve bastırma gelene kadar yalnız ops alıcıları; müşteri için ayrı açık seçim |
| 2 | N-ardışık eşik kaç olsun? | 2 (10 dk poll ile ≈ 20 dk) |
| 3 | Stopped siteler poll edilsin mi? | Agent poll'u ve inspect atlansın; haftada bir live probe yeterli |
| 4 | Otomatik `restart_app` (L4) varsayılan açık mı? | İlk sürümde kapalı (`OPS_AUTO_RESTART_APP=false`), 2 hafta audit izlendikten sonra açılsın |
| 5 | Grace'li rotasyon Plane-only mi yoksa CMS dual-secret mi? | Plane-only (giden çağrıda önceki secret'a düş, gelen mail proxy'de iki secret'ı da kabul et); CMS desteği opsiyonel |
| 6 | Inspect sıklığı? | Saatlik + olay tetikli (catalog değişimi, deploy bitişi, manuel) |
| 7 | Sağlık olayı saklama süresi? | `site_health_events` 90 gün; `fleet_daily_snapshots` 400 gün (prune, Boşluk-B4) |
| 8 | Ops webhook kanalı kapsam içi mi? | Evet (Plane v1 iç operasyon); Mailcow/related-infra ile ilgisi yok |
| 9 | Deploy teşhisinin `AUTO_SAFE` listesi (`redeploy`, `bind_domains` dahil) ile app-health otomatik politikası tek listede birleşsin mi? | **S3 ile hizalanacak** (S3 raporu `DeploymentFailureClassifier::AUTO_SAFE` konusunu işliyor); bu rapor app-health bağlamında `redeploy`/`bind_domains` otomatiğini önermiyor |

_Referans doğrulaması: bu dosyadaki 94 benzersiz `dosya:satır` referansının tamamı 2026-09-24'te HEAD 8484d37 üzerinde `sed -n` ile açılıp kontrol edildi; 10 referans düzeltildi._
