<?php

namespace App\Services\Cloudflare;

class CloudflareProbeResult
{
    /**
     * @param  list<string>  $missingLabels
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly bool $tokenValid,
        public readonly bool $zoneRead,
        public readonly bool $zoneEdit,
        public readonly bool $dnsRead,
        public readonly bool $dnsEdit,
        public readonly bool $dnsUnverified,
        public readonly bool $accountIdInvalid,
        public readonly array $missingLabels,
        public readonly array $payload,
    ) {}

    public function requiredPassed(): bool
    {
        if (! $this->tokenValid || $this->accountIdInvalid) {
            return false;
        }

        if (! $this->zoneRead || ! $this->zoneEdit) {
            return false;
        }

        if ($this->dnsUnverified) {
            return true;
        }

        return $this->dnsRead && $this->dnsEdit;
    }

    /**
     * @return array<string, mixed>
     */
    public function storedPayload(): array
    {
        return $this->payload;
    }
}
