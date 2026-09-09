<?php

namespace App\Services\GitHub;

use RuntimeException;

class GitHubApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
    ) {
        parent::__construct($message);
    }
}
