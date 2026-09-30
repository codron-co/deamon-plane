# Arama & Analitik (Search Console + GA4/GTM)

Site detayında **Arama & Analitik** sekmesi (`/sites/{site}#search`). Bir sitenin arama motoru
doğrulamasını ve ölçüm kimliklerini Plane'den yönetir; env veya redeploy yok
([ADR-12](../decisions/adr-12-site-config-from-plane.md)).

CMS tarafı: `codron-co/deamon` → `docs/modules/search-integrations.md` ("Deamon Plane'den yönetim")
ve `docs/modules/control-plane-agent.md` (`GET` / `POST /internal/control/v1/search-integrations`). **CMS 1.2.49+ gerekir**; daha eski CMS `404/405` döner, saatlik tarama CMS güncellenince yeniden iter.

## Alanlar

| Plane alanı (`site_search_integrations`) | CMS anahtarı | Doğrulama (Plane = CMS) |
|---|---|---|
| `google_verification` | `verifications.google` | meta `content` değeri: `[A-Za-z0-9._:+/=-]`, ≤191; tırnak/boşluk/HTML yok |
| `google_file_token` | `verification_files.google` | `google{token}.html` → `token`: `[A-Za-z0-9_-]` |
| `bing_verification` | `verifications.bing` | meta `content` (`msvalidate.01`) |
| `yandex_verification` | `verifications.yandex` | meta `content` (`yandex-verification`) |
| `google_mode` | `google.mode` | `off` \| `ga4` \| `gtm`; `ga4` → `ga4_id`, `gtm` → `gtm_id` zorunlu |
| `ga4_id` | `google.ga4_id` | `G-XXXXXXXX` (büyük harfe çevrilir; `UA-…` reddedilir) |
| `gtm_id` | `google.gtm_id` | `GTM-XXXXXXX` |
| `yandex_metrica_id` | `yandex_metrica.counter_id` | yalnızca rakam, ≤16 |
| `clarity_id` | `clarity.project_id` | harf/rakam, ≤64 |
| `enable_module` | `enable_module` | Sitede `search-integrations` modülü kapalıysa açar (varsayılan açık) |

Operatör tüm meta etiketini (`<meta name="google-site-verification" content="…">`) ya da dosya adını
(`google1a2b3c.html`, URL de olur) yapıştırabilir; Plane yalnızca değeri ayıklar
(`SiteSearchIntegration::normalizeInput`). Ham `<head>`/`<body>` HTML, pikseller ve IndexNow bu
ekranda **yok**; CMS agent onları reddeder (`unsupported_field`). Değerler gizli değildir (sayfa
kaynağında görünür), bu yüzden şifrelenmez ve audit satırlarında yer alır.

## Akış

- **Kaydet** (`POST /sites/{site}/search-integrations`, `update` yetkisi = operatör/süper yönetici):
  doğrula → `site_search_integrations` istenen durum → audit `site.search_integrations.updated`
  (önce/sonra değerler) → `PushSiteSearchIntegrationsJob` (4 deneme, 60/180/600 sn geri çekilme).
  CMS 422 (`validation_failed`/`unsupported_field`) tekrar denenmez; neden panelde görünür.
- **Siteden çek** (`…/pull`, onay modalı): agent `GET` → sitedeki değerler Plane'e yazılır, tüm alanlar
  Plane'in sayılır, siteye bir şey gönderilmez. Audit `site.search_integrations.pulled`.
- **Şimdi gönder** (`…/push`): kayıtlı istenen durumu yeniden kuyruğa alır. Audit `…push_requested`.
- **Saatlik tarama** `ReconcileSiteSearchIntegrationsJob` (`ops-search-integrations-reconcile`):
  hiç gönderilmemiş, gönderimden sonra değişmiş ya da son gönderimi başarısız kayıtları satır içi
  yeniden iter (eski CMS `404/405` → CMS güncellenince kendiliğinden düzelir).

**Hangi alanlar gönderilir:** `managed_fields`. Plane'de bir kez dolu olan (ya da siteden çekilen) alan
her gönderimde gider, boşaltılırsa sitede de silinir. Plane'de hiç doldurulmamış boş alan gönderilmez;
böylece ilk kayıt sitenin kendi girdiği değerleri silmez. Sitede zaten değer varsa önce **Siteden çek**.

**Çakışma kuralı:** Site admini aynı alanları düzenleyebilir (CMS ekranında "Bu alanlar Deamon Plane'den
yönetiliyor" uyarısı). Plane'in bir sonraki gönderimi (kayıt, "Şimdi gönder" veya saatlik tarama bir
başarısızlığı yeniden denerken) kazanır. Kalıcı değişiklik Plane'den yapılır.

