# Bot koruması (Cloudflare Turnstile)

> CMS tarafı: `deamon/docs/modules/turnstile.md` (1.2.78+). Agent ucu: `GET`/`POST /internal/control/v1/turnstile`.

Plane her sitenin herkese açık formlarını Cloudflare Turnstile ile korur: widget'ları sitenin Cloudflare hesabında açar, anahtarları siteye imzalı agent ile iter. Ücretsizdir; site Cloudflare'de olmak zorunda değildir (widget hesabı sitenin bağlı Cloudflare hesabı, yoksa varsayılan hesap).

## Neden havuz

Cloudflare ücretsiz planında **hesap başına 20 widget**, **widget başına 10 alan adı** var; kök alan adı alt alan adlarını da kapsar; doğrulama isteği sınırsız.

- Her siteye ayrı widget: ~30 sitede 20 sınırı aşılır.
- Tek widget hepsine: 10 alan adında dolar.

Bu yüzden `TurnstileWidgetPool` her widget modu (`managed`, `non-interactive`, `invisible`) için, aynı moddaki sitelerin alan adlarını 10'a kadar bir widget'ta toplar; dolunca `Deamon · {mod} · 02` açılır. Her site üç modun her birinden bir widget'ta durur ve üç anahtar çiftini de alır: görünüm değişikliği (Plane'den ya da CMS admininden) Cloudflare'e gitmeden anında olur. Bir hesap böylece kök alan adı başına üç yer harcar: 20 × 10 / 3 ≈ 66 site. Daha fazlası için siteyi ikinci bir Cloudflare hesabına bağlamak yeter.

Paylaşılan widget'ta bir sitenin token'ı diğerinde de geçerli olurdu; CMS siteverify cevabındaki `hostname`'i kendi host'uyla karşılaştırır, bu yüzden olmaz.

## Alan adları

`TurnstileHostnames::forSite`: `operatorHosts()` içinden müşteri alanı kökten (`www.musteri.com.tr` → `musteri.com.tr`, `CloudflareHostname::apex`), ajansın ortak alanındaki site tam adıyla (`kirmizi-tilki.codron.co`; kök yazılsa başka sitelerin önizleme adresleri de kapsanırdı). Geçici önizleme adresi yazılmaz; CMS widget'ta olmayan host'ta korumayı atlar.

## Akış

| Ne zaman | Ne olur |
|---|---|
| Yeni site, agent secret enjekte edilince (`SiteAgentSecretInjector`) | `ops.turnstile.auto_enroll` açıksa (varsayılan) ve kullanılabilir Cloudflare hesabı varsa kayıt açılır (açık, gerekirse görünür, bütün formlar); 2 dk sonra `SyncSiteTurnstileJob` widget'ları ayırır ve iter |
| Var olan siteler | `php artisan ops:turnstile-enroll --all` (önce `--dry-run`) ya da site sayfasında **Bot korumasını kur**. İşler 20 sn arayla kuyruğa girer |
| Operatör kaydı (site → Altyapı → Bot koruması) | `changed_at`, audit `site.turnstile.updated` (gizli anahtar yok), `SyncSiteTurnstileJob` |
| Alan adı ekle/sil, site formunda alan adı değişimi | `SiteTurnstileSync::queue` (30 sn gecikmeli eşitleme) |
| Kalıcı silme (purge) | Sitenin alan adları widget'lardan çıkarılır; boşalan widget silinir |
| Saatlik `ReconcileSiteTurnstileJob` (:25) | Widget'ı olmayan ya da alan adları değişen siteyi eşitler; bekleyen/başarısız gönderimi ve health'te farklı site anahtarı bildiren siteyi yeniden iter |

`SyncSiteTurnstileJob`: Cloudflare hatası gönderimi durdurmaz (mod/form değişikliği eski widget'larla gider). 4 deneme, 60/180/600 sn. `validation_failed`, `cms_too_old`, `secret_echoed`, `no_base_url` yeniden denenmez.

Sürüm: CMS health'te 1.2.78'den eski sürüm bildiriyorsa gönderim yapılmaz (`cms_too_old`). Sürüm henüz bilinmiyorsa (yeni site) denenir; eski CMS 404 döner.

## CMS gövdesi

`SiteTurnstile::pushPayload()`: `{enabled, mode, forms, hostnames, keys: {managed|non-interactive|invisible: {sitekey, secret}}}`. Widget yoksa `keys: null` (CMS Plane anahtarlarını siler, sitenin kendi anahtarı kalır). Gövde log'a yazılmaz; cevapta gizli anahtar geçerse gönderim `secret_echoed` ile başarısız sayılır. Health özetinde (`last_health_payload.turnstile`) yalnız `enabled`, `mode`, `source`, `ready`, `sitekeys` tutulur.

## Cloudflare token izni

Mevcut token'lara **Account → Turnstile → Edit** eklenmeli (Cloudflare → My Profile → API Tokens → token → Edit). İzin yoksa `sync_error = cloudflare_forbidden`; DNS, provision ve site etkilenmez.

## Kayıtlar

- `turnstile_widgets`: hesap, mod, ad, `sitekey` (benzersiz), `secret` (şifreli), `domains`.
- `site_turnstiles`: site başına istenen durum (`enabled`, `mode`, `forms`), atama (`cloudflare_setting_id`, `hostnames`, `widget_ids`), `synced_at`/`sync_error`, `changed_at`/`pushed_at`/`push_failed_at`/`push_error`.

## Test

`tests/Feature/Sites/SiteTurnstileTest.php` (`Http::fake`; canlı token yok).
