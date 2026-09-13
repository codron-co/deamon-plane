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

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    public function isRateLimited(): bool
    {
        return $this->status === 429;
    }

    /**
     * Throttles, gateway errors, and connection failures say nothing about the
     * resource. Callers must retry these instead of recording them as an outcome.
     */
    public function isTransient(): bool
    {
        return $this->status === 429
            || $this->status === 0
            || in_array($this->status, [500, 502, 503, 504], true);
    }

    public static function fromResponse(Response $response, ?string $token = null): self
    {
        $json = $response->json();
        $payload = is_array($json) ? self::redactPayload($json, $token) : null;
        $message = is_array($json)
            ? (string) ($json['message'] ?? $json['error'] ?? 'Coolify API request failed.')
            : 'Coolify API request failed.';

        $errors = is_array($json) ? ($json['errors'] ?? null) : null;

        // Known Coolify English bodies → locale strings (rate limit, compose/domain order).
        // Keep the raw body in $payload for logs, but never show remote English to an operator.
        if ($response->status() === 429) {
            $message = (string) __('coolify.errors.rate_limited');
        } elseif (self::isComposeDomainsBeforeRaw($message, $errors)) {
            $message = (string) __('coolify.errors.compose_domains_before_raw');
        } elseif (is_array($json)) {
            $message = self::appendValidationErrors($message, $errors);
        }

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

    /**
     * Coolify refuses docker_compose_domains until a deploy has loaded docker_compose_raw from git.
     */
    public static function isComposeDomainsBeforeRaw(string $message, mixed $errors = null): bool
    {
        $haystack = strtolower($message);
        if (
            str_contains($haystack, 'docker_compose_raw')
            || str_contains($haystack, 'cannot set docker_compose_domains')
        ) {
            return true;
        }

        if (! is_array($errors) || $errors === []) {
            return false;
        }

        $encoded = json_encode($errors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) && str_contains(strtolower($encoded), 'docker_compose_raw');
    }

    /**
     * Laravel-style Coolify 422 bodies are often message=Validation failed. plus errors{field: [...]}.
     */
    private static function appendValidationErrors(string $message, mixed $errors): string
    {
        if (! is_array($errors) || $errors === []) {
            return $message;
        }

        $parts = [];
        foreach ($errors as $field => $fieldErrors) {
            if (! is_string($field) || $field === '') {
                continue;
            }

            $texts = is_array($fieldErrors)
                ? array_values(array_filter($fieldErrors, is_string(...)))
                : (is_string($fieldErrors) ? [$fieldErrors] : []);

            if ($texts === []) {
                continue;
            }

            $parts[] = $field.': '.implode(' ', $texts);
        }

        if ($parts === []) {
            return $message;
        }

        $suffix = implode('; ', $parts);
        if ($suffix === '' || str_contains($message, $suffix)) {
            return $message;
        }

        return trim($message.' '.$suffix);
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
