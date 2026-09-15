# Sites — backend ve iş akışı denetimi (todo)

**Tarih:** 2026-09-15
**Kapsam:** `/sites` listesi, oluştur / düzenle, site detay, domain, Cloudflare, Coolify, admin, mail, yaşam döngüsü.
**Yöntem:** Route → FormRequest → controller → servis → job zinciri okundu. Her madde kodda doğrulandı.
**Kural:** Her madde ayrı commit. Test + Pint yeşil olmadan işaretlenmez.

Öncelik: **P0** operatörü bugün engelliyor · **P1** yanlış sonuç veya uzun bekleme · **P2** tutarlılık.

---

## P0

### B1 · Bağlı domain silinemiyor
- [x] **Sorun:** Siteye domain eklenebiliyor ama tek tek kaldırılamıyor. Tek yol düzenleme formundaki takma ad satırını boşaltmak.
- **Kanıt:** `routes/ops/sites.php` içinde `POST /sites/{site}/domains` var, silme karşılığı yok. `_domains.blade.php` satırlarında aksiyon yok.
- **Yapılacak:** `DELETE /sites/{site}/domains/{domain}`. Birincil host silinemez (önce birincili değiştir). Takma ad silinince www kardeşi de gider. Cloudflare A kaydı temizlenir, host kendi zone'undaysa zone'a dokunulmaz. Coolify bağlaması güncellenip redeploy kuyruğa alınır. Audit `site.domain_removed`. Kart satırında danger onaylı "Kaldır" butonu.
- **Kabul:** Takma ad + www satırları silinir, DNS kayıt DELETE'i gider, Coolify PATCH listesinde host yok, deploy tetiklenir. Birincil için 422. Viewer 403.

### B2 · Düzenleme formundan kaldırılan takma ad Cloudflare'de kalıyor
- [x] **Sorun:** `SiteDomainSync::sync` yalnız `site_domains` satırını siliyor. DNS A kaydı yayında kalıyor.
- **Kanıt:** `app/Services/Sites/SiteDomainSync.php` → `whereNotIn('domain', $keep)->delete()`, Cloudflare çağrısı yok.
- **Yapılacak:** Silinen hostları döndür, B1'deki DNS temizleme servisiyle kaldır.
- **Kabul:** Formdan takma ad çıkarılınca ilgili `dns_records` DELETE'i gönderilir.

### B3 · Arşivlenen sitenin slug ve domaini 500 veriyor
- [x] **Sorun:** Arşivli sitenin slug'ı veya birincil domaini ile yeni site açınca doğrulama geçiyor, INSERT benzersizlik ihlaliyle 500 veriyor. Takma adda genel "alınmış" mesajı çıkıyor, hangi site olduğu söylenmiyor.
- **Kanıt:** `create_sites_table` → `slug` ve `primary_domain` DB'de unique. `StoreSiteRequest` / `UpdateSiteRequest` → `Rule::unique('sites', ...)->whereNull('deleted_at')` arşivli satırı atlıyor.
- **Karar (denetimden sonra revize):** Satırları silmek yerine arşivli site değerlerini sahiplenmeye devam eder. Importer da arşivli siteyi hostunu "dolu" sayıyor (`CoolifyFleetImporter::trashedOccupies`), satır silmek bununla çelişirdi. Domaini serbest bırakmanın yolu B11'deki geri yükle / kalıcı sil.
- **Yapılan:** `App\Rules\NotHeldByArchivedSite` slug, birincil, takma ad ve domain ekleme formlarında. Mesaj arşivdeki siteyi adıyla söylüyor.
- **Kabul:** Arşivli değerle oluşturma 500 değil, alan bazında hata döner. Canlı site kendi değerleriyle güncellenebilir.

---

## P1

