<?php

namespace App\Services\GitHub;

final class GitHubAppJwt
{
    public static function encode(string $appId, string $privateKeyPem, ?int $now = null): string
    {
        $now ??= time();
        $header = self::base64UrlEncode((string) json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ], JSON_THROW_ON_ERROR));
        $payload = self::base64UrlEncode((string) json_encode([
            'iat' => $now - 60,
            'exp' => $now + 540,
            'iss' => $appId,
        ], JSON_THROW_ON_ERROR));

        $signingInput = $header.'.'.$payload;
        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $privateKeyPem, OPENSSL_ALGO_SHA256);
        if (! $ok || $signature === '') {
            throw new GitHubCredentialsException('GitHub App private key could not be used to sign a JWT.');
        }

        return $signingInput.'.'.self::base64UrlEncode($signature);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
