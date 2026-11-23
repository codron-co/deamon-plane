{{-- Bölüm başlığı: solda 36 px iOS Ayarlar tarzı ikon, yanında başlık. $icon, $title, $font --}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 12px;">
    <tr>
        <td width="36" valign="middle" style="width:36px; vertical-align:middle;">
            <img src="{{ $icon }}" alt="" width="36" height="36" style="display:block; width:36px; height:36px; border:0;">
        </td>
        <td valign="middle" class="dm-text" style="padding:0 0 0 12px; vertical-align:middle; font-family:{{ $font }}; font-size:21px; line-height:26px; font-weight:600; letter-spacing:-0.4px; color:#1d1d1f;">{{ $title }}</td>
    </tr>
</table>
