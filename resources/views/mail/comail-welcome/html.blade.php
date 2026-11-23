{{--
    CoMail hoş geldin e-postası (App\Mail\ComailWelcomeMail). Deamon e-posta ailesinin dili
    (CMS resources/views/mail/branded): 560 px tablo, satır içi stil, sistem fontu, beyaz kart,
    iOS Ayarlar tarzı 36 px ikonlu bölüm başlıkları, koyu mod. Outlook masaüstü için mso tabloları.
    $w = ComailWelcome::data(). Şifre hiçbir zaman bu şablona gelmez.
--}}
@php
    $font = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Helvetica, Arial, sans-serif";
    $mono = "ui-monospace, SFMono-Regular, Menlo, Consolas, 'Courier New', monospace";
    $ports = $w['ports'];
    // **kalın** -> <strong>; metin önce kaçışlanır, yalnız bu biçim HTML olur.
    $md = static fn (string $text): string => preg_replace(
        ['/\*\*(.+?)\*\*/u', '/`(.+?)`/u'],
        ['<strong style="font-weight:600; color:inherit;">$1</strong>', '<span style="font-family:'.e($mono).'; font-size:14px;">$1</span>'],
        e($text),
    );
    $p = 'margin:0 0 16px; font-family:'.$font.'; font-size:16px; line-height:25px; color:#1d1d1f;';

    $sections = [
        [
            'icon' => 'iphone',
            'title' => 'iPhone ve iPad',
            'intro' => 'Telefonunuzdaki Mail uygulamasına hesabı bir kez ekleyin.',
            'steps' => [
                '**Ayarlar**’ı açın ve **Uygulamalar** > **Mail** > **Mail Hesapları** > **Hesap Ekle**’ye dokunun. iOS 17 ve öncesinde yol: **Ayarlar** > **Mail** > **Hesaplar** > **Hesap Ekle**.',
                'Listenin en altındaki **Diğer**’e, ardından **Mail Hesabı Ekle**’ye dokunun.',
                'Adınızı, e-posta adresinizi ve şifrenizi yazıp **İleri**’ye dokunun.',
                'Üstte **IMAP** seçili kalsın. **Gelen Posta Sunucusu** ve **Giden Posta Sunucusu** bölümlerinin ikisine de aynı bilgileri yazın: **Ana Bilgisayar Adı** `'.$w['host'].'`, **Kullanıcı Adı** tam e-posta adresiniz, **Parola** şifreniz.',
                '**İleri**’ye dokunun, telefon bilgileri kontrol eder. Son ekranda **Kaydet**’e dokunun.',
                'Mail uygulamasını açın; yeni hesabınız posta kutuları listesinde görünür.',
            ],
        ],
        [
            'icon' => 'android',
            'title' => 'Android',
            'intro' => 'Anlatım Gmail uygulamasına göredir; telefonunuzdaki başka bir e-posta uygulamasında da aynı bilgiler kullanılır.',
            'steps' => [
                '**Gmail** uygulamasını açın. Sağ üstteki profil resminize dokunup **Başka bir hesap ekle**’yi seçin.',
                '**Diğer**’e dokunun, e-posta adresinizi yazın ve **Manuel kurulum**’u seçin.',
                '**Kişisel (IMAP)**’ı seçin, şifrenizi yazıp **İleri**’ye dokunun.',
                'Gelen sunucu ayarlarında **Sunucu** alanına `'.$w['host'].'` yazın. Bağlantı noktası **'.$ports['imap'].'**, güvenlik türü **SSL/TLS** olsun. **İleri**’ye dokunun.',
                'Giden sunucu ayarlarında da **Sunucu** alanına `'.$w['host'].'` yazın. Bağlantı noktası **'.$ports['smtp'].'**, güvenlik türü **SSL/TLS** olsun. **İleri**’ye dokunun.',
                'Sonraki ekranları olduğu gibi bırakıp **İleri**’ye dokunun; hesabınız Gmail’de görünür.',
            ],
        ],
        [
            'icon' => 'outlook',
            'title' => 'Outlook',
            'intro' => $w['autodiscover']
                ? 'Outlook çoğu zaman ayarları kendisi bulur ve yalnızca şifrenizi sorar. Bulamazsa 3. adımdan devam edin.'
                : 'Bilgisayarda Outlook’a hesabı şöyle ekleyin.',
            'steps' => array_values(array_filter([
                'Outlook’u açın. Yeni Outlook’ta **Ayarlar** > **Hesaplar** > **Hesap ekle**’ye, klasik Outlook’ta **Dosya** > **Hesap Ekle**’ye tıklayın.',
                $w['autodiscover']
                    ? 'E-posta adresinizi yazıp **Devam**’a (klasik Outlook’ta **Bağlan**’a) tıklayın. Outlook şifrenizi sorarsa yazın; kurulum burada biter.'
                    : 'E-posta adresinizi yazıp **Devam**’a (klasik Outlook’ta **Bağlan**’a) tıklayın.',
                'Hesap türü sorulursa **IMAP**’i seçin.',
                'Gelen posta için sunucu `'.$w['host'].'`, bağlantı noktası **'.$ports['imap'].'**, şifreleme **SSL/TLS** yazın. Giden posta için sunucu `'.$w['host'].'`, bağlantı noktası **'.$ports['smtp'].'**, şifreleme **SSL/TLS** yazın. Kullanıcı adı her ikisinde de tam e-posta adresiniz olsun.',
                'Şifrenizi yazıp **Bağlan**’a, ardından **Bitti**’ye tıklayın.',
            ])),
            'after' => 'Telefondaki Outlook uygulamasında: sol üstteki profil simgesine, sonra **Ayarlar** > **Hesap ekle** > **E-posta hesabı ekle**’ye dokunun. Adresinizi yazıp **Devam**’a dokunun; hesap türü sorulursa **IMAP**’i seçip yukarıdaki sunucu bilgilerini girin.',
        ],
    ];

    $settings = [
        ['label' => 'Kullanıcı adı', 'value' => $w['address'], 'hint' => 'Her yerde tam e-posta adresiniz'],
        ['label' => 'Gelen posta (IMAP)', 'value' => $w['host'], 'hint' => 'Bağlantı noktası '.$ports['imap'].' · SSL/TLS'],
        ['label' => 'Giden posta (SMTP)', 'value' => $w['host'], 'hint' => 'Bağlantı noktası '.$ports['smtp'].' · SSL/TLS (olmazsa '.$ports['smtp_alt'].' · STARTTLS)'],
        ['label' => 'POP3 (yalnız gerekirse)', 'value' => $w['host'], 'hint' => 'Bağlantı noktası '.$ports['pop3'].' · SSL/TLS'],
    ];

    $troubles = [
        ['title' => 'Şifre kabul edilmiyor', 'text' => 'Kullanıcı adına tam e-posta adresinizi yazdığınızdan ve şifredeki büyük, küçük harflerden emin olun. Arka arkaya birkaç kez yanlış şifre girilirse güvenlik için giriş yarım saat kadar engellenir; bu sürede yeniden denemeyin, eski şifreyle kalan bir cihaz varsa onu da kapatın.'],
        ['title' => 'E-posta gönderemiyorum', 'text' => 'Giden sunucuda bağlantı noktasını **'.$ports['smtp'].' · SSL/TLS** yerine **'.$ports['smtp_alt'].' · STARTTLS** yapıp yeniden deneyin. Giden sunucuda da kullanıcı adı ve şifre yazılı olmalı.'],
        ['title' => 'Beklediğim e-posta gelmedi', 'text' => '**Önemsiz** (Spam) klasörüne bakın. Oradaysa iletiyi gelen kutusuna taşıyın; sonrakiler doğrudan gelir.'],
    ];