**Durum:** `pushState()` → `ok` (gönderildi) · `pending` (değişti, henüz gönderilmedi) · `failed`
(`push_error`, `SiteSearchIntegrationsPresenter::errorLabel`) · `never`. Panel ayrıca son çekme,
sitedeki modül durumu ve health'in bildirdiği özet (`search_integrations.google_verification`,
`measurement`) gösterir.

## Filo listesi

Sites tablosunda isteğe bağlı **Arama & Analitik** sütunu (`search`; sütun seçiciden açılır): `GSC` /
`GSC yok` + ölçüm kimliği (`G-…` / `GTM-…` / Kapalı), son gönderim başarısızsa kırmızı çip. Filtre
**Arama & Analitik** (`analytics`): `gsc_missing` (Search Console doğrulaması yok), `measurement_missing`
(GA4/GTM yok), `push_failed`. Kaynak Plane istenen durumudur; Plane'de kaydı olmayan site "yok" sayılır.

## Operatör adımları

**Search Console — URL ön ekli mülk (meta etiketi):**

1. Search Console → **Mülk ekle** → **URL ön eki** → `https://alanadi.com` (sitenin birincil alan adı, `https://` ile).
2. Doğrulama yöntemlerinden **HTML etiketi**'ni seçin, `<meta name="google-site-verification" …>` etiketini kopyalayın.
3. Plane → Sites → site → **Arama & Analitik** → **Google Search Console doğrulama** alanına yapıştırın → **Kaydet ve siteye gönder**.
4. Durum çipi **Siteye gönderildi** olunca (genelde birkaç saniye; kuyruk çalışıyor olmalı) Search Console'da **Doğrula**'ya basın.
   Kontrol: `view-source:https://alanadi.com` içinde `google-site-verification` görünmeli.
5. Etiketi **kaldırmayın**: Google doğrulamayı periyodik yeniler.

Alternatif: **HTML dosyası** yöntemi — indirilen dosyanın adını (`google1a2b3c.html`) **Google doğrulama
dosyası** alanına yapıştırın; site `https://alanadi.com/google1a2b3c.html` adresini kendisi sunar.
**Site henüz yayında değilse** (CMS "Yakında" sayfası) meta etiketi sayfaya basılmaz; bu durumda dosya
yöntemini kullanın (CMS 1.2.49+ dosya yollarını bakım sayfasından geçirir).

**Search Console — Domain mülkü:** DNS TXT kaydı ile doğrulanır; bu ekranın kapsamı dışındadır. TXT
kaydını alan adının DNS'ine (Cloudflare vb.) ekleyin. Domain mülkü doğrulandıysa meta etiketine gerek yoktur.

**Google Analytics 4:**

1. GA4 → Yönetici → **Veri akışları** → Web akışı → **Ölçüm kimliği** (`G-XXXXXXXXXX`).
2. Plane → **Arama & Analitik** → **Google ölçüm modu = Google Analytics 4** → **GA4 ölçüm kimliği** → Kaydet.
3. Etiket tüm sayfalara Consent Mode v2 ile eklenir; ziyaretçi onayı olmadan veri toplama `denied` başlar.
   GA4 **Gerçek zamanlı** raporunda onay veren bir ziyaretle doğrulayın.

Tag Manager kullanılıyorsa mod **Google Tag Manager** + `GTM-…`; GA4'ü GTM içinden yönetin (ikisini birden
açmayın, çift sayım olur).

## Dosyalar

- `app/Models/SiteSearchIntegration.php` — alan eşlemesi, normalize, kurallar, `pushPayload`, `managedAfter`, `pushState`
- `app/Services/SearchIntegrations/SiteSearchIntegrationsAgent.php` — imzalı `POST` (push) / `GET` (pull)
- `app/Services/SearchIntegrations/SiteSearchIntegrationsPresenter.php` — hata metinleri
- `app/Http/Controllers/Ops/SiteSearchIntegrationsController.php` — kaydet / çek / gönder
- `app/Jobs/PushSiteSearchIntegrationsJob.php`, `app/Jobs/ReconcileSiteSearchIntegrationsJob.php` (+ `routes/console.php`)
- `resources/views/ops/sites/_search-integrations.blade.php`, `_cell.blade.php` (`search` sütunu)
- `lang/{tr,en}/search_integrations.php`
- `database/migrations/2026_09_30_180000_create_site_search_integrations_table.php`
- Testler: `tests/Feature/Sites/SiteSearchIntegrationsTest.php` (`Http::fake`)
