<?php

namespace App\Support;

use App\Services\Agent\ControlPlaneAgentContract;
use Illuminate\Support\Str;

final class ControlPlaneAgentSignature
{
    public static function canonicalString(string $timestamp, string $nonce, string $body): string
    {
        return $timestamp.'.'.$nonce.'.'.$body;
    }

    public static function sign(string $secret, string $timestamp, string $nonce, string $body): string
    {
        return strtolower(hash_hmac('sha256', self::canonicalString($timestamp, $nonce, $body), $secret));
    }

    public static function matches(string $secret, string $timestamp, string $nonce, string $body, ?string $signature): bool
    {
        if ($secret === '' || $signature === null || trim($signature) === '') {
            return false;
        }

        $expected = self::sign($secret, $timestamp, $nonce, $body);

        return hash_equals($expected, strtolower(trim($signature)));
    }

    /**
     * @return array{timestamp: string, nonce: string, signature: string, headers: array<string, string>}
     */
    public static function headers(string $secret, string $body = '', ?int $timestamp = null, ?string $nonce = null): array
    {
        $timestamp = (string) ($timestamp ?? now()->timestamp);
        $nonce = $nonce ?? (string) Str::uuid();
        $signature = self::sign($secret, $timestamp, $nonce, $body);

        return [
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'signature' => $signature,
            'headers' => [
                ControlPlaneAgentContract::HEADER_TIMESTAMP => $timestamp,
                ControlPlaneAgentContract::HEADER_NONCE => $nonce,
                ControlPlaneAgentContract::HEADER_SIGNATURE => $signature,
            ],
        ];
    }
}
