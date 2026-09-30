<?php

return [
    'bad_signature' => 'Site, Plane’in imzasını kabul etmedi: agent gizli anahtarı eşleşmiyor. Deploy öncesi kontrol anahtarı Coolify’a yazar; sitede “Tekrar deploy” yapın.',
    'needs_secret' => 'Sitenin agent gizli anahtarı yok. Altyapı sekmesinden anahtar üretip gönderin.',
    'theme' => [
        'not_registered' => 'Sitenin CMS’i tema işlemlerini tanımıyor (agent anahtarı eksik ya da CMS 1.2.5’ten eski).',
        'not_found' => 'Tema sitede kurulu değil. “Siteye yeniden gönder” ile kurun.',
        'unsupported_source' => 'CMS yalnızca git kaynaklı temaları kabul ediyor.',
        'path_traversal' => 'Tema kimliği CMS’in güvenlik kontrolünden geçmedi.',
        'system_theme' => 'Varsayılan sistem teması kurulamaz veya güncellenemez.',
        'system_theme_agent' => 'Varsayılan sistem teması Plane’den kurulamaz veya güncellenemez.',
        'data_package_missing' => 'Sitede temanın içerik paketi (sync.json) yok. Temayı güncelleyin ya da içerik paketini kurun.',
        'validation_failed' => 'CMS tema isteğini doğrulamadı.',
        'git_failed' => 'CMS temayı git’ten indiremedi.',
        'http' => 'CMS tema isteğine HTTP :status ile yanıt verdi.',
        'active' => 'Sitenin aktif teması kaldırılamaz. Önce başka bir temayı aktifleştirin.',
        'protected' => 'Sistem teması kaldırılamaz.',
    ],
    'admin' => [
        'too_old' => 'Sitenin CMS’i yönetici işlemleri için eski (Deamon 1.2.13+ gerekir).',
        'validation_failed' => 'CMS yönetici isteğini doğrulamadı.',
        'http' => 'CMS yönetici isteğine HTTP :status ile yanıt verdi.',
    ],
];
