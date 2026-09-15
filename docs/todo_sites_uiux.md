# Sites — UI/UX denetimi (todo)

**Tarih:** 2026-09-15
**Kapsam:** `/sites` listesi, oluştur / düzenle formu, site detay (hero, sekmeler, altyapı kartları, tehlike bölümü), arşiv sayfası.
**Yöntem:** Blade şablonları, `public/js/*` davranışı ve Türkçe dil dosyaları okundu. Her madde şablonda doğrulandı.
**Kural:** Her madde ayrı commit. Test + Pint + `node --test` yeşil olmadan işaretlenmez.

Öncelik: **P0** operatör işini yapamıyor veya yanlış bilgi görüyor · **P1** belirgin sürtünme · **P2** tutarlılık.

---

## P0

### U1 · Oluştur / düzenle formunda doğrulama hataları alan yanında görünmüyor
- [x] **Sorun:** Form fetch ile gönderiliyor. Hata olunca tüm mesajlar birleşik tek bir bildirim olarak çıkıyor, alanın altında kırmızı yazı yok, girilen değerler sayfada kalıyor ama sunucu `old()` ile doldurmuyor. "Ek domain'ler … üzerinde kalmalı" raporu bu yüzden bildirim olarak geldi.
- **Kanıt:** `layouts/ops.blade.php` → `<body class="ops-app">`. `ops-async.js` `.ops-app` içindeki her POST formunu yakalıyor, yalnız `data-ops-native` atlanıyor. `sites/create.blade.php` ve `sites/edit.blade.php` formlarında bu işaret yok. 422'de `flattenErrors()` bildirime yazıyor.
- **Yapılacak:** İki forma `data-ops-native`. Gönderimde butonu kilitleyen ve "Kaydediliyor…" gösteren küçük bir native bekleme davranışı.
- **Kabul:** Hatalı gönderimde sayfa yeniden yüklenir, hata ilgili alanın altında görünür, girilen değerler korunur.

### U2 · Site detay sayfası açılırken Coolify'ı bekliyor
- [ ] **Sorun:** Altyapı sekmesindeki Coolify kartı, şablon render edilirken Coolify API'ye senkron çağrı yapıyor. Coolify yavaşsa veya kısıtlıyorsa detay sayfası o kadar gecikiyor. B6'da admin paneli için çözülen sorunun aynısı.
- **Kanıt:** `sites/_coolify-ops.blade.php` → `@php` içinde `CoolifyDeploySettings::snapshot($site)`.
- **Yapılacak:** Kartı fragment uç noktasından sekme ilk açıldığında yükle (B6 deseni). Sayfa GET'i Coolify'a istek atmasın.
- **Kabul:** Detay GET'i Coolify'a istek göndermez. Fragment oto-deploy, pin durumu ve hata durumunu döndürür.

### U3 · Silme aksiyonlarında bekleme durumu yok
- [x] **Sorun:** Tehlike sekmesindeki ve Ayarlar menüsündeki Arşivle / Kalıcı sil formları onaydan sonra hiçbir geri bildirim vermiyor. Kalıcı silme saniyeler sürüyor, ikinci tıklama ikinci isteği atıyor.
- **Kanıt:** `sites/show.blade.php` tehlike bölümü ve `sites/_header-actions.blade.php` silme formlarında `data-ops-pending` ve `data-pending-label` yok. Diğer tüm aksiyon formlarında var.
- **Yapılacak:** Dört forma bekleme işareti ve etiket.
- **Kabul:** Onaydan sonra buton kilitlenir ve "İşleniyor…" gösterir.

---

## P1

### U4 · Türkçe arayüzde İngilizce etiketler
- [x] **Sorun:** Türkçe panelde "Hard Delete", "Soft Delete", "Live Sync", "Sync" yazıyor. Aynı ekranda "Kalıcı sil" (arka plan işi) ile "Hard Delete" (menü) yan yana farklı adla geçiyor.
- **Kanıt:** `lang/tr/sites.php` → `menu.soft_delete`, `menu.hard_delete`, `menu.sync`, `live.sync`, `live.confirm_title`, `danger.lede`, `danger.hard_confirm*`, `flash.live_sync_get`.
- **Yapılacak:** Türkçe karşılıklar: Arşivle, Kalıcı sil, Canlı kontrol, Senkron. Onay ve açıklama metinleri aynı kelimeleri kullansın.
- **Kabul:** `lang/tr` içinde bu dört İngilizce etiket kalmaz, ilgili testler çeviri anahtarıyla doğrular.

