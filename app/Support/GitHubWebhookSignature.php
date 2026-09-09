<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * GitHub → Plane theme webhook HMAC. Distinct from Coolify deploy webhooks
 * (`X-Coolify-Signature` / coolify_settings.webhook_secret).
 *
 * Header: X-Hub-Signature-256 = sha256=<hex>
 * Secret: github_settings.webhook_secret (encrypted) or GITHUB_WEBHOOK_SECRET.
 * Empty secret is never used as an HMAC key.
 */
final class GitHubWebhookSignature
{
    public const HEADER = 'X-Hub-Signature-256';

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
        $value = $request->header(self::HEADER);

        return is_string($value) && trim($value) !== '' ? $value : null;
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
