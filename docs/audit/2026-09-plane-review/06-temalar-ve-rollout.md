# 06 — Temalar ve rollout

**Tarih:** 2026-09-24 · **HEAD:** 8484d37 · **Hazırlayan:** S5 — Temalar ve rollout denetçisi (Dalga 1)
**Kapsam:** M4 — `app/Services/Themes/**`, `app/Services/GitHub/**` (DeamonGitConnectionService hariç), `app/Services/Agent/{CoreThemeHealer,ThemeAgentResult}`, `ThemeController`, `SiteThemeController`, `ThemeGitConnectionController`, `GithubSettingsController`, `Webhooks/GitHubWebhookController` + `VerifyGitHubWebhook` + `GitHubWebhookSignature`, `Theme{Install,Update,SyncAfterDeploy}Job`, `ops:sync-theme-catalog`, tema modelleri/policy'leri, `resources/views/ops/themes/**`, `sites/_themes`, `public/js/theme-git.js`, testler.
**Kapsam dışı:** CMS tema agent'ı ve installer'ı (`codron-co/deamon`), Coolify deploy akışı (M2), health poll'un tema dışı kısmı (M3), müşteri tema ZIP upload (kapsam dışı — bilinçli karar).

Kanıt yöntemi: kod okuma + hedefli test (`php artisan test --compact --filter='Theme|GitHubWebhook|GithubSettings'` → **112 geçti / 0 başarısız, 647 assertion, 54 s**) + repo dışında (`%TEMP%\plane-audit-2026-09\probe-s5\S5ThemeProbeTest.php`) yazılıp repo'nun `phpunit.xml`'i ile çalıştırılan 9 kanıt testi (**9/9 geçti** = aşağıdaki P1–P9 davranışları bugün gerçekten böyle). Repo'ya dosya yazılmadı (`git status` yalnız önceden var olan 2 değişiklik).

## Özet

