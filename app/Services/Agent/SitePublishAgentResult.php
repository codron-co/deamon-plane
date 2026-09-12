<?php

namespace App\Services\Agent;

use App\Enums\CmsPublishStatus;

/**
 * Outcome of POST /internal/control/v1/site/status. `status` is the state the
 * CMS echoed back — never the one we asked for — so the Plane mirror can only
 * ever record what the CMS actually applied.
 */
final class SitePublishAgentResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $safeMessage,
        public readonly ?CmsPublishStatus $status = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $errorCode = null,
        public readonly bool $changed = false,
    ) {}

    public static function success(?CmsPublishStatus $status, bool $changed, ?int $httpStatus = 200): self
    {
        return new self(true, 'Publish state applied.', $status, $httpStatus, null, $changed);
    }

    public static function failure(string $safeMessage, ?int $httpStatus = null, ?string $errorCode = null): self
    {
        return new self(false, $safeMessage, null, $httpStatus, $errorCode);
    }

    public static function needsSecret(): self
    {
        return self::failure((string) __('sites.publish.needs_secret'), null, 'needs_secret');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromCmsPayload(array $payload, int $httpStatus): self
    {
        $status = CmsPublishStatus::tryFrom((string) ($payload['site_status'] ?? ''));

        if ($status === null) {
            return self::failure((string) __('sites.publish.errors.unreadable'), $httpStatus, 'unreadable');
        }

        return self::success($status, (bool) ($payload['changed'] ?? true), $httpStatus);
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
            $httpStatus === 401 || $httpStatus === 403 => (string) __('sites.publish.errors.signature'),
            // The write path landed in CMS 1.2.16; older instances simply have no route.
            $httpStatus === 404 => (string) __('sites.publish.errors.unsupported_cms'),
            $cmsMessage !== '' => $cmsMessage,
            default => (string) __('sites.publish.errors.http', ['status' => $httpStatus]),
        };

        return self::failure($message, $httpStatus, $code);
    }
}
