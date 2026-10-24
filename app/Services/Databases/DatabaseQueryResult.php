<?php

namespace App\Services\Databases;

final class DatabaseQueryResult
{
    /**
     * @param  list<string>  $columns
     * @param  list<list<string|null>>  $rows
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $columns,
        public readonly array $rows,
        public readonly bool $truncated,
        public readonly ?int $affected,
        public readonly int $milliseconds,
    ) {}
}