### U5 · Formda takma ad satırı kaldırılamıyor, hataların çoğu görünmüyor
- [x] **Sorun:** "Domain ekle" satır ekliyor ama satır silmenin yolu yok, yalnız metni temizlemek. 20 takma ada izin var ama hata yalnız ilk iki satır için basılıyor, üçüncü satırdaki hata hiçbir yerde görünmüyor.
- **Kanıt:** `sites/_form.blade.php` → yalnız `@error('aliases.0')` ve `@error('aliases.1')`. `public/js/sites-aliases.js` yalnız ekleme yapıyor.
- **Yapılacak:** Her satırın altında kendi `@error("aliases.$index")`. Her satırda "Kaldır" butonu (JS), son satır silinince boş bir satır kalsın.
- **Kabul:** Üçüncü takma ad hatası kendi satırında görünür. Satır kaldırılınca form o değeri göndermez.

### U6 · Toplu işlem çubuğu aşırı kalabalık
- [x] **Sorun:** Seçim yapınca tek satırda 11 kontrol çıkıyor: dal seçimi, dal değiştir, yayına al, compose'a geç, oto deploy aç, oto deploy kapat, yeniden deploy, HEAD'i takip et, pin alanı, pin, agent secret. Dar ekranda satır taşıyor, benzer aksiyonlar ayrı butonlarda.
- **Kanıt:** `sites/_region.blade.php` → `.sites-bulk-actions` grubu.
- **Yapılacak:** Deploy aksiyonlarını (yeniden deploy, HEAD'i takip et, pin) bir "Deploy" menüsüne, oto deploy aç/kapat'ı bir "Oto deploy" menüsüne topla. Onay metinleri ve `formaction`'lar aynı kalsın.
- **Kabul:** Çubukta en fazla 6 üst düzey kontrol kalır, tüm aksiyonlar ve onayları çalışır.

### U7 · Liste satırında boş düzeltme menüsü
- [x] **Sorun:** Sorunsuz her satırda da "Uygulama düzeltmeleri" menüsü duruyor, açınca "Sorun yok" yazıyor. 25 satırda 25 anlamsız düğme.
- **Kanıt:** `sites/_region.blade.php` → `@can('update')` altında menü, `$rowFixes === []` dalı yalnız etiket gösteriyor.
- **Yapılacak:** Düzeltme yoksa menüyü render etme.
- **Kabul:** Sorunsuz satırda menü yok, sorunlu satırda aynen var.

### U8 · Agent sağlık rozeti ham hata kodu gösteriyor
- [x] **Sorun:** Rozet "Hatalı · timeout", "Hatalı · http_error" gibi iç kod gösteriyor.
- **Kanıt:** `sites/_agent-health.blade.php` → `$statusLabel = … .' · '.$reason`.
- **Yapılacak:** Nedenleri dil anahtarına çevir, bilinmeyen kod için genel metin.
- **Kabul:** Rozette çevrilmiş neden görünür.

### U9 · Yazılım maili bildirim tablosu ham değerler gösteriyor
- [x] **Sorun:** Haftalık rapor günü 0–6, saati 0–23 sayı kutusu. Hangi günün 0 olduğu belli değil. Sürüm eşiği `patch / minor / major` ham seçenek ve stilsiz select. Erişilebilirlik etiketleri İngilizce `day` / `hour`.
- **Kanıt:** `sites/_platform-mail.blade.php` satır 52–58.
- **Yapılacak:** Gün için Pazartesi…Pazar select, saat için 00:00…23:00 select, sürüm eşiği için çevrilmiş etiketler, `field-input` sınıfı, Türkçe `aria-label`.
- **Kabul:** Tabloda ham sayı ve İngilizce etiket kalmaz, gönderilen değerler değişmez.

---

## P2

### U10 · Formdaki mail sunucusu seçimi stilsiz
- [x] **Sorun:** Diğer tüm seçimler `field-input` sınıfı taşıyor, mail sunucusu seçimi taşımıyor ve farklı görünüyor.
- **Kanıt:** `sites/_form.blade.php` → `<select id="site_mail_server" name="mail_server_id">`.
- **Yapılacak:** `class="field-input"`.
- **Kabul:** Seçim diğer alanlarla aynı görünür.

### U11 · Mail metrik kartı tema ikonunu kullanıyor
- [x] **Sorun:** Genel bakıştaki "Mail" kartı ile "Tema" kartı aynı ikonla çıkıyor.
- **Kanıt:** `sites/show.blade.php` → mail kartında `site-metric-icon is-theme`.
- **Yapılacak:** Mail için ayrı ikon sınıfı ve CSS.
- **Kabul:** İki kart farklı ikon gösterir.

### U12 · Tehlike bölümü arşivden habersiz
- [x] **Sorun:** Açıklama "Soft Delete Plane kaydını gizler" diyor. Arşiv sayfası (B11) var ama bu bölüm oradan bahsetmiyor, operatör siteyi nerede bulacağını bilmiyor.
- **Kanıt:** `lang/tr/sites.php` → `danger.lede`, `danger.confirm`.
- **Yapılacak:** Metin "Arşivle" dilini kullansın ve arşiv sayfasına link versin.
- **Kabul:** Tehlike bölümünde arşiv sayfası linki görünür.
