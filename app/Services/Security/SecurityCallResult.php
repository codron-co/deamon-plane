<?php

namespace App\Services\Security;

/**
 * Outcome of one signed call to a CMS security endpoint. Never throws: a site that
 * is down, too old or throttled is a state to report, not an exception.
 */
final class SecurityCallResult
{
    public const OK = 'ok';

    /** The CMS has no security endpoint (older release or module not installed): 404/405. */
    public const UNSUPPORTED = 'unsupported';

    public const NEEDS_SECRET = 'needs_secret';

    public const RATE_LIMITED = 'rate_limited';

    public const FAILED = 'failed';

    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        public readonly string $state,
        public readonly array $payload = [],
        public readonly ?int $httpStatus = null,
        public readonly string $message = '',
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function ok(array $payload, int $httpStatus): self
    {
        return new self(self::OK, $payload, $httpStatus);
    }

    public static function failure(string $state, string $message, ?int $httpStatus = null): self
    {
        return new self($state, [], $httpStatus, $message);
    }

    public function isOk(): bool
    {
        return $this->state === self::OK;
    }

    public function isUnsupported(): bool
    {
        return $this->state === self::UNSUPPORTED;
    }
}
