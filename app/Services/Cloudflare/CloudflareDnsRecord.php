<?php

namespace App\Services\Cloudflare;

final readonly class CloudflareDnsRecord
{
    public function __construct(
        public string $type,
        public string $relativeName,
        public string $content,
        public ?int $priority = null,
        public bool $proxied = false,
    ) {}

    public function fqdn(string $apex): string
    {
        if ($this->relativeName === '@') {
            return $apex;
        }

        return $this->relativeName.'.'.$apex;
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiPayload(string $apex): array
    {
        $payload = [
            'type' => $this->type,
            'name' => $this->fqdn($apex),
            'content' => $this->content,
            'ttl' => 1,
        ];

        if (in_array($this->type, ['A', 'AAAA', 'CNAME'], true)) {
            $payload['proxied'] = $this->proxied;
        }

        if ($this->priority !== null) {
            $payload['priority'] = $this->priority;
        }

        return $payload;
    }
}
