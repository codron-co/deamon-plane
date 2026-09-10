<?php

namespace App\Services\Cloudflare;

final readonly class CloudflareProbeResult
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $flashKey,
        public string $message,
        public array $payload,
    ) {}
}
