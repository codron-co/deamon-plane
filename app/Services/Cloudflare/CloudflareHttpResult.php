<?php

namespace App\Services\Cloudflare;

final readonly class CloudflareHttpResult
{
    /**
     * @param  array<string, mixed>  $json
     */
    public function __construct(
        public int $status,
        public array $json,
    ) {}

    public function errorText(): string
    {
        return CloudflareApiException::messageFromJson($this->json) ?? '';
    }

    public function isAccountNotFound(): bool
    {
        if ($this->status !== 400) {
            return false;
        }

        $text = $this->errorText();

        return $text !== '' && preg_match('/account/i', $text) === 1 && preg_match('/not found|invalid|unauthorized/i', $text) === 1;
    }
}
