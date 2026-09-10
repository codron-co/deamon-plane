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

    public function projectUuid(): ?string
    {
        return self::firstUuid([
            $this->raw['project_uuid'] ?? null,
            data_get($this->raw, 'environment.project.uuid'),
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

    public function serverUuid(): ?string
    {
        return self::firstUuid([
            $this->raw['server_uuid'] ?? null,
            data_get($this->raw, 'destination.server.uuid'),
            data_get($this->raw, 'server.uuid'),
        ]);
    }

    /**
     * @return array{kind: CoolifyGitSourceKind, uuid: string}|null
     */
    public function gitSource(): ?array
    {
        $github = self::firstUuid([
            $this->raw['github_app_uuid'] ?? null,
            data_get($this->raw, 'github_app.uuid'),
            data_get($this->raw, 'source.github_app.uuid'),
            data_get($this->raw, 'source.uuid'),
        ]);

        if ($github !== null) {
            return ['kind' => CoolifyGitSourceKind::GithubApp, 'uuid' => $github];
        }

        $deployKey = self::firstUuid([
            $this->raw['private_key_uuid'] ?? null,
            data_get($this->raw, 'private_key.uuid'),
        ]);

        if ($deployKey !== null) {
            return ['kind' => CoolifyGitSourceKind::DeployKey, 'uuid' => $deployKey];
        }

        return null;
    }

    public function gitSourceUuid(): ?string
    {
        $source = $this->gitSource();

        return is_array($source) ? $source['uuid'] : null;
    }

    public function gitSourceKind(): ?CoolifyGitSourceKind
    {
        $source = $this->gitSource();

        return is_array($source) ? $source['kind'] : null;
    }

    public function isAutoDeploy(): bool
    {
        $value = $this->raw['is_auto_deploy'] ?? false;

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function gitCommitSha(): ?string
    {
        return self::nullableString($this->raw['git_commit_sha'] ?? null);
    }

    public function isDockerfilePack(): bool
    {
        return strtolower((string) $this->buildPack) === 'dockerfile';
    }

    public function isComposePack(): bool
    {
        return strtolower((string) $this->buildPack) === 'dockercompose';
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

    private static function firstUuid(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $value = self::uuidish($candidate);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Coolify resource uuids are nanoid-like strings. Integer FKs (and numeric strings) are skipped.
     */
    private static function uuidish(mixed $candidate): ?string
    {
        if (is_int($candidate) || is_float($candidate)) {
            return null;
        }

        if (! is_string($candidate)) {
            return null;
        }

        $value = trim($candidate);
        if ($value === '' || ctype_digit($value)) {
            return null;
        }

        return $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
