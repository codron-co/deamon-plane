<?php

namespace App\Services\Cloudflare;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

class CloudflareApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?array $payload = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function isForbidden(): bool
    {
        return $this->status === 403;
    }

    public static function fromResponse(Response $response, ?string $token = null): self
    {
        $json = $response->json();
        $payload = is_array($json) ? self::redactPayload($json, $token) : null;
        $message = self::messageFromBody($json) ?? 'Cloudflare API request failed.';

        return new self(
            self::redact($message, $token),
            $response->status(),
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

    private static function messageFromBody(mixed $json): ?string
    {
        if (! is_array($json)) {
            return null;
        }

        $parts = [];
        $errors = $json['errors'] ?? [];
        if (is_array($errors)) {
            foreach ($errors as $error) {
                if (is_array($error) && isset($error['message']) && is_string($error['message']) && $error['message'] !== '') {
                    $parts[] = $error['message'];
                }
            }
        }

        if ($parts !== []) {
            return implode('; ', $parts);
        }

        foreach (['message', 'error'] as $key) {
            if (isset($json[$key]) && is_string($json[$key]) && $json[$key] !== '') {
                return $json[$key];
            }
        }

        return null;
    }
}
