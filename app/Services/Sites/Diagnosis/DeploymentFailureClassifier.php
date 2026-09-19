<?php

namespace App\Services\Sites\Diagnosis;

/**
 * Reads a failed deployment's Coolify error, log excerpt and (when available) the
 * container log tail, and names the failure. Rules are ordered from the most
 * specific evidence (a MySQL error line) to the compose-level symptom ("dependency
 * failed to start"), so a deeper cause always wins over the generic one.
 *
 * Adding a rule: pick a code, add its wording to `lang/{tr,en}/deploy_diagnosis.php` and a
 * row to `docs/runbooks/deploy-failure-triage.md`. Only fixes in AUTO_SAFE may be
 * applied without an operator.
 */
final class DeploymentFailureClassifier
{
    /**
     * Fixes Plane may run on its own after a failed deploy: none of them delete
     * data, rotate a secret or move the site to another commit.
     *
     * @var list<string>
     */
    public const AUTO_SAFE = ['sync_env', 'redeploy', 'restart_app', 'sync_deployments', 'bind_domains'];

    /**
     * @var list<array{code: string, service: string, patterns: list<string>, auto: string|null, fixes: list<string>, logs: bool}>
     */
    private const RULES = [
        // --- container-level evidence (needs the container log) ---
        [
            'code' => 'mysql_no_root_password',
            'service' => 'mysql',
            'patterns' => ['/Database is uninitialized and password option is not specified/i'],
            'auto' => 'sync_env',
            'fixes' => ['sync_env', 'redeploy'],
            'logs' => false,
        ],
        [
            'code' => 'mysql_data_newer_version',
            'service' => 'mysql',
            'patterns' => [
                '/initialized by a newer version/i',
                '/Data dictionary upgrade/i',
                '/(Unsupported|unknown) redo log format/i',
                '/Upgrade after a crash is not supported/i',
                '/is not supported by this version of the server/i',
            ],
            'auto' => null,
            'fixes' => [],
            'logs' => false,
        ],
        [
            'code' => 'mysql_locked',
            'service' => 'mysql',
            'patterns' => ['/Unable to lock \.\/ibdata1/i', '/do not already have another mysqld process/i'],
            'auto' => null,
            'fixes' => ['stop_then_redeploy'],
            'logs' => false,
        ],
        [
            'code' => 'mysql_corrupt',
            'service' => 'mysql',
            'patterns' => [
                '/InnoDB: Assertion failure/i',
                '/InnoDB: .*corrupt/i',
                '/checksum mismatch/i',
                '/Tablespace .* is corrupted/i',
                '/log sequence number .* is in the future/i',
            ],
            'auto' => null,
            'fixes' => [],
            'logs' => false,
        ],
        // --- git / registry ---
        [
            'code' => 'git_access',
            'service' => 'git',
            'patterns' => [
                '/could not read Username/i',
                '/Repository not found/i',
                '/Authentication failed for/i',
                '/Invalid username or (token|password)/i',
                '/Permission denied \(publickey\)/i',
                '/Could not resolve host: github\.com/i',
                '/Bad credentials/i',
            ],
            'auto' => null,
            'fixes' => [],
            'logs' => false,
        ],
        [
            'code' => 'git_ref_missing',
            'service' => 'git',
            'patterns' => [
                "/couldn'?t find remote ref/i",
                '/Remote branch .* not found/i',
                '/reference is not a tree/i',
                '/invalid reference:/i',
                '/unknown revision or path/i',
            ],
            'auto' => null,
            'fixes' => ['follow_head'],
            'logs' => false,
        ],
        [
            'code' => 'registry_rate_limited',
            'service' => 'build',
            'patterns' => ['/toomanyrequests/i', '/pull rate limit/i'],
            'auto' => 'redeploy',
            'fixes' => ['redeploy'],
            'logs' => false,
        ],
        // --- host ---
        [
            'code' => 'disk_full',
            'service' => 'host',
            'patterns' => ['/No space left on device/i', '/\bENOSPC\b/', '/disk quota exceeded/i'],
            'auto' => null,
            'fixes' => [],
            'logs' => false,
        ],
        [
            'code' => 'oom_killed',
            'service' => 'host',
            'patterns' => ['/Out of memory/i', '/OOMKilled/i', '/oom-kill/i', '/exited \(137\)/', '/Killed process \d+/i'],
            'auto' => null,
            'fixes' => ['restart_app'],
            'logs' => true,
        ],
        [
            'code' => 'port_conflict',
            'service' => 'host',
            'patterns' => ['/port is already allocated/i', '/address already in use/i'],
            'auto' => null,
            'fixes' => ['stop_then_redeploy'],
            'logs' => false,
        ],
        [
            'code' => 'docker_daemon',
            'service' => 'host',
            'patterns' => ['/Cannot connect to the Docker daemon/i', '/is the docker daemon running/i'],
            'auto' => null,
            'fixes' => ['redeploy'],
            'logs' => false,
        ],
        // --- app container ---
        [
            'code' => 'app_db_unreachable',
            'service' => 'app',
            'patterns' => [
                '/Veritabanina 60 saniye icinde ulasilamadi/i',
                '/SQLSTATE\[HY000\] \[2002\]/',
                '/getaddrinfo for mysql failed/i',
                '/Connection refused[^\n]*3306/i',
            ],
            'auto' => 'restart_app',
            'fixes' => ['restart_app', 'redeploy'],
            'logs' => true,
        ],
        [
            'code' => 'app_redis_unreachable',
            'service' => 'app',
            'patterns' => ["/Redis'e 60 saniye icinde ulasilamadi/i", '/Connection refused[^\n]*6379/i'],
            'auto' => 'restart_app',
            'fixes' => ['restart_app', 'redeploy'],
            'logs' => true,
        ],
        [
            'code' => 'migration_failed',
            'service' => 'app',
            'patterns' => [
                '/SQLSTATE\[/',
                '/Illuminate\\\\Database\\\\QueryException/',
                '/Migration table not found/i',
            ],
            'auto' => null,
            'fixes' => ['rollback_last_good'],
            'logs' => true,
        ],
        [
            'code' => 'volume_permission',
            'service' => 'host',
            'patterns' => ['/Permission denied/i', '/Operation not permitted/i', '/chown: (cannot|changing ownership)/i'],
            'auto' => null,
            'fixes' => [],
            'logs' => false,
        ],
        // --- compose-level symptom: which service died ---
        [
            'code' => 'mysql_exited',
            'service' => 'mysql',
            'patterns' => [
                '/dependency failed to start: container mysql-/i',
                '/dependency mysql failed to start/i',
                '/container mysql-[\w-]+ (is unhealthy|exited)/i',
            ],
            'auto' => null,
            'fixes' => ['redeploy'],
            'logs' => true,
        ],
        [
            'code' => 'redis_exited',
            'service' => 'redis',
            'patterns' => [
                '/dependency failed to start: container redis-/i',
                '/dependency redis failed to start/i',
                '/container redis-[\w-]+ (is unhealthy|exited)/i',
            ],
            'auto' => null,
            'fixes' => ['redeploy'],
            'logs' => true,
        ],
        [
            'code' => 'app_exited',
            'service' => 'app',
            'patterns' => [
                '/dependency failed to start: container app-/i',
                '/container app-[\w-]+ (is unhealthy|exited)/i',
            ],
            'auto' => null,
            'fixes' => ['restart_app', 'redeploy'],
            'logs' => true,
        ],
        // --- build ---
        [
            'code' => 'build_failed',
            'service' => 'build',
            'patterns' => [
                '/failed to solve/i',
                '/did not complete successfully: exit code/i',
                '/npm ERR!/',
                '/Your requirements could not be resolved/i',
                '/ERROR: failed to build/i',
                '/returned a non-zero code/i',
            ],
            'auto' => null,
            'fixes' => ['rollback_last_good'],
            'logs' => false,
        ],
        // --- Coolify / Plane side ---
        [
            'code' => 'compose_domains_before_raw',
            'service' => 'coolify',
            'patterns' => ['/docker_compose_domains without docker_compose_raw/i', '/henüz compose dosyasını git/iu'],
            'auto' => 'redeploy',
            'fixes' => ['redeploy', 'bind_domains'],
            'logs' => false,
        ],
        [
            'code' => 'no_deployment_uuid',
            'service' => 'coolify',
            'patterns' => ['/did not return a deployment uuid/i'],
            'auto' => 'sync_deployments',
            'fixes' => ['sync_deployments', 'redeploy'],
            'logs' => false,
        ],
        [
            'code' => 'coolify_timeout',
            'service' => 'coolify',
            'patterns' => ['/Timed out waiting for Coolify deployment/i'],
            'auto' => 'sync_deployments',
            'fixes' => ['sync_deployments'],
            'logs' => false,
        ],
        [
            'code' => 'coolify_rate_limited',
            'service' => 'coolify',
            'patterns' => ['/Too Many Attempts/i', '/istek sınırı/iu', '/HTTP 429/'],
            'auto' => 'sync_deployments',
            'fixes' => ['sync_deployments'],
            'logs' => false,
        ],
    ];

