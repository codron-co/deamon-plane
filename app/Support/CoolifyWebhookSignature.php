<?php

namespace App\Support;

use Illuminate\Http\Request;

final class CoolifyWebhookSignature
{
    /**
     * Coolify→Plane webhook auth.
     *
     * HMAC (preferred when a signer exists): header first match
     * X-Coolify-Signature | X-Hub-Signature-256 | X-Signature.
     * Value: `sha256=<hex>` or raw hex.
     *
     * Coolify native notifications are unsigned. Fallback: query `token` or
     * `secret` must match the stored webhook signing secret via hash_equals.
     *
     * Secret: coolify_settings.webhook_secret (encrypted) or
     * COOLIFY_WEBHOOK_SECRET. Empty secret is never used as an HMAC key or
     * query-token comparator.
     */
    public const HEADER_PREFERRED = 'X-Coolify-Signature';

    /**
     * @var list<string>
     */
    public const HEADER_CANDIDATES = [
        'X-Coolify-Signature',
        'X-Hub-Signature-256',
        'X-Signature',
    ];

    public static function sign(string $secret, string $payload): string
    {
        return 'sha256='.hash_hmac('sha256', $payload, $secret);
    }

    public static function matches(string $secret, string $payload, ?string $header): bool
    {
        if ($secret === '' || $header === null || trim($header) === '') {
            return false;
        }

        $provided = self::extractHex($header);
        if ($provided === null) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $provided);
    }

    public static function headerFromRequest(Request $request): ?string
    {
        foreach (self::HEADER_CANDIDATES as $name) {
            $value = $request->header($name);
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    public static function queryTokenFromRequest(Request $request): ?string
    {
        foreach (['token', 'secret'] as $key) {
            $value = $request->query($key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    public static function queryTokenMatches(string $secret, ?string $token): bool
    {
        if ($secret === '' || $token === null || $token === '') {
            return false;
        }

        return hash_equals($secret, $token);
    }

    private static function extractHex(string $header): ?string
    {
        $header = trim($header);
        if (str_starts_with(strtolower($header), 'sha256=')) {
            $header = substr($header, 7);
        }

        $header = trim($header);
        if ($header === '' || ! ctype_xdigit($header)) {
            return null;
        }

        return strtolower($header);
    }
}