@endphp
<!DOCTYPE html>
<html lang="tr" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>E-posta hesabınız hazır</title>
    <!--[if mso]>
    <noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
    <style>td, th, div, p, a, h1, h2, h3, span { font-family: Arial, sans-serif !important; }</style>
    <![endif]-->
    <style>
        :root { color-scheme: light dark; supported-color-schemes: light dark; }
        body { margin: 0 !important; padding: 0 !important; width: 100% !important; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table { border-collapse: collapse; mso-table-lspace: 0; mso-table-rspace: 0; }
        img { border: 0; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
        a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; }
        .dm-logo-dark { display: none; }

        @media only screen and (max-width: 600px) {
            .dm-page { padding: 16px 10px 8px !important; }
            .dm-card { padding: 28px 20px !important; border-radius: 14px !important; }
            .dm-title-lg { font-size: 28px !important; line-height: 34px !important; }
            .dm-btn-a { display: block !important; text-align: center !important; }
            .dm-btn-table { width: 100% !important; }
            .dm-address { font-size: 18px !important; line-height: 24px !important; }
            .dm-hero { max-width: 100% !important; }
        }

        @media (prefers-color-scheme: dark) {
            .dm-body, .dm-page { background-color: #000000 !important; }
            .dm-card { background-color: #1c1c1e !important; border-color: #2c2c2e !important; }
            .dm-text, .dm-title { color: #f5f5f7 !important; }
            .dm-muted { color: #a1a1a6 !important; }
            .dm-faint { color: #8e8e93 !important; }
            .dm-surface { background-color: #2c2c2e !important; }
            .dm-divider { border-color: #3a3a3c !important; }
            .dm-link { color: #2997ff !important; }
            .dm-num { background-color: #f5f5f7 !important; color: #1d1d1f !important; }
            .dm-btn-td { background-color: #f5f5f7 !important; }
            .dm-btn-a { background-color: #f5f5f7 !important; color: #1d1d1f !important; border-color: #f5f5f7 !important; }
            .dm-note { background-color: #3a2a12 !important; border-color: #5c4318 !important; }
            .dm-has-dark-logo .dm-logo-light { display: none !important; }
            .dm-has-dark-logo .dm-logo-dark { display: inline-block !important; }
        }

        [data-ogsc] .dm-body, [data-ogsc] .dm-page { background-color: #000000 !important; }
        [data-ogsc] .dm-card { background-color: #1c1c1e !important; }
        [data-ogsc] .dm-text, [data-ogsc] .dm-title { color: #f5f5f7 !important; }
        [data-ogsc] .dm-muted { color: #a1a1a6 !important; }
        [data-ogsc] .dm-surface { background-color: #2c2c2e !important; }
    </style>
</head>
<body class="dm-body" style="margin:0; padding:0; background-color:#f5f5f7; font-family:{{ $font }};">
<div style="display:none; max-height:0; max-width:0; overflow:hidden; opacity:0; mso-hide:all; font-size:1px; line-height:1px; color:#f5f5f7;">{{ $w['address'] }} adresiniz açıldı. Web’den, iPhone’da, Android’de ve Outlook’ta nasıl kullanacağınızı adım adım anlattık.&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="dm-page" style="width:100%; background-color:#f5f5f7;">
    <tr>
        <td align="center" class="dm-page" style="padding:40px 16px 16px; background-color:#f5f5f7;">
            <!--[if mso]><table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:560px;">
                <tr>
                    <td align="left" class="dm-has-dark-logo" style="padding:0 4px 20px;">
                        <a href="https://codron.co" target="_blank" style="text-decoration:none;">
                            <img src="{{ $w['logoUrl'] }}" alt="CodRon" width="155" height="28" class="dm-logo-light" style="display:inline-block; width:155px; height:28px; border:0;">
                            <!--[if !mso]><!--><img src="{{ $w['darkLogoUrl'] }}" alt="CodRon" width="155" height="28" class="dm-logo-dark" style="display:none; width:155px; height:28px; border:0;"><!--<![endif]-->
                        </a>
                    </td>
                </tr>
                <tr>
                    <td class="dm-card" style="background-color:#ffffff; border:1px solid #e5e5ea; border-radius:16px; padding:36px 36px 32px; font-family:{{ $font }}; color:#1d1d1f;">
                        <p class="dm-muted" style="margin:0 0 8px; font-family:{{ $font }}; font-size:13px; line-height:18px; font-weight:500; color:#6e6e73;">CodRon E-posta</p>
                        <h1 class="dm-title dm-title-lg" style="margin:0 0 20px; font-family:{{ $font }}; font-size:34px; line-height:40px; font-weight:700; letter-spacing:-0.8px; color:#1d1d1f;">E-posta hesabınız hazır</h1>
                        <img src="{{ $w['heroUrl'] }}" alt="E-posta, telefon, bilgisayar ve web simgeleri; ortada onay işaretli bir zarf" width="486" class="dm-hero" style="display:block; width:100%; max-width:486px; height:auto; margin:0 0 24px; border:0; border-radius:14px; background-color:#f2f2f5;">

                        <p class="dm-text" style="{{ $p }}">{{ $w['firstName'] ? 'Merhaba '.$w['firstName'].',' : 'Merhaba,' }}</p>
                        <p class="dm-text" style="{{ $p }}">Yeni e-posta adresiniz açıldı. Aşağıda hesabınızı web’den, telefonunuzdan ve Outlook’tan nasıl kullanacağınızı adım adım anlattık. Kurulum birkaç dakika sürer.</p>

                        {{-- Adres ve şifre --}}
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="dm-surface" style="width:100%; margin:8px 0 24px; background-color:#f5f5f7; border-radius:14px; border-collapse:separate;">
                            <tr>
                                <td class="dm-divider" style="padding:16px 18px 14px; border-bottom:1px solid #e5e5ea; font-family:{{ $font }};">
                                    <p class="dm-muted" style="margin:0 0 4px; font-size:12px; line-height:16px; font-weight:500; color:#6e6e73;">E-posta adresiniz</p>
                                    <p class="dm-text dm-address" style="margin:0; font-size:20px; line-height:26px; font-weight:600; letter-spacing:-0.3px; color:#1d1d1f; word-break:break-all;"><a href="mailto:{{ $w['address'] }}" class="dm-text" style="color:#1d1d1f; text-decoration:none;">{{ $w['address'] }}</a></p>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:14px 18px 16px; font-family:{{ $font }};">
                                    <p class="dm-muted" style="margin:0 0 4px; font-size:12px; line-height:16px; font-weight:500; color:#6e6e73;">Şifreniz</p>
                                    <p class="dm-text" style="margin:0; font-size:15px; line-height:22px; color:#1d1d1f;">Şifrenizi hesabı açan yöneticiden alın. Güvenliğiniz için şifreyi e-postayla göndermiyoruz.</p>
                                </td>
                            </tr>
                        </table>

                        @if($w['forcePasswordChange'])
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="dm-note" style="width:100%; margin:0 0 24px; background-color:#fff8eb; border:1px solid #f5dfb3; border-radius:12px; border-collapse:separate;">
                                <tr>
                                    <td class="dm-text" style="padding:14px 16px; font-family:{{ $font }}; font-size:15px; line-height:22px; color:#1d1d1f;">
                                        {!! $md('İlk girişte sizden **yeni bir şifre** belirlemeniz istenecek. Önce aşağıdaki düğmeyle web’den bir kez giriş yapıp yeni şifrenizi belirleyin; telefon ve Outlook kurulumunu bu yeni şifreyle yapın.') !!}
                                    </td>
                                </tr>
                            </table>
                        @endif

                        {{-- Web --}}
                        @include('mail.comail-welcome.heading', ['icon' => $w['icons']['web'], 'title' => 'Web’den kullanın', 'font' => $font])
                        <p class="dm-muted" style="margin:0 0 16px; font-family:{{ $font }}; font-size:15px; line-height:23px; color:#6e6e73;">Kurulum gerekmez. Bilgisayarınızın ya da telefonunuzun tarayıcısında açılır; e-posta adresinizi ve şifrenizi yazıp giriş yapın.</p>
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" class="dm-btn-table" style="margin:0 0 8px;">
                            <tr>
                                <td class="dm-btn-td" align="center" bgcolor="#1d1d1f" style="border-radius:10px; background-color:#1d1d1f;">
                                    <a href="{{ $w['webmailUrl'] }}" target="_blank" class="dm-btn-a" style="display:inline-block; padding:13px 24px; font-family:{{ $font }}; font-size:15px; line-height:20px; font-weight:600; color:#ffffff; text-decoration:none; border:1px solid #1d1d1f; border-radius:10px; background-color:#1d1d1f;">Web postasını aç</a>
                                </td>
                            </tr>
                        </table>
                        <p class="dm-faint" style="margin:0 0 36px; font-family:{{ $font }}; font-size:13px; line-height:19px; color:#86868b; word-break:break-all;">Adres: <a href="{{ $w['webmailUrl'] }}" target="_blank" class="dm-link" style="color:#0066cc; text-decoration:none;">{{ $w['webmailUrl'] }}</a></p>

                        @foreach($sections as $section)
                            @include('mail.comail-welcome.heading', ['icon' => $w['icons'][$section['icon']], 'title' => $section['title'], 'font' => $font])
                            <p class="dm-muted" style="margin:0 0 14px; font-family:{{ $font }}; font-size:15px; line-height:23px; color:#6e6e73;">{{ $section['intro'] }}</p>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; margin:0 0 {{ isset($section['after']) ? '16px' : '36px' }};">
                                @foreach($section['steps'] as $step)
                                    <tr>
                                        <td width="34" valign="top" style="width:34px; padding:{{ $loop->first ? '0' : '12px' }} 0 0; vertical-align:top;">
                                            <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
                                                <td class="dm-num" align="center" valign="middle" width="24" height="24" bgcolor="#1d1d1f" style="width:24px; height:24px; border-radius:12px; background-color:#1d1d1f; color:#ffffff; font-family:{{ $font }}; font-size:13px; line-height:24px; font-weight:600; text-align:center;">{{ $loop->iteration }}</td>
                                            </tr></table>
                                        </td>
                                        <td valign="top" class="dm-text" style="padding:{{ $loop->first ? '1px' : '13px' }} 0 0; vertical-align:top; font-family:{{ $font }}; font-size:15px; line-height:23px; color:#1d1d1f;">{!! $md($step) !!}</td>
                                    </tr>
                                @endforeach
                            </table>
                            @isset($section['after'])
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="dm-surface" style="width:100%; margin:0 0 36px; background-color:#f5f5f7; border-radius:12px; border-collapse:separate;">
                                    <tr>
                                        <td class="dm-text" style="padding:14px 16px; font-family:{{ $font }}; font-size:14px; line-height:21px; color:#1d1d1f;">{!! $md($section['after']) !!}</td>
                                    </tr>
                                </table>
                            @endisset
                        @endforeach

                        {{-- Sunucu bilgileri --}}
                        @include('mail.comail-welcome.heading', ['icon' => $w['icons']['sunucu'], 'title' => 'Sunucu bilgileri', 'font' => $font])
                        <p class="dm-muted" style="margin:0 0 14px; font-family:{{ $font }}; font-size:15px; line-height:23px; color:#6e6e73;">Bir uygulama ayarları elle sorarsa bunları yazın.</p>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="dm-surface" style="width:100%; margin:0 0 36px; background-color:#f5f5f7; border-radius:14px; border-collapse:separate;">
                            @foreach($settings as $row)
                                <tr>
                                    <td class="{{ $loop->last ? '' : 'dm-divider' }}" style="padding:12px 16px; {{ $loop->last ? '' : 'border-bottom:1px solid #e5e5ea;' }} font-family:{{ $font }};">
                                        <p class="dm-muted" style="margin:0 0 2px; font-size:12px; line-height:16px; font-weight:500; color:#6e6e73;">{{ $row['label'] }}</p>
                                        <p class="dm-text" style="margin:0; font-family:{{ $mono }}; font-size:15px; line-height:22px; color:#1d1d1f; word-break:break-all;">{{ $row['value'] }}</p>
                                        <p class="dm-muted" style="margin:2px 0 0; font-size:13px; line-height:18px; color:#6e6e73;">{{ $row['hint'] }}</p>
                                    </td>
                                </tr>
                            @endforeach
                        </table>

                        {{-- Sorun giderme --}}
                        @include('mail.comail-welcome.heading', ['icon' => $w['icons']['sorun'], 'title' => 'Sorun yaşarsanız', 'font' => $font])
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="dm-surface" style="width:100%; margin:4px 0 28px; background-color:#f5f5f7; border-radius:14px; border-collapse:separate;">
                            @foreach($troubles as $row)
                                <tr>
                                    <td class="{{ $loop->last ? '' : 'dm-divider' }}" style="padding:14px 16px; {{ $loop->last ? '' : 'border-bottom:1px solid #e5e5ea;' }} font-family:{{ $font }}; vertical-align:top;">
                                        <p class="dm-text" style="margin:0; font-size:15px; line-height:21px; font-weight:600; color:#1d1d1f;">{{ $row['title'] }}</p>
                                        <p class="dm-muted" style="margin:3px 0 0; font-size:14px; line-height:21px; color:#6e6e73;">{!! $md($row['text']) !!}</p>
                                    </td>
                                </tr>
                            @endforeach
                        </table>

                        <p class="dm-text" style="margin:0 0 10px; font-family:{{ $font }}; font-size:15px; line-height:23px; color:#1d1d1f;">Takıldığınız bir yer olursa bu e-postayı yanıtlamanız ya da <a href="mailto:{{ $w['supportEmail'] }}" class="dm-link" style="color:#0066cc; text-decoration:none;">{{ $w['supportEmail'] }}</a> adresine yazmanız yeterli; destek ekibimiz yardımcı olur.</p>
                        <p class="dm-text" style="margin:24px 0 0; font-family:{{ $font }}; font-size:15px; line-height:22px; color:#1d1d1f;">CodRon ekibi</p>
                    </td>
                </tr>
                <tr>
                    <td align="center" class="dm-faint" style="padding:24px 16px 8px; font-family:{{ $font }}; font-size:12px; line-height:18px; color:#86868b;">
                        <p class="dm-muted" style="margin:0 0 4px; font-size:12px; line-height:18px; font-weight:600; color:#6e6e73;">CodRon</p>
                        <p class="dm-faint" style="margin:0 0 4px; font-size:12px; line-height:18px; color:#86868b;">codron.co · {{ $w['supportEmail'] }}</p>
                        <p class="dm-faint" style="margin:12px 0 0; font-size:12px; line-height:18px; color:#86868b;">Bu e-posta, {{ $w['address'] }} adresi açıldığı için bir kez gönderildi.</p>
                    </td>
                </tr>
            </table>
            <!--[if mso]></td></tr></table><![endif]-->
        </td>
    </tr>
</table>
</body>
</html>