### B4 · Kalıcı silme Cloudflare kayıtlarını bırakıyor
- [x] **Sorun:** `SiteLifecycle::purge` Coolify uygulamasını siliyor, DNS A kayıtları ve geçici preview host kaydı kalıyor.
- **Kanıt:** `app/Services/Sites/SiteLifecycle.php` → `deleteCoolifyIfPresent` sonrası doğrudan `forceDelete`.
- **Yapılacak:** Coolify silindikten sonra host A kayıtlarını ve preview hostunu best-effort kaldır. Zone silinmez (müşteri zone'u). Hata purge'ü durdurmaz, audit'e not düşer.
- **Kabul:** Purge testinde host başına DNS DELETE görülür, Cloudflare hatası purge'ü engellemez.

### B5 · Takma ad birincil domain yapılamıyor
- [ ] **Sorun:** Düzenleme formunda birincil alanına aynı sitenin takma adını yazınca "alınmış" hatası.
- **Kanıt:** `UpdateSiteRequest` → `Rule::unique('site_domains', 'domain')->ignore($domainId)` yalnız mevcut birincil satırı hariç tutuyor.
- **Yapılacak:** Kural bu sitenin tüm satırlarını hariç tutsun (`where('site_id', '!=', $siteId)`).
- **Kabul:** Takma adı birincil yapma kaydedilir, eski birincil takma ada düşmez ve DNS/Coolify akışı çalışır.

### B6 · Site detay sayfası her açılışta CMS'i bekliyor
- [x] **Sorun:** `SiteDetailController` her istekte `listAdmins` çağırıyor. CMS yavaş veya kapalıysa sayfa 10 saniye + retry kadar donuyor.
- **Kanıt:** `app/Http/Controllers/Ops/SiteDetailController.php` → `$agentClient->listAdmins($site)` senkron.
- **Yapılacak:** Admin paneli ayrı fragment uç noktasından sonradan yüklensin. Sayfa CMS'e bağlı olmadan açılsın.
- **Kabul:** Detay GET'i agent'a istek atmaz. Fragment uç noktası listeyi, hata ve `needs_secret` durumunu döndürür.

### B7 · Yazılım maili site ayarı istek içinde CMS'i bekliyor
- [x] **Sorun:** `assignPlatformMail` configure'u senkron çağırıyor. Bağlantı retry'ı ile istek ~25 saniye sürebilir. Hostinger mail için yapılan kuyruk düzeltmesi burada yok.
- **Kanıt:** `SiteController::assignPlatformMail` → `$configurer->sync($site)`.
- **Yapılacak:** `PushPlatformMailJob` kuyruğa. Flash "arka planda aktarılıyor" desin, sonuç mevcut `platform_mail_push_*` kolonlarında görünsün.
- **Kabul:** Kayıt isteği agent'a senkron istek atmaz, job kuyruğa girer.

### B8 · Toplu kalıcı silme istek içinde döngü ve kırılgan
- [ ] **Sorun:** `bulkPurge` arka plan işi açmıyor, tüm siteleri tek HTTP isteğinde siliyor. Döngü yalnız iki istisna tipini yakalıyor, beklenmeyen hata yarıda 500 veriyor.
- **Kanıt:** `SiteCoolifyOpsController::bulkPurge`, `SiteLifecycle::purgeMany`.
- **Yapılacak:** JSON isteğinde `sites.bulk_purge` arka plan işi. `purgeMany` her `Throwable`'ı site bazında yakalasın.
- **Kabul:** Fetch ile toplu silme job döndürür. Bir sitedeki beklenmeyen hata diğerlerini durdurmaz.

---

## P2

### B9 · Posta kutusu isteği durum kontrolü yok
- [x] **Sorun:** Reddedilmiş istek karşılandı yapılabiliyor, aynı istek iki kez işlenip çift audit yazılıyor.
- **Kanıt:** `SiteController::fulfillMailboxRequest` / `rejectMailboxRequest` durum okumuyor.
- **Yapılacak:** Yalnız `pending` istek işlenir, aksi halde hata flash.
- **Kabul:** İşlenmiş isteğe ikinci POST durumu değiştirmez, audit yazmaz.

### B10 · Operatöre giden İngilizce sabit metinler
- [ ] **Sorun:** Türkçe arayüzde İngilizce hata ve başarı mesajları çıkıyor.
- **Kanıt:** `SiteController::checkHealth` ek cümlesi, `SiteThemeController` tüm flash'lar, `ChannelSwitcher` ve `SiteProvisioner::start` istisna mesajları.
- **Yapılacak:** Lang anahtarlarına taşı (tr + en).
- **Kabul:** Bu yollarda `__()` dışı operatör metni kalmaz, testler çeviri anahtarıyla doğrular.

### B11 · Arşivlenen site geri alınamıyor
- [ ] **Sorun:** Arşiv UI'dan geri döndürülemiyor, listede görünmüyor. Coolify uygulaması çalışmaya devam ediyor ama Plane'de iz yok.
- **Kanıt:** `restore` / `onlyTrashed` kullanan route yok. `sites.flash.archived` "Coolify'e dokunulmadı" diyor.
- **Yapılacak:** Sites listesine "Arşiv" görünümü (`?archived=1`), satırda "Geri yükle" (Super Admin). Geri yüklemede B3'teki domain satırları yeniden kurulur, domain başka sitede kullanılıyorsa 422.
- **Kabul:** Arşivli site listelenir, geri yüklenince normal listede döner.
