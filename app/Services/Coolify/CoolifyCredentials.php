<?php

namespace App\Services\Coolify;

use App\Models\CoolifySetting;

final class CoolifyCredentials
{
    public readonly string $baseUrl;

    public function __construct(
        string $baseUrl,
        private readonly string $token,
    ) {
        $this->baseUrl = self::normalizeBaseUrl($baseUrl);
    }

    public function token(): string
    {
        return $this->token;
    }

    public function hasToken(): bool
    {
        return $this->token !== '';
    }

    public function apiRoot(): string
    {
        return $this->baseUrl.'/api/v1';
    }

    public static function resolve(?CoolifySetting $settings = null): self
    {
        $settings ??= CoolifySetting::current();

        $base = filled($settings->base_url)
            ? (string) $settings->base_url
            : (string) config('ops.coolify.base_url', '');

        $token = filled($settings->api_token)
            ? (string) $settings->api_token
            : (string) config('ops.coolify.api_token', '');

        return new self($base, $token);
    }

    public static function normalizeBaseUrl(string $baseUrl): string
    {
        $normalized = rtrim(trim($baseUrl), '/');
        if (str_ends_with(strtolower($normalized), '/api/v1')) {
            $normalized = substr($normalized, 0, -7);
            $normalized = rtrim($normalized, '/');
        }

        return $normalized;
    }

    /**
     * @return array{baseUrl: string, token: string}
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'token' => $this->hasToken() ? '[redacted]' : '',
        ];
    }
}
