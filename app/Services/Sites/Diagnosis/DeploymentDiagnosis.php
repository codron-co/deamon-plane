<?php

namespace App\Services\Sites\Diagnosis;

/**
 * What Plane concluded about a failed deployment: a failure code the ops UI and the
 * runbook both key on, the evidence line it was read from, the container log tail
 * (when Coolify could hand it over) and what the automatic fix did.
 *
 * Stored as JSON on `deployments.diagnosis`. Wording lives in `lang/{tr,en}/deploy_diagnosis.php`
 * so the row never carries English prose or secrets.
 */
final class DeploymentDiagnosis
{
    public const UNKNOWN = 'unknown';

    public const AUTO_APPLIED = 'applied';

    public const AUTO_SKIPPED = 'skipped';

    public const AUTO_FAILED = 'failed';

    /**
     * @param  list<string>  $fixes
     * @param  array{fix: string, status: string, reason: string|null, at: string}|null  $autoFix
     */
    public function __construct(
        public readonly string $code,
        public readonly string $service,
        public readonly ?string $autoFixKey,
        public readonly array $fixes,
        public readonly ?string $evidence,
        public readonly ?string $container,
        public readonly ?int $exitCode,
        public readonly bool $needsContainerLogs,
        public readonly ?string $containerLogs = null,
        public readonly ?string $containerLogsError = null,
        public readonly ?array $autoFix = null,
        public readonly ?string $diagnosedAt = null,
    ) {}

    public static function unknown(): self
    {
        return new self(
            code: self::UNKNOWN,
            service: 'unknown',
            autoFixKey: null,
            fixes: ['redeploy'],
            evidence: null,
            container: null,
            exitCode: null,
            needsContainerLogs: true,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $fixes = [];
        foreach ((array) ($row['fixes'] ?? []) as $fix) {
            if (is_string($fix) && trim($fix) !== '') {
                $fixes[] = trim($fix);
            }
        }

        $auto = is_array($row['auto_fix'] ?? null) ? $row['auto_fix'] : null;
        if ($auto !== null && ! isset($auto['fix'], $auto['status'])) {
            $auto = null;
        }

        return new self(
            code: self::string($row['code'] ?? null) ?? self::UNKNOWN,
            service: self::string($row['service'] ?? null) ?? 'unknown',
            autoFixKey: self::string($row['auto_fix_key'] ?? null),
            fixes: $fixes,
            evidence: self::string($row['evidence'] ?? null),
            container: self::string($row['container'] ?? null),
            exitCode: is_numeric($row['exit_code'] ?? null) ? (int) $row['exit_code'] : null,
            needsContainerLogs: (bool) ($row['needs_container_logs'] ?? false),
            containerLogs: self::string($row['container_logs'] ?? null),
            containerLogsError: self::string($row['container_logs_error'] ?? null),
            autoFix: $auto === null ? null : [
                'fix' => (string) $auto['fix'],
                'status' => (string) $auto['status'],
                'reason' => self::string($auto['reason'] ?? null),
                'at' => (string) ($auto['at'] ?? ''),
            ],
            diagnosedAt: self::string($row['diagnosed_at'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'service' => $this->service,
            'auto_fix_key' => $this->autoFixKey,
            'fixes' => $this->fixes,
            'evidence' => $this->evidence,
            'container' => $this->container,
            'exit_code' => $this->exitCode,
            'needs_container_logs' => $this->needsContainerLogs,
            'container_logs' => $this->containerLogs,
            'container_logs_error' => $this->containerLogsError,
            'auto_fix' => $this->autoFix,
            'diagnosed_at' => $this->diagnosedAt,
        ];
    }

    public function isUnknown(): bool
    {
        return $this->code === self::UNKNOWN;
    }

    public function withContainerLogs(?string $logs, ?string $error): self
    {
        return new self(
            $this->code,
            $this->service,
            $this->autoFixKey,
            $this->fixes,
            $this->evidence,
            $this->container,
            $this->exitCode,
            $this->needsContainerLogs,
            $logs,
            $error,
            $this->autoFix,
            $this->diagnosedAt,
        );
    }

    public function withAutoFix(string $fix, string $status, ?string $reason = null): self
    {
        return new self(
            $this->code,
            $this->service,
            $this->autoFixKey,
            $this->fixes,
            $this->evidence,
            $this->container,
            $this->exitCode,
            $this->needsContainerLogs,
            $this->containerLogs,
            $this->containerLogsError,
            ['fix' => $fix, 'status' => $status, 'reason' => $reason, 'at' => now()->toIso8601String()],
            $this->diagnosedAt,
        );
    }

    public function stamped(): self
    {
        return new self(
            $this->code,
            $this->service,
            $this->autoFixKey,
            $this->fixes,
            $this->evidence,
            $this->container,
            $this->exitCode,
            $this->needsContainerLogs,
            $this->containerLogs,
            $this->containerLogsError,
            $this->autoFix,
            now()->toIso8601String(),
        );
    }

    public function title(): string
    {
        return (string) __('deploy_diagnosis.codes.'.$this->code.'.title', $this->replacements());
    }

    public function cause(): string
    {
        return (string) __('deploy_diagnosis.codes.'.$this->code.'.cause', $this->replacements());
    }

    /**
     * Operator steps, in order. Empty when the code has no manual work.
     *
     * @param  array<string, string>  $replacements  Site context (uuid, domain) merged over the defaults.
     * @return list<string>
     */
    public function steps(array $replacements = []): array
    {
        return $this->lines('steps', $replacements);
    }

    /**
     * Shell commands for whoever has the server. Placeholders are filled here so the
     * operator can paste them as-is.
     *
     * @param  array<string, string>  $replacements
     * @return list<string>
     */
    public function commands(array $replacements = []): array
    {
        return $this->lines('commands', $replacements);
    }

    public function autoFixLabel(): ?string
    {
        if ($this->autoFix === null) {
            return null;
        }

        $fix = (string) __('sites.app_health.fixes.'.$this->autoFix['fix']);
        $key = 'deploy_diagnosis.auto.'.$this->autoFix['status'];

        return (string) __($key, [
            'fix' => $fix,
            'reason' => $this->autoFix['reason'] !== null
                ? (string) __('deploy_diagnosis.skip_reasons.'.$this->autoFix['reason'])
                : '',
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function replacements(string $siteUuid = '', string $domain = ''): array
    {
        return [
            'container' => $this->container ?? ($this->service.'-<uuid>-<ts>'),
            'exit' => $this->exitCode === null ? '?' : (string) $this->exitCode,
            'service' => $this->service,
            'uuid' => $siteUuid !== '' ? $siteUuid : '<coolify-uuid>',
            'domain' => $domain !== '' ? $domain : '<domain>',
        ];
    }

    /**
     * @param  array<string, string>  $replacements
     * @return list<string>
     */
    private function lines(string $key, array $replacements = []): array
    {
        $raw = __('deploy_diagnosis.codes.'.$this->code.'.'.$key);
        if (! is_array($raw)) {
            return [];
        }

        $map = self::placeholders(array_merge(
            $this->replacements(),
            array_filter($replacements, static fn (string $value): bool => $value !== ''),
        ));

        $out = [];
        foreach ($raw as $line) {
            if (! is_string($line)) {
                continue;
            }
            $out[] = strtr($line, $map);
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $replacements
     * @return array<string, string>
     */
    public static function placeholders(array $replacements): array
    {
        $map = [];
        foreach ($replacements as $key => $value) {
            $map[':'.$key] = $value;
        }

        return $map;
    }

    private static function string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
