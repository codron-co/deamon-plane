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
        $uuid = trim((string) ($payload['uuid'] ?? ''));
        if ($uuid === '') {
            $id = $payload['id'] ?? '';
            $uuid = is_numeric($id) && (int) $id > 0 ? (string) $id : '';
        }

        return new self(
            kind: CoolifyGitSourceKind::GithubApp,
            uuid: $uuid,
            name: self::githubAppDisplayName($payload, $uuid),
            raw: $payload,
        );
    }

    /**
     * Coolify source name (and org when present). Never the generic "GitHub App" type label.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function githubAppDisplayName(array $payload, string $fallback = ''): string
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $organization = trim((string) ($payload['organization'] ?? ''));
        $generic = $name === '' || in_array(strtolower($name), ['github app', 'github_app'], true);

        if ($generic) {
            $name = $organization !== ''
                ? $organization
                : trim((string) ($payload['html_url'] ?? ''));
        } elseif ($organization !== '' && ! str_contains(mb_strtolower($name), mb_strtolower($organization))) {
            $name = $name.' / '.$organization;
        }

        return $name !== '' ? $name : $fallback;
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
