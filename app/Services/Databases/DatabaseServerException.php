<?php

namespace App\Services\Databases;

use App\Support\SecretRedactor;
use RuntimeException;

final class DatabaseServerException extends RuntimeException
{
    /**
     * Query exceptions carry the SQL text (e.g. IDENTIFIED BY '<password>'); the
     * passwords in play are passed as $secrets and never reach the message.
     * The original exception is not chained for the same reason.
     *
     * @param  list<string>  $secrets
     */
    public function __construct(string $message, array $secrets = [])
    {
        parent::__construct(SecretRedactor::redactSensitive($message, $secrets));
    }
}
