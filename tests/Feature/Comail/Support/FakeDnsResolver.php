<?php

namespace Tests\Feature\Comail\Support;

use App\Services\Comail\DnsResolver;

class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, list<array<string, mixed>>> "TYPE name" => answers */
    public array $answers = [];

    public function set(string $type, string $name, array $answers): self
    {
        $this->answers[strtoupper($type).' '.strtolower($name)] = $answers;

        return $this;
    }

    public function lookup(string $name, string $type): array
    {
        return $this->answers[strtoupper($type).' '.strtolower($name)] ?? [];
    }
}
