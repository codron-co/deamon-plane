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

    public function isSubdomainRejection(): bool
    {
        $message = strtolower($this->cloudflareMessage());

        return str_contains($message, 'root domain')
            || str_contains($message, 'not any subdomains');
    }

    public function isDuplicateRecord(): bool
    {
        foreach ([81053, 81057, 81058] as $code) {
            if ($this->hasErrorCode($code)) {
                return true;
            }
        }

        $message = strtolower($this->cloudflareMessage());

        return str_contains($message, 'already exists')
            || str_contains($message, 'record with that host');
    }

    public function hasErrorCode(int $code): bool
    {
        $errors = $this->payload['errors'] ?? [];
        if (! is_array($errors)) {
            return false;
        }

        foreach ($errors as $error) {
            if (is_array($error) && (int) ($error['code'] ?? 0) === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cloudflare's own (English) wording, for pattern checks. getMessage() may be the
     * translated operator text.
     */
    public function cloudflareMessage(): string
    {
        $raw = self::messageFromBody($this->payload);

        return $raw ?? $this->getMessage();
    }

    public static function fromResponse(Response $response, ?string $token = null): self
    {
        $json = $response->json();
        $payload = is_array($json) ? self::redactPayload($json, $token) : null;
        $message = self::messageFromBody($json) ?? 'Cloudflare API request failed.';

        return new self(
            self::operatorMessage(self::redact($message, $token), $payload),
            $response->status(),
            $payload,
        );
    }

    /**
     * Known Cloudflare errors in the operator's language. The English original stays
     * in the payload (cloudflareMessage()) for logs and pattern checks; unknown errors
     * keep Cloudflare's text so nothing is hidden.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public static function operatorMessage(string $message, ?array $payload): string
    {
        $lower = strtolower($message);
        $codes = [];
        foreach (is_array($payload['errors'] ?? null) ? $payload['errors'] : [] as $error) {
            if (is_array($error) && isset($error['code'])) {
                $codes[] = (int) $error['code'];
            }
        }

        $key = match (true) {
            str_contains($lower, 'not a tld'), str_contains($lower, 'root domain and not') => 'cloudflare.errors.public_suffix',
            str_contains($lower, 'not a registered domain'), in_array(1049, $codes, true) => 'cloudflare.errors.not_registered',
            str_contains($lower, 'already exists') && str_contains($lower, 'zone'), in_array(1061, $codes, true) => 'cloudflare.errors.zone_exists_elsewhere',
            str_contains($lower, 'authentication error'), in_array(10000, $codes, true) => 'cloudflare.errors.token_rejected',
            default => null,
        };

        return $key === null ? $message : (string) __($key, ['domain' => __('cloudflare.errors.this_domain')]);
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