    /**
     * @return list<string>
     */
    public static function knownCodes(): array
    {
        $codes = array_map(static fn (array $rule): string => $rule['code'], self::RULES);
        $codes[] = DeploymentDiagnosis::UNKNOWN;

        return $codes;
    }

    public function classify(?string $errorMessage, ?string $logExcerpt, ?string $containerLogs = null): DeploymentDiagnosis
    {
        $text = implode("\n", array_filter([
            (string) $errorMessage,
            (string) $logExcerpt,
            (string) $containerLogs,
        ], static fn (string $part): bool => trim($part) !== ''));

        [$container, $exitCode] = $this->containerFromText($text);

        foreach (self::RULES as $rule) {
            foreach ($rule['patterns'] as $pattern) {
                if (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }

                return new DeploymentDiagnosis(
                    code: $rule['code'],
                    service: $rule['service'],
                    autoFixKey: $rule['auto'],
                    fixes: $rule['fixes'],
                    evidence: $this->evidenceAround($text, (int) $match[0][1], (string) $match[0][0]),
                    container: $container,
                    exitCode: $exitCode,
                    needsContainerLogs: $rule['logs'] && trim((string) $containerLogs) === '',
                );
            }
        }

        $unknown = DeploymentDiagnosis::unknown();

        return new DeploymentDiagnosis(
            code: $unknown->code,
            service: $unknown->service,
            autoFixKey: null,
            fixes: $unknown->fixes,
            evidence: null,
            container: $container,
            exitCode: $exitCode,
            needsContainerLogs: trim((string) $containerLogs) === '',
        );
    }

