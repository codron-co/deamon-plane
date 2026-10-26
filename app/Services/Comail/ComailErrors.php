<?php

namespace App\Services\Comail;

/**
 * mailcow translation keys → Turkish messages for the CMS. Unknown keys fall back
 * to a generic message; the key itself is never shown to customers.
 */
final class ComailErrors
{
    private const MESSAGES = [
        'mailbox_quota_exceeded' => 'Posta kutusu kotası alan adının toplam kotasını aşıyor.',
        'mailbox_quota_left_exceeded' => 'Alan adında bu kota için yeterli yer kalmadı.',
        'max_mailbox_exceeded' => 'Alan adı için posta kutusu sınırına ulaşıldı.',
        'max_alias_exceeded' => 'Alan adı için yönlendirme sınırına ulaşıldı.',
        'mailbox_quota_exceeds_domain_quota' => 'Posta kutusu kotası alan adının kotasını aşıyor.',
        'object_exists' => 'Bu kayıt zaten var.',
        'is_alias' => 'Bu adres zaten bir yönlendirme olarak kullanılıyor.',
        'is_alias_or_mailbox' => 'Bu adres zaten bir posta kutusu ya da yönlendirme olarak kullanılıyor.',
        'is_mailbox' => 'Bu adres zaten bir posta kutusu.',
        'alias_invalid' => 'Yönlendirme adresi geçersiz.',
        'goto_invalid' => 'Hedef adres geçersiz.',
        'alias_goto_identical' => 'Yönlendirme adresi ile hedef aynı olamaz.',
        'mailbox_invalid' => 'Posta kutusu adı geçersiz.',
        'domain_invalid' => 'Alan adı geçersiz.',
        'domain_exists' => 'Bu alan adı zaten CoMail’de kayıtlı.',
        'domain_not_found' => 'Alan adı CoMail’de bulunamadı.',
        'domain_not_empty' => 'Alan adında hâlâ posta kutusu var.',
        'access_denied' => 'CoMail bu işleme izin vermedi.',
        'password_complexity' => 'Parola yeterince güçlü değil.',
        'password_mismatch' => 'Parolalar eşleşmiyor.',
        'password_empty' => 'Parola boş olamaz.',
        'quota_not_0_not_numeric' => 'Kota pozitif bir sayı olmalı.',
        'description_invalid' => 'Açıklama geçersiz.',
        'dkim_domain_or_sel_invalid' => 'DKIM anahtarı oluşturulamadı.',
        'dkim_domain_or_sel_exists' => 'DKIM anahtarı zaten var.',
        'syncjob_exists' => 'Bu taşıma işi zaten var.',
        'target_mailbox_invalid' => 'Hedef posta kutusu geçersiz.',
        'sieve_error' => 'Otomatik yanıt kuralı kaydedilemedi.',
        'value_missing' => 'Gerekli bir alan eksik.',
        'policy_list_from_exists' => 'Bu adres listede zaten var.',
        'policy_list_from_invalid' => 'Adres biçimi geçersiz.',
        'mode_invalid' => 'MTA-STS kipi geçersiz.',
        'max_age_invalid' => 'MTA-STS süresi geçersiz.',
        'mx_invalid' => 'MTA-STS için MX adı geçersiz.',
    ];

    public static function turkish(?string $key): string
    {
        if ($key !== null && isset(self::MESSAGES[$key])) {
            return self::MESSAGES[$key];
        }

        return 'CoMail isteği tamamlayamadı.';
    }
}
