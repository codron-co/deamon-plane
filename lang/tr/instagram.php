<?php

return [
    'title' => 'Instagram',
    'lede' => 'Deamon CMS sitelerindeki «Instagram ile bağlan» bu Meta uygulaması üzerinden çalışır. Uygulama sırrı yalnız Plane\'de şifreli saklanır; müşteri girişini Plane karşılar ve siteye yalnız o hesabın token\'ı imzalı agent ile gider.',
    'save' => 'Kaydet',
    'push' => 'Sitelere yeniden aktar',
    'form_errors' => 'İşaretli alanları düzeltip tekrar deneyin.',
    'state_label' => 'Durum',
    'state' => ['ready' => 'Hazır', 'missing' => 'Eksik'],
    'state_hint' => [
        'ready' => 'Uygulama kimliği ve sırrı kayıtlı; CMS 1.2.76+ sitelerde «Instagram ile bağlan» görünür.',
        'missing' => 'Uygulama kimliği veya sırrı yok. Siteler yalnız elle token ile bağlanabilir.',
    ],
    'app' => [
        'title' => 'Meta uygulaması',
        'hint' => 'developers.facebook.com → uygulama → Kullanım durumları → Instagram API → «Instagram girişiyle API kurulumu» sayfasındaki Instagram uygulama kimliği ve sırrı (Facebook uygulama kimliği değil).',
    ],
    'fields' => [
        'app_id' => 'Instagram uygulama kimliği',
        'app_id_hint' => 'Yalnız rakamlardan oluşur.',
        'app_secret' => 'Instagram uygulama sırrı',
        'app_secret_hint' => 'Meta panelinde «Göster» ile görülür. Şifreli saklanır, burada bir daha gösterilmez.',
        'secret_saved' => 'Kayıtlı. Değiştirmek için yenisini yazın; boş bırakırsanız mevcut değer korunur.',
    ],
    'meta' => [
        'title' => 'Meta panelinde girilecek adresler',
        'hint' => 'Uygulama ayarlarında aşağıdaki adresleri kullanın.',
        'redirect_uri' => 'Geçerli OAuth yönlendirme URI\'si (Instagram girişi ayarları)',
        'data_deletion' => 'Veri silme talimatları URL\'si (Uygulama ayarları → Temel)',
        'privacy' => 'Gizlilik politikası URL\'si (Uygulama ayarları → Temel)',
        'terms' => 'Hizmet koşulları URL\'si (Uygulama ayarları → Temel)',
        'deauthorize' => 'Yetki kaldırma (deauthorize) callback URL\'si (Instagram işletme girişi ayarları)',
        'deletion_request' => 'Veri silme isteği URL\'si (Instagram işletme girişi ayarları)',
        'scope' => 'İzin: instagram_business_basic',
    ],
    'rollout' => [
        'title' => 'Siteler',
        'supported' => ':count site CMS 1.2.76+ çalıştırıyor (Instagram modülünü destekliyor).',
        'connected' => 'Instagram hesabı bağlı siteler: :count',
        'none' => 'Henüz bağlı site yok.',
        'last_pushed' => 'son aktarım :time',
    ],
    'flash' => [
        'saved' => 'Instagram ayarları kaydedildi ve sitelere aktarılıyor.',
        'push_queued' => 'Instagram ayarı sitelere yeniden aktarılıyor.',
    ],
    'public' => [
        'error_title' => 'Instagram bağlantısı başlatılamadı',
        'error_lede' => 'Bağlantı süresi dolmuş ya da geçersiz olabilir. Sitenizin yönetim panelinde Entegrasyonlar → Instagram sayfasına dönüp «Instagram ile bağlan»a yeniden basın.',
        'error_not_configured' => 'Instagram bağlantısı şu an kullanılamıyor. Lütfen daha sonra tekrar deneyin.',
        'deletion_title' => 'Instagram verilerinizin silinmesi',
        'deletion_lede' => 'Deamon CMS ile yapılmış bir web sitesine Instagram hesabınızı bağladıysanız, site yalnız profil adınızı ve son gönderilerinizin açıklamalarını, bağlantılarını ve görsellerini saklar; bunları yalnız o sitede göstermek için kullanır.',
        'deletion_steps_title' => 'Verilerinizi silmek için',
        'deletion_step_1' => 'Sitenin yönetim panelinde Entegrasyonlar → Instagram sayfasında «Bağlantıyı kaldır»a basın. Token ve siteye alınan gönderiler ve görseller hemen silinir; bir kampanya sayfasında kullanılan görseller o sayfayı silene kadar kalır.',
        'deletion_step_2' => 'Instagram\'da Ayarlar → Web sitesi izinleri (Uygulamalar ve web siteleri) bölümünden uygulamanın erişimini kaldırın. Instagram bize bildirir; token ve siteye alınan tüm gönderiler ve görseller, kampanya görselleri dahil, silinir.',
        'deletion_step_3' => 'Site yönetimine erişiminiz yoksa info@codron.co adresine Instagram kullanıcı adınızla yazın; verileriniz 7 gün içinde silinir ve size bilgi verilir.',
    ],
    'requests' => [
        'title' => 'Meta\'dan gelen silme istekleri',
        'hint' => 'Yetki kaldırma ve veri silme callback\'leri. «Elle» durumunda hiçbir siteye otomatik ulaşılamadı: 7 gün içinde elle kaldırın.',
        'none' => 'Henüz istek yok.',
        'type' => ['deletion' => 'Veri silme', 'deauthorize' => 'Erişim kaldırıldı'],
        'status' => ['received' => 'Alındı', 'completed' => 'Silindi', 'manual' => 'Elle'],
    ],
    'status' => [
        'title' => 'Instagram veri silme isteği',
        'code' => 'Onay kodu: :code',
        'received' => 'alındı :date',
        'state' => [
            'received' => 'İsteğiniz alındı; bağlı web sitesindeki veriler siliniyor.',
            'completed' => 'Tamamlandı: erişim anahtarı ve siteye alınan tüm gönderiler ve görseller bağlı web sitesinden silindi.',
            'manual' => 'İsteğiniz alındı; silme işlemini ekibimiz elle tamamlıyor. İstekten itibaren en geç 7 gün içinde biter.',
        ],
        'completed_at' => 'Tamamlandı: :date',
        'contact' => 'Sorular için: info@codron.co (onay kodunu yazın).',
    ],
    'legal' => [
        'updated' => 'Son güncelleme: 27 Ekim 2026',
        'nav' => 'Yasal',
        'links' => [
            'privacy' => 'Gizlilik politikası',
            'terms' => 'Kullanım koşulları',
            'deletion' => 'Veri silme',
        ],
    ],
    'privacy' => [
        'title' => 'Gizlilik politikası — Instagram bağlantısı',
        'lede' => 'Bu politika, bir işletme Instagram profesyonel hesabını Deamon CMS ile yapılmış web sitesine bağladığında Deamon uygulamasının hangi verileri aldığını, bunları ne için kullandığını ve nasıl sildiğini açıklar.',
        'sections' => [
            [
                'heading' => '1. Biz kimiz',
                'paragraphs' => [
                    'Deamon CMS, CodRon (Kanat Medya, Türkiye) tarafından geliştirilen ve işletilen bir web sitesi platformudur. «Deamon» Meta uygulaması ve plane.codron.co adresindeki bağlantı hizmeti bu platformun parçasıdır.',
                    'Her web sitesi Deamon CMS kullanan bir işletmeye (müşterimize) aittir. Instagram hesabını bağlayıp bağlamamaya işletme karar verir; veriyi yalnız onun adına ve yalnız web sitesini çalıştırmak için işleriz. İletişim: info@codron.co.',
                ],
            ],
            [
                'heading' => '2. Instagram\'dan aldığımız veriler',
                'paragraphs' => [
                    'Tek bir izin isteriz: instagram_business_basic. Bu izin, hesap sahibi Instagram ile giriş yapıp onay verdikten sonra geçerli olur. Bu izinle şunları alırız:',
                ],
                'items' => [
                    'Hesap bilgileri: Instagram kullanıcı kimliği, kullanıcı adı ve hesap türü.',
                    'Hesabın kendi gönderileri: gönderi kimliği, açıklama, medya türü, görsel ya da video adresi, küçük resim, Instagram\'daki gönderi bağlantısı, yayın zamanı ve albüm gönderilerinin öğeleri.',
                    'Web sitesinin bu listeyi yenilemesini sağlayan erişim anahtarı (token). 60 gün geçerlidir ve kendiliğinden yenilenir.',
                ],
            ],
            [
                'heading' => '3. Erişmediğimiz veriler',
                'paragraphs' => [
                    'Instagram şifresini hiçbir zaman görmeyiz ve saklamayız. Direkt mesajlara, yorumlara, takipçilere, beğenilere, istatistiklere, e-posta adresine ya da başka Instagram kullanıcılarına ait hiçbir veriye erişmeyiz. Uygulama Instagram\'da hiçbir şey paylaşamaz, düzenleyemez ya da silemez.',
                ],
            ],
            [
                'heading' => '4. Verileri nasıl kullanırız',
                'items' => [
                    'Bağlı hesabın kendi son gönderilerini işletmenin kendi web sitesinde, her gönderinin Instagram\'a bağlantı verdiği bir Instagram bölümünde göstermek için.',
                    'Site sahibi kampanya modülünü açarsa, tarihli bir kampanya duyuran gönderilerini aynı sitede kampanya sayfasına dönüştürmek için.',
                    'Bağlantı durumunu (kullanıcı adı, son yenileme) sitenin yöneticilerine göstermek için.',
                ],
                'notes' => [
                    'Site sahibi istediği gönderiyi gizleyebilir ya da bağlantıyı her an kaldırabilir. Instagram verilerini reklam, profilleme, analiz ya da model eğitimi için kullanmayız; hiçbir zaman satmaz ve kiralamayız.',
                ],
            ],
            [
                'heading' => '5. Veriler nerede saklanır, kim görür',
                'items' => [
                    'Erişim anahtarı, sunucularımızdaki web sitesi veritabanında şifreli saklanır; yönetim panelinde hiçbir zaman gösterilmez.',
                    'plane.codron.co Instagram girişini yürütür, anahtarı imzalı bir bağlantıyla siteye bir kez iletir ve yalnız bir denetim kaydı (kullanıcı adı ve zaman) tutar; anahtarı saklamaz.',
                    'Sayfa hızlı açılsın diye gönderi görselleri sitenin medya kütüphanesine kopyalanır; video dosyaları indirilmez.',
                    'Açıklamalar ve görseller, herkese açık Instagram profilinde olduğu gibi web sitesinde de herkese açık görünür.',
                ],
                'notes' => [
                    'Verileri üçüncü kişilerle paylaşmayız; yalnız sunucularımızı talimatımızla çalıştıran barındırma sağlayıcıları ve yasal zorunluluk hâli bunun dışındadır.',
                ],
            ],
            [
                'heading' => '6. Ne kadar süre saklarız',
                'paragraphs' => [
                    'Veriler yalnız hesap bağlı kaldığı sürece saklanır. Site listeyi yaklaşık saatte bir yeniler; Instagram\'da silinen ya da listeden düşen gönderi bir sonraki yenilemede siteden de silinir. Tek istisna, site sahibinin kampanya sayfasına dönüştürdüğü gönderidir; sahibi silene kadar o sayfanın içeriği olarak kalır.',
                    'Site sahibi bağlantıyı kaldırınca anahtar ve siteye alınan gönderiler ve görseller hemen silinir; bir kampanya sayfasında kullanılan görseller, sahibi o sayfayı silene kadar sayfanın içeriği olarak kalır.',
                    'Instagram\'da erişim kaldırılınca ya da bize bir veri silme isteği ulaşınca (Instagram üzerinden veya e-postayla) anahtarı ve siteye alınan tüm gönderileri ve görselleri, kampanya görselleri dahil, sileriz: otomatik olarak dakikalar içinde, en geç 7 gün içinde.',
                ],
            ],
            [
                'heading' => '7. Seçenekleriniz ve haklarınız',
                'items' => [
                    'Bağlantıyı kaldırma: site yönetiminde Entegrasyonlar → Instagram → «Bağlantıyı kaldır».',
                    'Instagram\'da erişimi kaldırma: Ayarlar → Web sitesi izinleri (Uygulamalar ve web siteleri) → «Deamon»u kaldırın.',
                    'Bize yazma: Instagram kullanıcı adınızla info@codron.co adresine yazın; verileri 7 gün içinde siler ve size bilgi veririz.',
                ],
                'notes' => [
                    '6698 sayılı Kişisel Verilerin Korunması Kanunu (KVKK) ve uygulandığı durumlarda AB Genel Veri Koruma Tüzüğü (GDPR) kapsamında verilerinize erişme, düzeltme, silme ve işlenmesine itiraz etme haklarınızı info@codron.co adresi üzerinden kullanabilirsiniz. Adım adım talimatlar veri silme sayfasındadır.',
                ],
            ],
            [
                'heading' => '8. Güvenlik',
                'paragraphs' => [
                    'Tüm trafik HTTPS ile şifrelenir. Erişim anahtarı şifreli saklanır, bağlantı linkleri imzalı ve tek kullanımlıktır; bağlantıyı yalnız sitenin yöneticileri yönetebilir.',
                ],
            ],
            [
                'heading' => '9. Değişiklikler',
                'paragraphs' => [
                    'Bu politika değişirse bu sayfayı ve üstteki tarihi güncelleriz. İletişim: info@codron.co.',
                ],
            ],
        ],
    ],
    'terms' => [
        'title' => 'Kullanım koşulları — Instagram bağlantısı',
        'lede' => 'Bu koşullar, bir işletme Instagram profesyonel hesabını Deamon uygulaması üzerinden Deamon CMS ile yapılmış web sitesine bağladığında geçerlidir.',
        'sections' => [
            [
                'heading' => '1. Hizmet',
                'paragraphs' => [
                    'CodRon (Kanat Medya, Türkiye) tarafından işletilen Deamon CMS, bir işletmenin kendi Instagram gönderilerini kendi web sitesinde göstermesini sağlar. Bu koşullar yalnız Instagram bağlantısını kapsar; web sitesinin kendisi CodRon ile işletme arasındaki sözleşmeye tabidir.',
                ],
            ],
            [
                'heading' => '2. Kimler bağlayabilir',
                'paragraphs' => [
                    'Bağladığınız Instagram profesyonel hesabının (İşletme ya da İçerik Üreticisi) ve bağladığınız web sitesinin sahibi ya da yönetmeye yetkili kişisi olmalısınız.',
                ],
            ],
            [
                'heading' => '3. İçeriğiniz',
                'paragraphs' => [
                    'Gönderilerinizin tüm hakları sizde kalır. Bağlanarak web sitesinin gönderilerinizi ve görsellerinizi çekmesine ve yalnız o sitede göstermesine izin verirsiniz. Instagram\'da paylaştığınız içerikten ve bu içeriğin sitenizde yer alabilmesinden siz sorumlusunuz.',
                ],
            ],
            [
                'heading' => '4. Instagram ve Meta kuralları',
                'paragraphs' => [
                    'Kullanımınız Instagram Kullanım Koşulları\'na ve Meta Platform Koşulları\'na da uygun olmalıdır. Meta ya da yasa gerektirirse bağlantıyı durdurabiliriz.',
                ],
            ],
            [
                'heading' => '5. Erişilebilirlik',
                'paragraphs' => [
                    'Bağlantı Instagram\'ın hizmetine bağlıdır. Gönderiler gecikmeli görünebilir; Instagram erişimi değiştirir ya da kaldırırsa akış durabilir. Yasanın izin verdiği ölçüde bu kesintilerden doğan dolaylı zararlardan sorumlu değiliz.',
                ],
            ],
            [
                'heading' => '6. Bağlantının sona ermesi',
                'paragraphs' => [
                    'Bağlantıyı site yönetiminden ya da Instagram\'da uygulamanın erişimini kaldırarak her an sonlandırabilirsiniz. Verileriniz gizlilik politikasında anlatıldığı gibi silinir. Entegrasyonu önceden haber vererek sonlandırabiliriz.',
                ],
            ],
            [
                'heading' => '7. Hukuk ve iletişim',
                'paragraphs' => [
                    'Bu koşullar Türkiye Cumhuriyeti hukukuna tabidir. Sorular için: info@codron.co.',
                ],
            ],
        ],
    ],
];
