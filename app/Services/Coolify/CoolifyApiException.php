<?php

namespace App\Services\Coolify;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

class CoolifyApiException extends RuntimeException
{
    /**
     * @param  list<array<string, mixed>>  $conflicts
     * @param  array<string, mixed>|null  $payload
     */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly array $conflicts = [],
        public readonly ?array $payload = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function isConflict(): bool
    {
        return $this->status === 409 || $this->conflicts !== [];
    }

    public static function fromResponse(Response $response, ?string $token = null): self
    {
        $json = $response->json();
        $payload = is_array($json) ? self::redactPayload($json, $token) : null;
        $message = is_array($json)
            ? (string) ($json['message'] ?? $json['error'] ?? 'Coolify API request failed.')
            : 'Coolify API request failed.';

        $conflicts = [];
        if (is_array($json) && isset($json['conflicts']) && is_array($json['conflicts'])) {
            $conflicts = $json['conflicts'];
        }

        return new self(
            self::redact($message, $token),
            $response->status(),
            $conflicts,
            $payload,
        );
    }

    public static function redact(string $text, ?string $token = null): string
    {
        if (is_string($token) && $token !== '' && str_contains($text, $token)) {
            $text = str_replace($token, '[redacted]', $text);
        }

        $redacted = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $text);

        return is_string($redacted) ? $redacted : $text;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function redactPayload(array $payload, ?string $token = null): array
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $encoded = is_string($encoded) ? self::redact($encoded, $token) : '{}';
        $decoded = json_decode($encoded, true);

        return is_array($decoded) ? $decoded : [];
    }
}
