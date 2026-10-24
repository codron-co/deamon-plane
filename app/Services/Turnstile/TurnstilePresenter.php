<?php

namespace App\Services\Turnstile;

/**
 * Turnstile eşitleme/gönderim hata kodlarını operatör metnine çevirir.
 */
final class TurnstilePresenter
{
    public static function errorLabel(?string $code): string
    {
        $code = trim((string) $code);
        if ($code === '') {
            return '';
        }

        if (str_starts_with($code, 'cloudflare: ')) {
            return __('turnstile.errors.cloudflare', ['reason' => substr($code, strlen('cloudflare: '))]);
        }

        if (str_starts_with($code, 'validation_failed') || str_starts_with($code, 'configure_failed')) {
            $reason = str_contains($code, ': ') ? substr($code, strpos($code, ': ') + 2) : $code;

            return __('turnstile.errors.rejected', ['reason' => $reason]);
        }

        if (preg_match('/\Ahttp_(\d{3})\z/', $code, $match) === 1) {
            return match ($match[1]) {
                '401' => __('turnstile.errors.signature'),
                '404', '405' => __('turnstile.errors.cms_too_old'),
                default => __('turnstile.errors.http_status', ['status' => $match[1]]),
            };
        }

        $key = 'turnstile.errors.'.$code;

        return __($key) !== $key ? __($key) : $code;
    }
}
