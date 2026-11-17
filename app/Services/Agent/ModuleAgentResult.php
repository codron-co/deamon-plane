<?php

namespace App\Services\Agent;

/**
 * Outcome of a module catalog call (GET/POST /internal/control/v1/modules).
 * Module rows are allowlisted; anything else the CMS sends is dropped.
 */
final class ModuleAgentResult
{
    /**
     * @param  list<array{slug: string, name: string, tagline: string, category: string, installed: bool, enabled: bool, locked: bool, requires: list<string>}>  $modules
     * @param  list<string>  $changed
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $safeMessage,
        public readonly ?int $httpStatus = null,
        public readonly array $modules = [],
        public readonly ?string $slug = null,
        public readonly ?bool $enabled = null,
        public readonly array $changed = [],
        public readonly ?string $errorCode = null,
        public readonly bool $needsSecret = false,
        public readonly bool $unsupported = false,
    ) {}

    public static function failure(string $safeMessage, ?int $httpStatus = null, ?string $errorCode = null, bool $unsupported = false): self
    {
        return new self(false, $safeMessage, $httpStatus, errorCode: $errorCode, unsupported: $unsupported);
    }

    public static function needsSecret(): self
    {
        return new self(false, (string) __('site_modules.errors.needs_secret'), needsSecret: true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromCmsPayload(array $payload, int $httpStatus): self
    {
        if (($payload['ok'] ?? null) !== true) {
            return self::failure((string) __('site_modules.errors.invalid_response'), $httpStatus, 'invalid_response');
        }

        $modules = [];
        foreach ((array) ($payload['modules'] ?? []) as $row) {
            if (is_array($row) && self::stringOrNull($row['slug'] ?? null) !== null) {
                $modules[] = self::allowlistedModule($row);
            }
        }

        $changed = [];
        foreach ((array) ($payload['changed'] ?? []) as $slug) {
            if (is_string($slug) && trim($slug) !== '') {
                $changed[] = trim($slug);
            }
        }

        return new self(
            true,
            self::stringOrNull($payload['message'] ?? null) ?? 'Module agent OK.',
            $httpStatus,
            $modules,
            self::stringOrNull($payload['slug'] ?? null),
            is_bool($payload['enabled'] ?? null) ? $payload['enabled'] : null,
            $changed,
        );
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public static function fromCmsError(?array $payload, int $httpStatus): self
    {
        $code = is_array($payload) ? self::stringOrNull($payload['error'] ?? null) : null;
        $cmsMessage = is_array($payload) ? self::stringOrNull($payload['message'] ?? null) : null;
        $unsupported = $httpStatus === 404 && $code === null;

        $message = match (true) {
            $unsupported => (string) __('site_modules.errors.unsupported'),
            $httpStatus === 429 => (string) __('sites.agent.rate_limited'),
            $httpStatus === 401 || $httpStatus === 403 => (string) __('agent.bad_signature'),
            $httpStatus === 422 && $cmsMessage !== null => $cmsMessage,
            $code === 'validation_failed' => (string) __('site_modules.errors.validation_failed'),
            default => (string) __('site_modules.errors.http', ['status' => $httpStatus]),
        };

        return self::failure($message, $httpStatus, $code, $unsupported);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{slug: string, name: string, tagline: string, category: string, installed: bool, enabled: bool, locked: bool, requires: list<string>}
     */
    private static function allowlistedModule(array $row): array
    {
        $slug = (string) self::stringOrNull($row['slug'] ?? null);

        return [
            'slug' => $slug,
            'name' => self::stringOrNull($row['name'] ?? null) ?? $slug,
            'tagline' => self::stringOrNull($row['tagline'] ?? null) ?? '',
            'category' => self::stringOrNull($row['category'] ?? null) ?? '',
            'installed' => (bool) ($row['installed'] ?? false),
            'enabled' => (bool) ($row['enabled'] ?? false),
            'locked' => (bool) ($row['locked'] ?? false),
            'requires' => array_values(array_filter(
                array_map(static fn ($value): ?string => self::stringOrNull($value), (array) ($row['requires'] ?? [])),
                static fn (?string $value): bool => $value !== null,
            )),
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
