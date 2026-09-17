<?php

namespace App\Services\Agent;

/**
 * Outcome of POST /internal/control/v1/site/identity. `name` is what the CMS
 * echoed back after applying the rename — never the value Plane asked for.
 */
final class SiteIdentityAgentResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $safeMessage,
        public readonly ?string $name = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $errorCode = null,
        public readonly bool $changed = false,
    ) {}

    public static function success(string $name, bool $changed, ?int $httpStatus = 200): self
    {
        return new self(true, 'Site name applied.', $name, $httpStatus, null, $changed);
    }

    public static function failure(string $safeMessage, ?int $httpStatus = null, ?string $errorCode = null): self
    {
        return new self(false, $safeMessage, null, $httpStatus, $errorCode);
    }

    public static function needsSecret(): self
    {
        return self::failure((string) __('sites.identity.needs_secret'), null, 'needs_secret');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromCmsPayload(array $payload, int $httpStatus): self
    {
        $name = is_string($payload['site_name'] ?? null) ? trim($payload['site_name']) : '';

        if ($name === '') {
            return self::failure((string) __('sites.identity.errors.unreadable'), $httpStatus, 'unreadable');
        }

        return self::success($name, (bool) ($payload['changed'] ?? true), $httpStatus);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public static function fromCmsError(?array $payload, int $httpStatus): self
    {
        $code = is_array($payload) && is_string($payload['error'] ?? null) ? $payload['error'] : null;
        $cmsMessage = is_array($payload) && is_string($payload['message'] ?? null) ? trim($payload['message']) : '';

        $message = match (true) {
            $httpStatus === 429 => (string) __('sites.agent.rate_limited'),
            $httpStatus === 401 || $httpStatus === 403 => (string) __('sites.identity.errors.signature'),
            // The route landed in CMS 1.2.27; older instances simply have no route.
            $httpStatus === 404 => (string) __('sites.identity.errors.unsupported_cms'),
            $cmsMessage !== '' => $cmsMessage,
            default => (string) __('sites.identity.errors.http', ['status' => $httpStatus]),
        };

        return self::failure($message, $httpStatus, $code);
    }
}
