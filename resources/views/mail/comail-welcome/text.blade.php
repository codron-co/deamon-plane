@php($ports = $w['ports'])
{{ $w['firstName'] ? 'Merhaba '.$w['firstName'].',' : 'Merhaba,' }}

Yeni e-posta adresiniz açıldı: {{ $w['address'] }}

Şifrenizi hesabı açan yöneticiden alın. Güvenliğiniz için şifreyi e-postayla göndermiyoruz.
@if($w['forcePasswordChange'])

İlk girişte sizden yeni bir şifre belirlemeniz istenecek. Önce web’den bir kez giriş yapıp yeni şifrenizi belirleyin; telefon ve Outlook kurulumunu bu yeni şifreyle yapın.
@endif


WEB’DEN KULLANIN
Kurulum gerekmez. Tarayıcıda açın, e-posta adresinizi ve şifrenizi yazıp giriş yapın:
{{ $w['webmailUrl'] }}


IPHONE VE IPAD
1. Ayarlar > Uygulamalar > Mail > Mail Hesapları > Hesap Ekle (iOS 17 ve öncesinde: Ayarlar > Mail > Hesaplar > Hesap Ekle).
2. Diğer > Mail Hesabı Ekle.
3. Adınızı, e-posta adresinizi ve şifrenizi yazıp İleri’ye dokunun.
4. IMAP seçili kalsın. Gelen ve Giden Posta Sunucusu bölümlerinin ikisine de: Ana Bilgisayar Adı {{ $w['host'] }}, Kullanıcı Adı tam e-posta adresiniz, Parola şifreniz.
5. İleri, ardından Kaydet.
6. Mail uygulamasını açın; hesabınız posta kutuları listesinde görünür.


ANDROID (GMAIL)
1. Gmail > sağ üstteki profil resmi > Başka bir hesap ekle.
2. Diğer > e-posta adresiniz > Manuel kurulum.
3. Kişisel (IMAP) > şifreniz > İleri.
4. Gelen sunucu: {{ $w['host'] }}, bağlantı noktası {{ $ports['imap'] }}, SSL/TLS.
5. Giden sunucu: {{ $w['host'] }}, bağlantı noktası {{ $ports['smtp'] }}, SSL/TLS.
6. Sonraki ekranlarda İleri; hesabınız Gmail’de görünür.


OUTLOOK
@if($w['autodiscover'])
Outlook çoğu zaman ayarları kendisi bulur ve yalnızca şifrenizi sorar. Bulamazsa 3. adımdan devam edin.
@endif
1. Yeni Outlook: Ayarlar > Hesaplar > Hesap ekle. Klasik Outlook: Dosya > Hesap Ekle.
2. E-posta adresinizi yazıp Devam’a (klasik Outlook’ta Bağlan’a) tıklayın.
3. Hesap türü sorulursa IMAP’i seçin.
4. Gelen: {{ $w['host'] }}, {{ $ports['imap'] }}, SSL/TLS. Giden: {{ $w['host'] }}, {{ $ports['smtp'] }}, SSL/TLS. Kullanıcı adı: tam e-posta adresiniz.
5. Şifrenizi yazıp Bağlan, ardından Bitti.
Telefondaki Outlook: profil simgesi > Ayarlar > Hesap ekle > E-posta hesabı ekle; hesap türü sorulursa IMAP.


SUNUCU BİLGİLERİ
Kullanıcı adı: {{ $w['address'] }}
Gelen posta (IMAP): {{ $w['host'] }}, {{ $ports['imap'] }}, SSL/TLS
Giden posta (SMTP): {{ $w['host'] }}, {{ $ports['smtp'] }}, SSL/TLS (olmazsa {{ $ports['smtp_alt'] }}, STARTTLS)
POP3 (yalnız gerekirse): {{ $w['host'] }}, {{ $ports['pop3'] }}, SSL/TLS


SORUN YAŞARSANIZ
- Şifre kabul edilmiyor: kullanıcı adı tam e-posta adresiniz olmalı; büyük, küçük harflere dikkat edin. Arka arkaya birkaç kez yanlış şifre girilirse giriş yarım saat kadar engellenir; bu sürede yeniden denemeyin.
- Gönderemiyorum: giden sunucuda {{ $ports['smtp'] }} SSL/TLS yerine {{ $ports['smtp_alt'] }} STARTTLS deneyin; giden sunucuda da kullanıcı adı ve şifre yazılı olmalı.
- Beklediğim e-posta gelmedi: Önemsiz (Spam) klasörüne bakın.

Takıldığınız bir yer olursa bu e-postayı yanıtlayın ya da {{ $w['supportEmail'] }} adresine yazın.

CodRon ekibi
