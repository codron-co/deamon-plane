<?php

namespace App\Services\Databases;

use App\Support\SecretRedactor;
use RuntimeException;

final class RedisServerException extends RuntimeException
{
    /**
     * Redis errors can echo command arguments (e.g. `ACL SETUSER ... >password`); the
     * passwords in play are passed as $secrets and never reach the message. The
     * original exception is not chained for the same reason.
     *
     * @param  list<string>  $secrets
     */
    public function __construct(string $message, array $secrets = [])
    {
        parent::__construct(SecretRedactor::redactSensitive($message, $secrets));
    }
}
