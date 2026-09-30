<?php

namespace App\Services\SearchIntegrations;

/**
 * Operator wording for push / pull failure reasons stored on
 * `site_search_integrations.push_error` or returned by a pull.
 */
final class SiteSearchIntegrationsPresenter
{
    public static function errorLabel(?string $reason): string
    {
        $reason = trim((string) $reason);

        if ($reason === '') {
            return (string) __('search_integrations.errors.http_error');
        }

        foreach (['validation_failed', 'unsupported_field', 'configure_failed'] as $code) {
            if (str_starts_with($reason, $code)) {
                $message = trim(substr($reason, strlen($code)), ": \t");

                return (string) __('search_integrations.errors.rejected', ['message' => $message !== '' ? $message : $code]);
            }
        }

        if (in_array($reason, ['http_404', 'http_405'], true)) {
            return (string) __('search_integrations.errors.cms_too_old');
        }

        if ($reason === 'http_401') {
            return (string) __('search_integrations.errors.signature');
        }

        if (preg_match('/\Ahttp_(\d{3})\z/', $reason, $match) === 1) {
            return (string) __('search_integrations.errors.http_status', ['status' => $match[1]]);
        }

        $key = 'search_integrations.errors.'.$reason;
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : $reason;
    }
}
