<?php

namespace App\Services\Sites;

final class SiteAppHealthIssue
{
    public function __construct(
        public readonly string $code,
        public readonly ?string $fix = null,
        public readonly ?string $key = null,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $code = trim((string) ($row['code'] ?? ''));

        return new self(
            code: $code !== '' ? $code : 'unknown',
            fix: self::nullableString($row['fix'] ?? null),
            key: self::nullableString($row['key'] ?? null),
        );
    }

    /**
     * @return array{code: string, fix: string|null, key: string|null}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'fix' => $this->fix,
            'key' => $this->key,
        ];
    }

    public function message(): string
    {
        $replace = ['key' => $this->key ?? ''];

        return match ($this->code) {
            'missing_app' => __('sites.app_health.issues.missing_app'),
            'dockerfile_pack' => __('sites.app_health.issues.dockerfile_pack'),
            'wrong_compose_file' => __('sites.app_health.issues.wrong_compose_file'),
            'missing_env' => __('sites.app_health.issues.missing_env', $replace),
            'wrong_env' => __('sites.app_health.issues.wrong_env', $replace),
            'missing_agent_secret' => __('sites.app_health.issues.missing_agent_secret'),
            'deploy_failed' => __('sites.app_health.issues.deploy_failed'),
            'domain_unbound' => __('sites.app_health.issues.domain_unbound', $replace),
            'agent_unhealthy' => __('sites.app_health.issues.agent_unhealthy'),
            'coolify_unreachable' => __('sites.app_health.issues.coolify_unreachable'),
            default => __('sites.app_health.issues.unknown'),
        };
    }

    public function fixLabel(): ?string
    {
        if ($this->fix === null) {
            return null;
        }

        return __('sites.app_health.fixes.'.$this->fix);
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
};
