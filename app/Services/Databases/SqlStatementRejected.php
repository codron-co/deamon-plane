<?php

namespace App\Services\Databases;

use RuntimeException;

final class SqlStatementRejected extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('SQL statement rejected: '.$reason);
    }
}
