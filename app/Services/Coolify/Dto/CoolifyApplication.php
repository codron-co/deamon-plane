<?php

namespace App\Services\Coolify\Dto;

use App\Enums\CoolifyGitSourceKind;
use App\Services\Coolify\CoolifyDomainParser;

final class CoolifyApplication
{
    /**
     * @param  list<array{name: string, domain: string}>  $composeDomains
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $uuid,
        public readonly string $name,
        public readonly ?string $gitBranch,
        public readonly ?string $buildPack,
        public readonly ?string $fqdn,
        public readonly ?string $gitRepository,
        public readonly ?string $dockerComposeLocation,
        public readonly array $composeDomains,
        public readonly ?string $status,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $uuid = (string) ($payload['uuid'] ?? '');
        $composeDomains = CoolifyDomainParser::normalize($payload['docker_compose_domains'] ?? null);
        $fqdn = $payload['fqdn'] ?? null;
        $fqdn = is_string($fqdn) && $fqdn !== '' ? $fqdn : CoolifyDomainParser::firstDomain($composeDomains);

        return new self(
            uuid: $uuid,
            name: (string) ($payload['name'] ?? ''),
            gitBranch: self::nullableString($payload['git_branch'] ?? null),
            buildPack: self::nullableString($payload['build_pack'] ?? null),
            fqdn: $fqdn,
            gitRepository: self::nullableString($payload['git_repository'] ?? null),
            dockerComposeLocation: self::nullableString($payload['docker_compose_location'] ?? null),
            composeDomains: $composeDomains,
            status: self::nullableString($payload['status'] ?? null),
            raw: $payload,
        );
    }

    public function primaryDomain(): ?string
    {
        $candidates = [];

        foreach ($this->composeDomains as $row) {
            if (($row['name'] ?? '') !== CoolifyDomainParser::COMPOSE_SERVICE) {
                continue;
            }

            foreach (self::splitDomainUrls((string) ($row['domain'] ?? '')) as $url) {
                $candidates[] = $url;
            }
        }

        foreach (self::splitDomainUrls((string) ($this->fqdn ?? '')) as $url) {
            $candidates[] = $url;
        }

        foreach ($candidates as $url) {
            $host = self::hostFromUrl($url);
            if ($host !== '' && ! CoolifyDomainParser::isGeneratedWildcardHost($host)) {
                return $url;
            }
        }

        return $candidates[0] ?? CoolifyDomainParser::firstDomain($this->composeDomains);
    }

    public function serverUuid(): ?string
    {
        return self::firstUuid([
            $this->raw['server_uuid'] ?? null,
            data_get($this->raw, 'destination.server.uuid'),
            data_get($this->raw, 'server.uuid'),
        ]);
    }

    public function projectUuid(): ?string
    {
        return self::firstUuid([
            $this->raw['project_uuid'] ?? null,
            data_get($this->raw, 'environment.project.uuid'),
            data_get($this->raw, 'environment.project_uuid'),
            data_get($this->raw, 'project.uuid'),
        ]);
    }

    public function environmentUuid(): ?string
    {
        return self::firstUuid([
            $this->raw['environment_uuid'] ?? null,
            data_get($this->raw, 'environment.uuid'),
        ]);
    }

    public function gitSourceKind(): ?CoolifyGitSourceKind
    {
        if ($this->githubAppUuid() !== null) {
            return CoolifyGitSourceKind::GithubApp;
        }

        if ($this->privateKeyUuid() !== null) {
            return CoolifyGitSourceKind::DeployKey;
        }

        return null;
    }

    public function gitSourceUuid(): ?string
    {
        return $this->githubAppUuid() ?? $this->privateKeyUuid();
    }

    public function githubAppUuid(): ?string
    {
        return self::firstUuid([
            $this->raw['github_app_uuid'] ?? null,
            data_get($this->raw, 'github_app.uuid'),
            data_get($this->raw, 'githubApp.uuid'),
            data_get($this->raw, 'source.github_app.uuid'),
        ]);
    }

    public function privateKeyUuid(): ?string
    {
        return self::firstUuid([
            $this->raw['private_key_uuid'] ?? null,
            data_get($this->raw, 'private_key.uuid'),
            data_get($this->raw, 'privateKey.uuid'),
        ]);
    }

    /**
     * @return list<string>
     */
    private static function splitDomainUrls(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\s*,\s*/', trim($raw)) ?: [] as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out;
    }

    private static function hostFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return strtolower($host);
        }

        return strtolower(preg_replace('#^https?://#i', '', strtok($url, '/')) ?: '');
    }

    /**
     * @param  list<mixed>  $candidates
     */
    private static function firstUuid(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $uuid = self::uuidish($candidate);
            if ($uuid !== null) {
                return $uuid;
            }
        }

        return null;
    }

    private static function uuidish(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $uuid = trim((string) $value);
        if ($uuid === '' || preg_match('/^[A-Za-z0-9_-]{8,64}$/', $uuid) !== 1) {
            return null;
        }

        if (ctype_digit($uuid)) {
            return null;
        }

        return $uuid;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
