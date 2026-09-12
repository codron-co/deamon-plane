<?php

namespace App\Services\Coolify;

use RuntimeException;
use Throwable;

/**
 * Another Coolify build is already in flight on the shared connection/server.
 * Callers should retry after the open deployment finishes (bulk fan-out defers).
 */
class CoolifyDeployBusyException extends RuntimeException
{
    public function __construct(string $message = '', int $code = 503, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
