<?php

namespace App\Services\Hostinger;

use RuntimeException;

class HostingerMailException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        public readonly int $status,
        string $message = 'Mail provider request failed.',
    ) {
        parent::__construct($message, $status);
    }

    public static function fromStatus(int $status): self
    {
        $error = match (true) {
            $status === 401, $status === 403 => 'unauthorized',
            $status === 404 => 'not_found',
            $status === 409 => 'conflict',
            $status === 422 => 'validation_failed',
            $status === 429 => 'rate_limited',
            default => 'upstream_error',
        };

        return new self($error, $status >= 400 ? $status : 502);
    }
}