- **Kritik — her branch push'u canlıya gidiyor.** GitHub push webhook'u hangi branch'e push edildiğine bakmadan katalog `latest_sha`'yı değiştiriyor. `ref=main` olan ve auto-update'i açık her kuruluma da o commit'i gönderiyor. `feature/wip` push'u canlı siteye kuruldu (P1). "Update to latest" da aynı SHA'yı kullanıyor.
- **Kritik — auto-update site düzenlemelerinin üstüne yazıyor.** CMS 1.2.32 kapısı yalnız manuel "Update" onay metninde var. Webhook yolu CMS < 1.2.32 sitelere de update gönderiyor (P6), yani sitenin düzenlediği tema dosyaları sessizce eziliyor.
- **Yüksek — A5 hâlâ Yapılmadı, geri alma da yarım.** Fan-out bütün opt-in sitelere 3'erli / 10 sn aralıkla gidiyor; kanal sırası, dalgalar arası sağlık kapısı ve durdurma yok. Storefront kontrolü geri alıyor (`baa9010`) ama döngüyü kapatmıyor: auto-update açık kalıyor, `error` satırları sonraki push'u yine alıyor (P9), geri alma sonrası tekrar kontrol yok. Yani L5 değil, korumasız L4.
- **Yüksek — kurulum durumu takılı kalabiliyor.** Assign sırasında activate başarısız olursa satır sonsuza dek `installing` kalıyor ve audit'e `install_succeeded` yazılıyor (P4). Clone token HTTP hatası da satırı `updating`'de bırakıyor ve audit yazılmıyor (P5). Job'larda `failed()` yok, `tries=1`. Smoke'un en kötü süresi (≈330 sn) job timeout'unun (120 sn) üstünde.
- **Yüksek — webhook'ta tekrar ve sıra koruması yok, manifest bayat.** `X-GitHub-Delivery` saklanmıyor (P3). Eski bir teslimatın yeniden gönderilmesi `latest_sha`'yı geri alıp fan-out yapıyor. Webhook `theme.json`'u yeniden okumadığı için `minimum_deamon_version` kapısı bayat veriyle çalışıyor. `installation` olayları işlenmiyor (P7); doküman işlendiğini söylüyor.
- **En değerli öneriler.** S5-O01 (yalnız default branch push'u; puan 12), S5-O06 (auto-update'te 1.2.32 kapısı; 12), S5-O05 (smoke hatasında o kurulumun auto-update'ini dondur; 10), S5-O08 (durum makinesini sağlamlaştır; 10), ardından S5-O04 (kademeli rollout planı = A5; 8).

## 1. Mevcut durum

### 1.1 Akışlar

```mermaid
flowchart LR
  subgraph Katalog
    A[Themes → Sync catalog / ops:sync-theme-catalog / OpsJob themes.catalog_sync] --> B[ThemeCatalogSync::sync]
    B --> C[her connected ThemeGitConnection: repos → theme.json + latestCommitSha(default_branch)]
    C --> D[themes upsert]
    B --> E[pruneOrphanThemes: connection_id NULL → delete → installations CASCADE]
  end
  subgraph Site
    F[POST sites/{site}/themes] --> G[ThemeRolloutService::assign: VisibilityGate, VersionGate, confirm, agent secret]
    G --> H[ThemeInstallJob → performInstall: install → activate? → sync? → guardStorefront]
    I[.../update] --> J[updateToLatest → ThemeUpdateJob → performUpdate(latest_sha) → guardStorefront]
    K[.../sync] --> L[syncNow (HTTP içinde, senkron) → deferSyncIfDeployOpen? → syncWithDataRepair → guardStorefront]
  end
  subgraph Webhook
    M[POST /webhooks/github] --> N[VerifyGitHubWebhook HMAC] --> O[GitHubWebhookHandler]
    O --> P[theme.latest_sha = payload.after]
    P --> Q[auto_update && status in active,error → updateToLatest(delay=floor(i/3)*10s), max 200]
  end
  L -. deploy açık .-> R[pending_sync_after_deploy=true] --> S[CoolifyDeploymentSync finished → ThemeSyncAfterDeployJob]
  H & J & L --> T[guardStorefront: ThemeSmokeCheck → 5xx? → health → core stale: CoreThemeHealer restart / değilse rollbackThemeFiles | rollbackLastSync]
```

### 1.2 Ana dosyalar

| Dosya | Sorumluluk | Satır | Test dosyası |
|-------|-----------|------:|--------------|
| `app/Services/Themes/ThemeRolloutService.php` | assign / update / sync / rollback / activate / smoke guard | 757 | `ThemeAssignTest`, `ThemeStorefrontGuardTest`, `ThemeSyncAfterDeployTest` |
| `app/Services/Themes/ThemeCatalogSync.php` | Katalog upsert + orphan prune | 210 | `ThemeCatalogSyncTest`, `ThemeGitConnectionTest` |
| `app/Services/Themes/ThemeGitConnectionService.php` | Manifest, installation, PAT, picker, disconnect | 290 | `ThemeGitConnectionTest` |
| `app/Services/Themes/ThemeSmokeCheck.php` / `ThemeSmokeResult.php` | Storefront GET, 5xx≠503 | 87 / 39 | `ThemeStorefrontGuardTest` |
| `app/Services/Themes/ThemeVersionGate.php` / `ThemeVisibilityGate.php` | `minimum_deamon_version` / görünürlük | 29 / 32 | `ThemeVisibilityGateTest`, webhook testi |
| `app/Services/GitHub/GitHubWebhookHandler.php` | Push/release → katalog + fan-out; CMS repo → env catalog | 218 | `GitHubWebhookTest` |
| `app/Services/GitHub/GitHubAppClient.php` / `GitHubAppJwt.php` / `ThemeManifest.php` | GitHub API, installation token, manifest | 539 / 34 / 81 | `GitHubAppManifestConversionTest`, dolaylı |
| `app/Services/Agent/CoreThemeHealer.php` / `ThemeAgentResult.php` | Bayat core tema → restart (6 saat pencere) / agent yanıtı | 68 / 138 | `ThemeStorefrontGuardTest`, `ThemeAgentResultTest` |
| `app/Http/Controllers/Ops/{Theme,SiteTheme,ThemeGitConnection,GithubSettings}Controller.php` | UI uçları | 259 / 169 / 178 / 28 | yukarıdakiler + `GithubSettingsTest` |
| `app/Http/Middleware/VerifyGitHubWebhook.php`, `app/Support/GitHubWebhookSignature.php` | HMAC | 32 / 61 | `GitHubWebhookTest` |
| `app/Jobs/Theme{Install,Update,SyncAfterDeploy}Job.php` | Kuyruk (`tries=1`, `timeout=120`, unique) | 48 / 47 / 86 | dolaylı (sync queue) |
| `app/Console/Commands/SyncThemeCatalogCommand.php` | `ops:sync-theme-catalog` (zamanlanmamış) | 36 | — |
| `resources/views/ops/sites/_themes.blade.php`, `ops/themes/{index,_region,show}.blade.php`, `themes/git/*`, `partials/connections` | UI | 286 / 123 / 103 / 550 / 209 / 128 | sayfa testleri |

### 1.3 Test kapsamı

| Davranış | Testli mi | Kanıt |
|----------|-----------|-------|
| Assign install→activate→sync, data-package onarımı, merge/overwrite, rollback'ler | Evet | `tests/Feature/Themes/ThemeAssignTest.php` (29 test) |
| Smoke → dosya/sync geri alma, core stale → restart | Evet | `ThemeStorefrontGuardTest.php` (8 test) |
| Deploy açıkken sync erteleme / bitince tek sefer / başarısız deploy temizler | Evet | `ThemeSyncAfterDeployTest.php` (5 test) |
| HMAC, boş secret, main push fan-out, version skip, selected repo | Evet | `GitHubWebhookTest.php` (8 test; hepsi `refs/heads/main`) |
| Default olmayan branch push, tag push, branch silme, `release` | **Hayır** | P1, P2, P8 davranışı testsiz |
| Aynı teslimatın tekrarı / sırasız teslimat | **Hayır** | P3 |
| `installation` / `installation_repositories` olayları | **Hayır** | P7 (kod da yok) |
| Activate hatası, job exception/timeout sonrası durum | **Hayır** | P4, P5 |
| Auto-update + CMS < 1.2.32 | **Hayır** | P6 |
| Smoke hatası sonrası auto-update davranışı | **Hayır** | P9 |
| Disconnect → prune → kurulumların silinmesi | Kısmen (tema silinir) | `ThemeCatalogSyncTest::test_sync_deletes_themes_orphaned_by_disconnected_git_source`; kurulum kaybı test edilmemiş |

### 1.4 Kuyruk parametreleri

| Job | `tries` | `timeout` | Unique | `failed()` | Not |
|-----|--------:|----------:|--------|------------|-----|
| `ThemeInstallJob` | 1 | 120 | `theme-install-{id}`, 300 sn | Yok | `app/Jobs/ThemeInstallJob.php:16-33` |
| `ThemeUpdateJob` | 1 | 120 | `theme-update-{id}`, 300 sn | Yok | `app/Jobs/ThemeUpdateJob.php:16-32`; webhook gecikmesi 660 sn'ye kadar çıkabiliyor |
| `ThemeSyncAfterDeployJob` | 1 | 120 | `theme-sync-after-deploy-{site}`, 120 sn | Yok | `app/Jobs/ThemeSyncAfterDeployJob.php:19-32`; hata yalnız `Log::warning` + `syncNow` audit'i |
| Katalog sync (`OpsBackgroundJob`) | OpsJob çatısı | — | — | OpsJob | `app/Services/Ops/OpsJobRunner.php:114-130`; audit yok |

Süre girdileri: agent `timeout_seconds=10`, `retry.max_attempts=2` (`config/ops.php:111,120`); GitHub `timeout=20` (`:54`); smoke `timeout=15` + `connectTimeout(5)`, başarısız yol 2 kez soruluyor (`ThemeSmokeCheck.php:44-47,71-72`); `smoke_paths` ≤ 10 + `/` (`docs/modules/theme-catalog.md` "at most 10").

### 1.5 Audit envanteri (tema)

`grep -rhoE "'theme\.[a-z_]+'" app` → 22 action (`theme.json` hariç): `assign_started`, `install_succeeded/failed`, `update_succeeded/failed`, `webhook_updated`, `update_skipped_version`, `activated`, `activate_failed`, `sync_succeeded/failed`, `sync_rollback_succeeded/failed`, `files_rollback_succeeded/failed`, `data_installed`, `data_install_failed`, `smoke_failed`, `auto_update_changed`, `updated`, `access_granted/revoked`. Ek olarak `site.core_theme_restarted/_restart_failed` (`CoreThemeHealer.php:44-58`). Audit'siz akışlar S5-B15'te.

### 1.6 Kanıt testleri (repo dışı, 9/9 geçti)

| Probe | Doğrulanan davranış | Bulgu |
|-------|---------------------|-------|
| P1 | `refs/heads/feature/wip` push'u → `latest_sha=featuresha`, `ref=main` kurulum `pinned_sha=featuresha` | B01 |
| P2 | `release` (`target_commitish:"main"`) → `latest_sha="main"` | B02 |
| P3 | Aynı gövde + aynı `X-GitHub-Delivery` iki kez → 2 agent `themes/update` çağrısı | B03 |
| P4 | install ok + activate 422 → status `installing`, `install_succeeded` var, `activate_failed` yok | B08 |
| P5 | `access_tokens` 502 → `GitHubApiException`, status `updating`, tema audit'i 0 | B09 |
| P6 | CMS `1.2.20` + auto-update → `themes/update` gönderildi | B06 |
| P7 | `installation` `deleted` → `updated:false`, bağlantı `connected` | B11 |
| P8 | Branch silme push'u (`after=000…`) → `fanout:1` | B14 |
| P9 | Smoke 500 → geri alındı (`pinned_sha=good`), `auto_update` hâlâ 1; ikinci kötü push yine `fanout:1` | B05 |

Komut: `php vendor/bin/phpunit --configuration phpunit.xml --testdox %TEMP%/plane-audit-2026-09/probe-s5/S5ThemeProbeTest.php` → `OK (9 tests, 32 assertions)`. (Geçici prob; repo'ya arşivlenmedi.)

## 2. Bulgular

| ID | Tür | Önem | Bulgu | Kanıt | Etki |
|----|-----|------|-------|-------|------|
| S5-B01 | Hata | **Kritik** | Push hangi branch'e yapılırsa yapılsın katalog `latest_sha` değişiyor. `refMatchesPush`, kurulum ref'i `default_ref`'e eşitse joker gibi davranıyor. `feature/wip` push'u `ref=main` + auto-update kurulumuna kuruldu. | `app/Services/GitHub/GitHubWebhookHandler.php:57-67` (ref kontrolü yok), `:203-216`, özellikle `:215` (`$installationRef === $theme->default_ref`); `app/Services/Themes/ThemeRolloutService.php:640-641` (update `latest_sha` gönderir); probe **P1** | Tamamlanmamış tema kodu, auto-update açık bütün canlı sitelere gidiyor. Manuel "Update to latest" de aynı SHA'yı kuruyor. |
| S5-B02 | Hata | Yüksek | `release` olayında `target_commitish` (genelde branch adı) `latest_sha`'ya yazılıyor. | `GitHubWebhookHandler.php:183`; probe **P2** (`latest_sha === 'main'`) | "Behind catalog" hesabı bozuluyor (`SiteThemeInstallation.php:69-79`). Update, agent'a `sha:"main"` gönderiyor; CMS'in buna tepkisi Doğrulanmadı. |
| S5-B03 | Güvenlik/Risk | Yüksek | Teslimat idempotency'si ve sıra koruması yok. `X-GitHub-Delivery` saklanmıyor, `before` alanı kontrol edilmiyor. GitHub imzasında zaman damgası da yok. | `grep -rn "X-GitHub-Delivery" app` boş; `GitHubWebhookHandler.php:60-62`; probe **P3** (aynı teslimat → 2 agent çağrısı) | GitHub UI'dan eski bir push'u "Redeliver" etmek ya da sırasız teslimat, `latest_sha`'yı geriye alıp bütün filoyu eski commit'e düşürür. |
| S5-B04 | Eksik | Yüksek | Kademeli rollout yok. Fan-out bütün opt-in kurulumlara `floor(i/3)*10 sn` gecikmeyle gidiyor: kanal sırası yok, dalgalar arası sağlık/smoke kapısı yok, hata oranında durma yok. 200'de kesme sessiz. **Önceki: A5 (Yapılmadı).** | `GitHubWebhookHandler.php:71-98`; `config/ops.php:40` | Kötü bir commit ≈11 dakikada 200 siteye yayılır. Smoke yalnız o siteyi geri alır, yayılmayı durdurmaz. |
| S5-B05 | Risk | Yüksek | Smoke hatası döngüyü kapatmıyor: `auto_update` açık kalıyor, `error` kurulumları fan-out'a dahil, geri almadan sonra tekrar smoke yok. Manuel dosya geri alması da auto-update'i durdurmuyor. | `ThemeRolloutService.php:518-521` (yalnız status), `:293-297`; `GitHubWebhookHandler.php:77-80`; probe **P9** (ikinci kötü push yine `fanout:1`) | Her yeni push aynı siteyi yeniden kırıp geri alıyor. Geri almanın düzelttiği doğrulanmıyor. |
| S5-B06 | Hata | **Kritik** | CMS 1.2.32 "dosya özelleştirmelerini koru" kapısı auto-update yolunda yok. `keepsThemeFileCustomizationsOnUpdate()` yalnız Blade onay metninde kullanılıyor. | `app/Models/Site.php:672`; `resources/views/ops/sites/_themes.blade.php:25,172-177`; `ThemeRolloutService.php:101-136` (kontrol yok); probe **P6** (1.2.20 siteye update gitti) | Önkoşul: auto-update açık, CMS < 1.2.32 ya da sürüm bilinmiyor. Bu durumda sitenin admin editörüyle yaptığı tema dosyası değişiklikleri sessizce siliniyor (veri kaybı; `155c548`/`16adae4` yalnız manuel yolu korudu). |
| S5-B07 | Hata | Yüksek | Webhook yalnız sha/tag'i güncelliyor; `minimum_deamon_version` ve `smoke_paths` yalnız katalog sync'inde okunuyor. Sürüm kapısı ve smoke bayat manifestle çalışıyor. | `GitHubWebhookHandler.php:57-67` vs `app/Services/Themes/ThemeCatalogSync.php:199-200`; `ThemeRolloutService.php:116` | Tabanı yükselten bir push (ör. 1.2.33 gerektiren tema) eski CMS'li sitelere auto-update ile kurulur (bkz. moonagro 2026-09-23 olayı). |
| S5-B08 | Hata | Yüksek | Assign sırasında activate başarısız olursa satır `installing`'de kalıyor. Audit `theme.install_succeeded` diyor, `theme.activate_failed` yazılmıyor. | `ThemeRolloutService.php:370-372` (`persistError:false`), `:388-392` (status yalnız `!$activate` iken), `:686-695`; probe **P4** | Operatör "kuruluyor" görüp bekliyor. Audit yanlış başarı söylüyor, hata sebebi hiçbir yerde yok. |
| S5-B09 | Hata | Yüksek | Job içinde fırlayan beklenmeyen exception (clone token HTTP hatası = `GitHubApiException`, `ConnectionException`, worker timeout) satırı `installing`/`updating`'de bırakıyor. `failed()` yok, `tries=1`, takılı durumları temizleyen bir süpürücü de yok. | `ThemeRolloutService.php:645-650` (yalnız `GitHubCredentialsException` yakalanıyor), `:353-358`, `:428-433`; `app/Jobs/ThemeUpdateJob.php:16-18`; `grep -rn "function failed" app/Jobs` boş; probe **P5** (status `updating`, 0 audit) | Takılı satır, yanlış "outdated" sayımı ve sessiz başarısızlık. `rollbackThemeFiles` içindeki aynı exception smoke geri almasını da kesiyor (`ThemeRolloutService.php:556` yalnız `ThemeRolloutException`'ı yakalıyor). |
| S5-B10 | Perf/Risk | Yüksek | Süre bütçesi job timeout'unu aşıyor. Smoke en kötü durumda 11 yol × 2 deneme × 15 sn = 330 sn. Buna agent (10 sn × 2 deneme), token (20 sn), health ve geri alma ekleniyor; job timeout 120 sn. `syncNow` ise HTTP isteğinin içinde senkron çalışıyor. | `config/ops.php:44-47,54,111,120`; `ThemeSmokeCheck.php:42-47`; `ThemeUpdateJob.php:18`; `SiteThemeController.php:83` | Worker smoke'un ortasında öldürülür: geri alma yapılmaz, satır `updating` kalır. Sync isteği proxy timeout'una takılabilir (PHP/proxy limiti Doğrulanmadı). |
| S5-B11 | Eksik | Yüksek | `installation` (deleted/suspend) ve `installation_repositories` olayları işlenmiyor. `repository` alanı olmayan payload'da erken `return` var. Doküman bunların işlendiğini söylüyor. | `GitHubWebhookHandler.php:32-35`; `docs/modules/github-webhooks.md:32`; probe **P7** (bağlantı `connected` kaldı) | App kaldırılınca bağlantı "bağlı" görünmeye devam ediyor, ilk clone'da hata çıkıyor. Picker bayat kalıyor. |
| S5-B12 | Risk | Yüksek | Bağlantı kesilip ardından katalog sync çalışınca `themes` satırları siliniyor ve `site_theme_installations` ile allowlist CASCADE ile gidiyor (pinned/previous SHA, `last_sync_task_id`, auto_update). Ne audit ne önizleme var. Onay metni kurulum kaybından söz etmiyor. | `ThemeCatalogSync.php:46,65-75`; `database/migrations/2026_09_09_221002_create_site_theme_installations_table.php:28`; `lang/tr/themes.php:224` | Plane tarafındaki geri alma noktaları ve kurulum kaydı kalıcı olarak kayboluyor (CMS'teki tema yerinde kalıyor). Bağlantıyı yeniden kurmak kaydı geri getirmiyor. |
| S5-B13 | Hata | Orta | Kurulumun kendi `ref`'i update'te dikkate alınmıyor. Tek `latest_sha` (default branch başı) her kuruluma gidiyor. Tag'e ya da başka branch'e sabitlenmiş site de default branch push'unda (`$short === default_ref`) update alıyor. | `GitHubWebhookHandler.php:214-216`; `ThemeRolloutService.php:640-641,655` | `ref=beta`/`v1.2` pini fiilen işe yaramıyor. CMS'e ref'te bulunmayan bir sha gidebiliyor (CMS davranışı Doğrulanmadı). |
| S5-B14 | Hata | Orta | Branch silme push'u (`after=000…`) yine bütün auto-update kurulumlarına fan-out yapıyor: sha `null` olunca ref filtresi atlanıyor. | `GitHubWebhookHandler.php:84` (`$sha !== null && …`); probe **P8** | Gereksiz yeniden kurulum, smoke ve health yükü; Coolify host yük profiliyle çakışıyor. |
| S5-B15 | Eksik | Orta | Şunlar için audit yok: katalog sync (created/updated/**deleted**), bağlantı connect/update/disconnect, webhook alındı özeti (fanout/skip/200 kesmesi), ertelenmiş sync'in kurulması ya da deploy hatasında düşürülmesi, smoke başarısı. | `grep -rhoE "'theme\.[a-z_]+'" app` → 22 action, bunların hiçbiri yok; `ThemeGitConnectionService.php` içinde `auditLogs` yok; `app/Jobs/ThemeSyncAfterDeployJob.php:71-77` | Activity'de "katalog neden 3 temayı sildi?", "hangi push kaç siteye gitti?" sorularının cevabı yok. |
| S5-B16 | UX | Orta | "Update" her durumda "kuyruğa alındı" flash'ı veriyor: sürüm kapısında atlandığında (yalnız audit yazılıyor) ve unique kilit dispatch'i düşürdüğünde de. | `SiteThemeController.php:52-54`; `ThemeRolloutService.php:116-130`; `app/Jobs/ThemeUpdateJob.php:12,20` | Operatör güncellendiğini sanıyor, site eski sürümde kalıyor. |
| S5-B17 | UX | Orta | SHA sapması site Themes sekmesinde görünmüyor (yalnız 7 karakterlik `pinned_sha`; katalog SHA'sı ya da "geride" rozeti yok). `pending_sync_after_deploy` hiçbir view'da yok. CMS'in gerçek SHA'sı ile uzlaştırma yok (`theme_list_path` config'te var ama kullanılmıyor). | `_themes.blade.php:140-142`; `grep -rn pending_sync_after_deploy resources/views` boş; `config/ops.php:125`; katalog düzeyi var: `ThemeController.php:55,101-103` | Sapma yalnız katalog sayfasında ve Sites filtresinde görünüyor. Ertelenmiş sync görünmez. CMS Super Admin ZIP ile değişen tema fark edilmiyor. |
| S5-B18 | Eksik | Orta | Kaçan webhook'lar telafi edilmiyor: katalog sync `latest_sha`'yı ilerletiyor ama auto-update fan-out'u başlatmıyor. Katalog sync zamanlanmış da değil. **Önceki: A1 (Kısmen).** | `ThemeCatalogSync.php:202`; `routes/console.php:16-42` (4 giriş, tema yok) | Plane kapalıyken yapılan push'lar auto-update sitelere hiç ulaşmıyor. |
| S5-B19 | Perf | Orta | Her GitHub API çağrısı yeni bir installation token üretiyor (katalog sync'te repo başına 3 çağrı = 3 token POST'u). Update/geri alma başına da bir token. | `GitHubAppClient.php:422-424,441-451,466-484`; `ThemeCatalogSync.php:105-106` | Katalog sync süresi ve GitHub oranı ikiye katlanıyor (rate-limit etkisi Doğrulanmadı). Artı yön: token kalıcı saklanmıyor, PAT siteye gitmiyor (`GitHubAppClient.php:303-316`). |
| S5-B20 | Risk | Orta | Update, açık Coolify deploy'unda ertelenmiyor (yalnız sync erteleniyor). Aynı site için Update/Sync/SyncAfterDeploy arasında mutex yok, unique kilitleri ayrı. | `ThemeRolloutService.php:132` vs `:167,375`; `uniqueId()` farklı: `ThemeUpdateJob.php:29-32`, `ThemeSyncAfterDeployJob.php:29-32` | Deploy entrypoint'i ile tema update'i aynı volume'da yarışıyor. 2026-09-12 host-guard tasarımının amacı yarım kalıyor. |
| S5-B21 | Borç | Düşük | Fan-out sayacı, soft-delete edilmiş sitelere ve unique kilidin düşürdüğü dispatch'lere de +1 sayıyor. 200 kesmesi yanıtta görünmüyor. `uniqueFor=300` ile 660 sn'ye varan gecikme birbirini tutmuyor. | `GitHubWebhookHandler.php:90-97`; `ThemeUpdateJob.php:20`; `ThemeRolloutService.php:112-114` | Webhook yanıtı ve GitHub teslimat logu yanıltıcı. |
| S5-B22 | UX | Düşük | Sabit İngilizce flash ve exception metinleri (lang dışında). | `ThemeController.php:215,238,257`; `ThemeRolloutService.php:50-52,56-58,152,164` | TR arayüzde karışık dil (lang anahtar eşitliği 2286=2286 bunları yakalamıyor). |
| S5-B23 | Borç | Düşük | Yorum ile sabit uyuşmuyor ("CMS 1.2.31+" vs `1.2.32`). `private` görünürlük `allowlist`'ten daha serbest (herkese atanabilir), bu isimlendirme kafa karıştırıyor. | `ThemeRolloutService.php:452` vs `app/Services/Agent/ControlPlaneAgentContract.php:95`; `ThemeVisibilityGate.php:15-19` | Bakım karışıklığı. "Private" adı yanlış güven veriyor. |

Önem kırılımı: **Kritik 2** (B01, B06) · **Yüksek 10** (B02, B03, B04, B05, B07, B08, B09, B10, B11, B12) · **Orta 8** (B13–B20) · **Düşük 3** (B21–B23). Toplam **23**.

### Önceki inceleme maddeleri (bu alan)

| Önceki | Durum | Bu rapordaki karşılığı |
|--------|-------|------------------------|
| A5 Tema auto-update kademeli yayılım | Yapılmadı (`grep -rni "rollout_plan\|staged" app` boş) | S5-B04 → S5-O04 |
| A1 Gece bakımı — tema katalog kısmı | Kısmen (katalog sync zamanlanmamış) | S5-B18 → S5-O15 |
| 1.3-d CMS runtime'da `git` yok | Doğrulanmadı (CMS handoff) | §6 |
| A7 Self-healing (CoreThemeHealer) | Kısmen — restart otomatik, doğrulama yok | §4 satır 3 |

## 3. İyileştirme ve güncelleme önerileri

Öncelik puanı = Etki×2 − Risk + (S=3, M=2, L=1, XL=0).

| ID | Öneri | Çözdüğü bulgu | Etki (1–5) | Efor | Risk (1–5) | Öncelik puanı | Kabul kriteri |
|----|-------|---------------|-----------:|------|-----------:|--------------:|---------------|
| S5-O01 | Webhook'ta branch filtresi: `latest_sha`'yı yalnız `refs/heads/{default_ref}` push'u değiştirsin. Tag push'u yalnız `latest_tag`'i güncellesin. Silme push'u (`deleted:true`/sıfır SHA) hiçbir şey yapmasın. `refMatchesPush`'taki `default_ref` jokeri kaldırılsın. | B01, B13, B14 | 5 | S | 1 | **12** | P1'deki senaryoda `latest_sha` değişmez, `fanout:0`. P8'de `fanout:0`. Yeni testler: feature branch, tag, silme. |
| S5-O06 | Auto-update'te 1.2.32 kapısı: `!keepsThemeFileCustomizationsOnUpdate()` ise webhook update'i atla ve `theme.update_skipped_customizations` audit'i yaz. Auto-update'i açma onayı eski CMS'te danger metni göstersin. | B06 | 5 | S | 1 | **12** | P6 senaryosunda agent çağrısı 0, audit 1. Manuel Update davranışı değişmez. |
| S5-O05 | Smoke hatasında o kurulumu dondur: `auto_update_held_sha` (bozuk SHA) yaz. Fan-out yalnız `status=active` ve held SHA'dan farklı `latest_sha` için çalışsın. Geri almadan sonra smoke'u bir kez daha çalıştır ve sonucu audit'e yaz. | B05 | 4 | S | 1 | **10** | P9'daki ikinci push `fanout:0`. `theme.smoke_failed.after.recheck` = pass/fail. Operatör "Devam et" ile hold'u kaldırabilir ve bu audit'lenir. |
| S5-O08 | Durum makinesi: activate hatasında `markError('theme.activate_failed')`. `themePayload` içinde `GitHubApiException`/`ConnectionException` → `markError`. Job'lara `failed()` → `markError`. `installing`/`updating` durumunda 15 dakikadan uzun kalanı `error` yapan zamanlanmış süpürücü. | B08, B09 | 4 | S | 1 | **10** | P4 → status `error` + `activate_failed` audit. P5 → status `error` + `update_failed` audit. Süpürücü testi. |
| S5-O07 | Push anında manifesti yeniden oku: fan-out'tan önce pushlanan SHA'daki `theme.json`'dan `minimum_deamon_version` ve `smoke_paths`'i güncelle (1–2 API çağrısı). Okuma başarısızsa fan-out yapma. | B07 | 4 | S | 2 | **9** | Test: push manifesti `minimum=9.0.0` → 1.2.x siteler `update_skipped_version` alır. |
| S5-O03 | Teslimat idempotency'si + tekdüzelik: `github_webhook_deliveries` (unique `delivery_id`, 30 gün TTL). Push'u yalnız `before == themes.latest_sha` ise ya da `GET /repos/{r}/compare/{latest}...{after}` `ahead` dönerse kabul et; değilse `ignored_stale` olarak yaz. | B03 | 4 | M | 2 | **8** | P3 → ikinci teslimat `duplicate:true`, agent çağrısı 1. Eski SHA'lı teslimat `latest_sha`'yı geri almaz. |
| S5-O04 | Rollout planı (A5): `theme_rollouts` + `theme_rollout_targets`. Dalgalar kanala göre (`alpha → beta → main`), dalga boyutu N, bekleme süresi (soak) X dk. Kapı: dalgada smoke fail = 0 ve health ok. Eşik aşılırsa plan `halted`. UI'da duraklat/sürdür/iptal. Webhook doğrudan fan-out yerine plan açsın. | B04, B05, B21 | 5 | L | 3 | **8** | Test: 3 kanal × 2 site; alpha'da smoke fail → beta/main'e 0 çağrı, plan `halted`, bildirim 1. Kill switch `OPS_THEME_ROLLOUT_ENABLED=false` → fan-out 0. |
| S5-O09 | Süre bütçesi: smoke için toplam bütçe (ör. 45 sn) ve `Http::pool` ile paralel GET. `ThemeUpdateJob`/`InstallJob` timeout ≥ 300. `syncNow` + geri almalar `OpsBackgroundJob`'a taşınsın, HTTP yanıtı hemen dönsün. | B10 | 4 | M | 2 | **8** | Smoke en kötü süresi ≤ bütçe (birim test). Sync uç noktası < 1 sn içinde 202/redirect döner, sonuç jobs widget'ında görünür. |
| S5-O10 | `installation` (deleted/suspend/unsuspend) → bağlantı `status=error/connected` + audit. `installation_repositories` → `syncRepos`. Ya da doküman düzeltilsin. | B11 | 3 | S | 1 | **8** | P7 → bağlantı `error`. Picker, eklenen/çıkarılan repo'yu webhook'la gösterir. |
| S5-O11 | Güvenli bağlantı kesme: onay modalı etkilenecek kurulum ve site sayısını göstersin. Prune silmek yerine temayı `archived` yapsın (kurulum kaydı kalsın) ya da ayrı "Kalıcı sil" onayı istesin. Her prune için `theme.catalog_pruned` (tema ve site listesi) audit'i. | B12 | 4 | M | 2 | **8** | Disconnect + sync sonrası `site_theme_installations` satırları durur. Audit satırı silinen ya da arşivlenen tema kimliklerini listeler. |
| S5-O12 | Audit kapsamı: `theme.catalog_synced`, `theme_git.connected/updated/disconnected`, `theme.webhook_received` (delivery, ref, fanout, skipped, truncated), `theme.sync_deferred`/`theme.sync_deferred_dropped`, `theme.smoke_passed` (özet). | B15, B21 | 3 | S | 1 | **8** | Her akış için feature testte ilgili action var. Secret/token audit'e girmez (mevcut `test_secret_is_never_written_to_logs` kalıbı). |
| S5-O18 | Tema detayında toplu "N geride kurulumu güncelle": O04 planını elle açar (L3). Kanal ve site seçimi, önizleme listesi. | B04, B17 | 4 | M | 2 | **8** | Geride kalan 5 kurulumlu temada plan açılır, 5 hedef listelenir. Viewer göremez. |
| S5-O14 | Sapma görünürlüğü: site Themes sekmesinde `pinned → latest` rozeti, "ertelenmiş sync" ve "beklemede (held)" chip'leri. İsteğe bağlı olarak health ya da `GET /themes` ile CMS'in bildirdiği SHA'yı sakla ve farkı göster. | B17 | 3 | M | 1 | **7** | Geride kalan kurulum rozet gösterir. `pending_sync_after_deploy=1` chip gösterir. Test: `_themes` render. |
| S5-O15 | Zamanlanmış katalog sync (saatlik, `onOneServer`, `withoutOverlapping`). Sonrasında geride kalan auto-update kurulumları için O04 planı açılsın (kaçan webhook telafisi). | B18 (A1) | 3 | S | 2 | **7** | `schedule:list` girişi var. Test: webhook kaçırılmış kurulum, sync sonrası plan hedefi olur. |
| S5-O13 | Dürüst flash: `updateToLatest` `queued\|skipped_version\|already_queued` dönsün, controller buna göre mesaj versin. | B16 | 2 | S | 1 | **6** | Sürüm atlamasında `error` flash'ı `cms_too_old` metniyle çıkar. |
| S5-O17 | Site başına tema mutex'i (`Cache::lock("site-theme:{id}")`) ve açık deploy'da update'i de ertele (`pending_update_after_deploy`). | B20 | 3 | M | 2 | **6** | Test: deploy açıkken update → agent çağrısı 0, deploy bitince 1. |
| S5-O16 | Installation token'ını installation başına ≤50 dk cache'le (şifreli cache anahtarı, loglanmaz). Katalog sync aynı token'ı kullansın. | B19 | 2 | S | 2 | **5** | 10 repoluk sync'te `access_tokens` POST sayısı 1 (Http::fake sayacı). |
| S5-O19 | i18n temizliği (`ThemeController`, `ThemeRolloutService` metinleri lang'e) + yorum düzeltmesi. `private` görünürlüğe "yalnız operatör açık atama" ipucu. | B22, B23 | 1 | S | 1 | **4** | `grep` ile sabit İngilizce flash kalmaz. |

Önerilen sıra: O01 → O06 → O05 → O08 → O07 (hepsi S, toplam ≈1 gün). Ardından O03, O10, O12. Sonra O04 + O18 + O15 birlikte (A5 paketi), O09, O11.

## 4. Otonomi fırsatları

| Akış | Bugünkü seviye | Hedef | Tetik | Ön koşul | Güvenli otomatik adım | Doğrulama | Geri alma | Guardrail/bütçe | Kill switch | Audit | Bildirim |
|------|----------------|-------|-------|----------|-----------------------|-----------|-----------|-----------------|-------------|-------|----------|
| Push → auto-update | L4, guardrail'siz (bütün opt-in'ler, branch filtresi yok) | L4 guardrail'li (O01+O04), ardından L5 | Default branch push'u (O01), idempotent teslimat (O03) | `auto_update=1`, `status=active`, held değil, CMS ≥ `minimum` ve ≥ 1.2.32 (O06), deploy açık değil | Dalga dalga `ThemeUpdateJob` (alpha → beta → main) | Dalga başına smoke 0 fail + health ok | Başarısız sitede `rollbackThemeFiles`; plan `halted` | Dalga ≤ N (vars. 3), soak ≥ 30 dk, hata eşiği 1 | `OPS_THEME_ROLLOUT_ENABLED`, plan başına "Duraklat" | `theme.rollout_{started,wave_passed,halted,completed}` | Activity + `PlatformOpsMailer` yeni `theme_rollout_halted` anahtarı (`DEPLOY_FAILED` kalıbı: `app/Services/Mail/PlatformNotificationCatalog.php:30`) |
| Smoke → geri alma | L4 (geri alır; döngüyü kapatmaz) | L5 | `guardStorefront` fail | Aktif tema, `previous_pinned_sha` ya da `last_sync_task_id` var | Geri al + hold (O05) | Geri almadan sonra smoke'u tekrar çalıştır | Geri alma başarısızsa: status `error`, insan müdahalesi | Kurulum başına 1 otomatik geri alma / push | `OPS_THEME_SMOKE_ENABLED` (var: `config/ops.php:45`) | `theme.smoke_failed` (+`recheck`) | Activity + ops maili |
| Bayat core tema → restart | L4 (`CoreThemeHealer.php:23-61`, 6 sa pencere) | L5 | Health `core_theme_in_sync=false` | `coolify_app_uuid`, pencere boş | Coolify restart | Restart'tan sonra health `in_sync=true` + smoke | Yok (restart idempotent); ikinci başarısızlıkta L2 teşhis | Site başına 6 saatte 1 | `OPS_CORE_THEME_AUTO_RESTART` (var) | `site.core_theme_restarted` / `_failed` (var) + `verified` | Doğrulama fail olursa ops maili |
| Takılı `installing`/`updating` | L0 | L4 | Zamanlanmış süpürücü (5 dk) | `updated_at` > 15 dk, kuyrukta ilgili unique kilit yok | status → `error`, `last_error="timeout"` | Satır `error`, UI'da görünür | Gerekmez (yalnız durum) | Çalışma başına ≤ 200 satır | config bayrağı | `theme.install_stalled` | Activity |
| Kaçan webhook telafisi | L0 | L4 | Zamanlanmış katalog sync sonrası (O15) | Auto-update, geride, held değil, açık plan yok | O04 planı aç (doğrudan update değil) | Plan kapıları | Plan geri alması | Günde ≤ 1 plan / tema | `OPS_THEME_ROLLOUT_ENABLED` | `theme.rollout_started{reason:reconcile}` | Activity |
| Deploy sonrası ertelenmiş sync | L4 (var) | L4 + L1 görünürlük | Deploy `finished` | `pending_sync_after_deploy` | `syncNow` merge | Smoke (var) | `rollbackLastSync` (var) | Site başına 1 | Yok → config bayrağı öner | Eksik: `theme.sync_deferred`/`_dropped` (O12) | Deploy fail'de düşürülen sync için Activity |
| Installation kaldırıldı | L0 | L4 | `installation.deleted/suspend` webhook | İmza geçerli, bağlantı eşleşiyor | Bağlantı `status=error` | Sonraki sync `markError` ile tutarlı | `unsuspend` → `connected` | — | — | `theme_git.installation_{deleted,suspended}` | Activity + ops maili |
| CMS gerçek SHA uzlaştırma | L0 | L2 | Health poll ya da `GET /themes` | CMS SHA'yı bildiriyor (CMS handoff) | Yalnız `reported_sha` yaz | Fark varsa rozet | — | Poll'a bağlı | — | Fark ilk görüldüğünde `theme.drift_detected` | UI rozeti |

## 5. Yeni özellik fikirleri (bu alana özgü)

- **Rollout planı sayfası.** Tema × SHA başına dalgalar, hedef listesi, canlı durum, duraklat/sürdür/iptal (O04/O18'in UI'ı).
- **Kanarya site.** Tema başına 1–2 "önce bu" site; plan ilk dalgayı ona yapar.
- **Değişiklik önizlemesi.** Update onayında GitHub compare API'den commit listesi ve değişen dosya sayısı; `customized_files` ile çakışma tahmini.
- **Donma penceresi.** Tema ya da site başına "auto-update şu saatlerde kapalı" (kampanya dönemleri).
- **Webhook teslimat günlüğü.** Son 100 teslimat, ref, sonuç, fan-out ve atlama sebebi (O03 tablosunun görünümü).
- **Tema sağlık skoru.** Tema başına son 30 günde smoke fail ve geri alma oranı; katalog satırında renkli gösterge.
- **SHA pin / hold yönetimi.** Site başına "bu SHA'da kal" ve serbest bırak (O05'in operatör yüzü).

## 6. CMS handoff

| Konu | CMS'ten gereken | Plane bağımlılığı |
|------|-----------------|-------------------|
| Kurulu tema SHA'sı | Health ya da `GET /themes` yanıtında aktif temanın `sha`'sı ve `customizations` özeti (mevcut şema Doğrulanmadı; test fake'lerinde `installed[].sha` var: `tests/Feature/Themes/ThemeAssignTest.php` activate yanıtı) | S5-O14 sapma görünürlüğü |
| `sha` ile `ref` uyuşmazlığı | `themes/update`'e ref'te olmayan ya da SHA olmayan (`"main"`) bir değer gelince açık 422 kodu (bugünkü davranış Doğrulanmadı) | S5-B02, S5-B13 teşhisi |
| Update süresi | `themes/update` git fetch ve checkout'u 10 sn agent timeout'u içinde mi bitiriyor, yoksa arka plan görevi mi? (Doğrulanmadı) | S5-O09 bütçesi |
| Idempotent update | Aynı SHA tekrar gelince no-op + `unchanged:true` | S5-O03 ve S5-B14 yükünü azaltır |
| Runtime'da `git` (önceki 1.3-d) | İmajda `git` bulunduğunun teyidi | Tüm install/update akışı |
| Dosya özelleştirme | 1.2.32 öncesi CMS'lerin güncellenmesi (auto-update ancak o zaman güvenli) | S5-O06 atlamalarının azalması |

## 7. Açık sorular

| # | Soru | Önerilen varsayılan |
|---|------|---------------------|
| 1 | Auto-update yalnız default branch'i mi izlesin, yoksa kurulumun `ref`'ini mi (branch başına `latest_sha`)? | v1: yalnız default branch. Başka ref'e pinli kurulumlar auto-update almaz (UI bunu söyler). |
| 2 | Kademeli rollout dalgaları kanala göre mi, sabit yüzdeye göre mi? | Kanal: `alpha → beta → main`; dalga 3 site, soak 30 dk, 1 smoke fail'de dur. |
| 3 | Smoke hatasında bekletme kapsamı yalnız o kurulum mu, temanın bütün rollout'u mu? | İkisi de: kurulum held, açık plan `halted`. |
| 4 | Bağlantı kesilince kurulum kayıtları silinsin mi? | Hayır. Tema `archived`, kurulum kaydı kalır. Kalıcı silme ayrı danger onayıyla. |
| 5 | CMS < 1.2.32 ya da sürümü bilinmeyen sitelerde auto-update ne yapsın? | Atla + audit + UI uyarısı. Açık "üzerine yazılmasını kabul ediyorum" seçeneği v1'de yok. |
| 6 | Webhook replay koruması için compare API çağrısı (ek GitHub isteği) kabul edilebilir mi? | Evet. Push başına 1 çağrı; başarısızsa teslimat reddedilmez, `before` eşleşmesine düşülür. |
| 7 | `syncNow` senkron mu kalsın (operatör sonucu hemen görüyor)? | Kuyruğa taşınsın; sonuç jobs widget'ında ve Activity'de görünsün. |
