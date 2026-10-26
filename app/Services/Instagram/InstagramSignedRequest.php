<?php

namespace App\Services\Instagram;

/**
 * Meta's `signed_request` (deauthorize and data deletion callbacks): base64url signature,
 * a dot, base64url JSON payload; HMAC-SHA256 of the payload with the Instagram app secret.
 * https://developers.facebook.com/docs/development/create-an-app/app-dashboard/data-deletion-callback
 */
final class InstagramSignedRequest
{
    /**
     * @return array<string, mixed>|null the payload, or null when the signature or shape is wrong
     */
    public static function parse(string $signedRequest, string $secret): ?array
    {
        if ($secret === '' || substr_count($signedRequest, '.') !== 1) {
            return null;
        }

        [$encodedSignature, $encodedPayload] = explode('.', $signedRequest, 2);
        $signature = self::decode($encodedSignature);
        $payload = json_decode((string) self::decode($encodedPayload), true);

        if ($signature === null || ! is_array($payload)) {
            return null;
        }

        if (strtoupper((string) ($payload['algorithm'] ?? '')) !== 'HMAC-SHA256') {
            return null;
        }

        if (! hash_equals(hash_hmac('sha256', $encodedPayload, $secret, true), $signature)) {
            return null;
        }

        return $payload;
    }

    private static function decode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
