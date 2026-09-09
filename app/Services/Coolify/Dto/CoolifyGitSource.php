<?php

namespace App\Services\Coolify\Dto;

use App\Enums\CoolifyGitSourceKind;

final class CoolifyGitSource
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly CoolifyGitSourceKind $kind,
        public readonly string $uuid,
        public readonly string $name,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromGithubApp(array $payload): self
    {
        $uuid = (string) ($payload['uuid'] ?? $payload['id'] ?? '');

        return new self(
            kind: CoolifyGitSourceKind::GithubApp,
            uuid: $uuid,
            name: (string) ($payload['name'] ?? $payload['html_url'] ?? $uuid),
            raw: $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPrivateKey(array $payload): self
    {
        $uuid = (string) ($payload['uuid'] ?? $payload['id'] ?? '');

        return new self(
            kind: CoolifyGitSourceKind::DeployKey,
            uuid: $uuid,
            name: (string) ($payload['name'] ?? $payload['description'] ?? $uuid),
            raw: $payload,
        );
    }
}