    /**
     * @return array{0: string|null, 1: int|null}
     */
    private function containerFromText(string $text): array
    {
        if (preg_match('/container ((?:mysql|redis|app)-[a-z0-9]+-\d+) (?:exited \((\d+)\)|is unhealthy)/i', $text, $m) === 1) {
            return [$m[1], isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null];
        }

        if (preg_match('/((?:mysql|redis|app)-[a-z0-9]{20,}-\d{9,})/i', $text, $m) === 1) {
            return [$m[1], null];
        }

        return [null, null];
    }

    /**
     * The matched line, trimmed of JSON escapes and cut to what a hover can show.
     */
    private function evidenceAround(string $text, int $offset, string $matched): string
    {
        $start = strrpos(substr($text, 0, $offset), "\n");
        $start = $start === false ? 0 : $start + 1;
        $end = strpos($text, "\n", $offset);
        $line = substr($text, $start, $end === false ? null : $end - $start);

        // Coolify log excerpts are JSON: strip the escaped line breaks and slashes.
        $line = $this->plain($line);
        if (strlen($line) <= 400) {
            return $line;
        }

        // Keep the window around the match, not the start of a very long JSON row.
        $needle = $this->plain($matched);
        $at = $needle === '' ? false : strpos($line, $needle);
        $from = $at === false ? 0 : max(0, $at - 120);

        return trim(substr($line, $from, 400));
    }

    private function plain(string $value): string
    {
        $value = str_replace(['\\n', '\\/', '\\"'], [' ', '/', '"'], $value);

        return preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
    }
}
