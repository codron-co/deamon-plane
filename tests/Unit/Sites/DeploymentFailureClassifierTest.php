<?php

namespace Tests\Unit\Sites;

use App\Services\Sites\Diagnosis\DeploymentDiagnosis;
use App\Services\Sites\Diagnosis\DeploymentFailureClassifier;
use Tests\TestCase;

class DeploymentFailureClassifierTest extends TestCase
{
    /**
     * Coolify output of the WetSan deploy on 2026-09-19: the app never starts because
     * the mysql container exits within a second. The excerpt is the JSON Coolify hands
     * over, so the lines are escaped.
     */
    private const WETSAN_EXCERPT = '[{"command":null,"output":"Container mysql-alaibjmbwyug8jpj1uigndk1-094151877919 Waiting","type":"stdout"},{"command":null,"output":"Container mysql-alaibjmbwyug8jpj1uigndk1-094151877919 Error dependency mysql failed to start\ndependency failed to start: container mysql-alaibjmbwyug8jpj1uigndk1-094151877919 exited (1)","type":"stderr"},{"command":null,"output":"Deployment failed: Command execution failed (exit code 1): docker exec xa7kk5solvy5s7i5e6lpeqqb bash -c \'docker compose --env-file \/artifacts\/xa7kk5solvy5s7i5e6lpeqqb\/.env up -d\'","type":"stderr"}]';

    public function test_mysql_dependency_failure_names_the_container_and_asks_for_its_log(): void
    {
        $diagnosis = (new DeploymentFailureClassifier)->classify('Coolify deploy’u başarısız oldu.', self::WETSAN_EXCERPT);

        $this->assertSame('mysql_exited', $diagnosis->code);
        $this->assertSame('mysql', $diagnosis->service);
        $this->assertSame('mysql-alaibjmbwyug8jpj1uigndk1-094151877919', $diagnosis->container);
        $this->assertSame(1, $diagnosis->exitCode);
        $this->assertTrue($diagnosis->needsContainerLogs);
        $this->assertNull($diagnosis->autoFixKey);
        $this->assertStringContainsString('dependency failed to start', (string) $diagnosis->evidence);
        $this->assertStringNotContainsString('\\n', (string) $diagnosis->evidence);
        $this->assertContains('docker logs mysql-alaibjmbwyug8jpj1uigndk1-094151877919 --tail 80', $diagnosis->commands());
    }

    public function test_container_log_refines_the_compose_symptom_into_the_real_cause(): void
    {
        $mysqlLog = "2026-09-19T09:47:18Z [Entrypoint] MySQL Docker Image 8.0.43\n2026-09-19T09:47:18Z [ERROR] [Entrypoint]: Database is uninitialized and password option is not specified\n    You need to specify one of the following as an environment variable: MYSQL_ROOT_PASSWORD";

        $diagnosis = (new DeploymentFailureClassifier)->classify(null, self::WETSAN_EXCERPT, $mysqlLog);

        $this->assertSame('mysql_no_root_password', $diagnosis->code);
        $this->assertSame('sync_env', $diagnosis->autoFixKey);
        $this->assertSame(['sync_env', 'redeploy'], $diagnosis->fixes);
        $this->assertFalse($diagnosis->needsContainerLogs);
        // The compose-level container name survives the refinement.
        $this->assertSame('mysql-alaibjmbwyug8jpj1uigndk1-094151877919', $diagnosis->container);
    }

    public function test_newer_data_directory_is_manual_only(): void
    {
        $log = "[ERROR] [MY-013171] [InnoDB] Cannot boot server version 80043 on data directory built by version 80400. Downgrade is not supported\n[ERROR] [MY-012930] [InnoDB] Plugin initialization aborted with error Generic error. Data directory was initialized by a newer version.";

        $diagnosis = (new DeploymentFailureClassifier)->classify(null, self::WETSAN_EXCERPT, $log);

        $this->assertSame('mysql_data_newer_version', $diagnosis->code);
        $this->assertNull($diagnosis->autoFixKey);
        $this->assertSame([], $diagnosis->fixes);
        $this->assertNotEmpty($diagnosis->steps());
    }

    public function test_oom_kill_beats_the_generic_exit(): void
    {
        $diagnosis = (new DeploymentFailureClassifier)->classify(
            null,
            'dependency failed to start: container app-abc123def456ghi789jkl012-094151856125 exited (137)',
        );

        $this->assertSame('oom_killed', $diagnosis->code);
        $this->assertSame(137, $diagnosis->exitCode);
        $this->assertSame('app-abc123def456ghi789jkl012-094151856125', $diagnosis->container);
        $this->assertSame(['restart_app'], $diagnosis->fixes);
    }

    public function test_disk_full_wins_over_build_failure(): void
    {
        $diagnosis = (new DeploymentFailureClassifier)->classify(
            null,
            "#12 ERROR: failed to solve: write /var/lib/docker/tmp/x: no space left on device\nBuild failed",
        );

        $this->assertSame('disk_full', $diagnosis->code);
        $this->assertSame('host', $diagnosis->service);
    }

    public function test_git_permission_denied_is_not_a_volume_permission_error(): void
    {
        $diagnosis = (new DeploymentFailureClassifier)->classify(
            null,
            "git@github.com: Permission denied (publickey).\nfatal: Could not read from remote repository.",
        );

        $this->assertSame('git_access', $diagnosis->code);
    }

    public function test_plane_side_timeouts_resync_automatically(): void
    {
        $classifier = new DeploymentFailureClassifier;

        $this->assertSame('coolify_timeout', $classifier->classify('Timed out waiting for Coolify deployment.', null)->code);
        $this->assertSame('sync_deployments', $classifier->classify('Timed out waiting for Coolify deployment.', null)->autoFixKey);
        $this->assertSame('no_deployment_uuid', $classifier->classify('Coolify did not return a deployment uuid.', null)->code);
        $this->assertSame('coolify_rate_limited', $classifier->classify('Too Many Attempts.', null)->code);
    }

    public function test_localized_compose_domains_message_is_recognised(): void
    {
        $this->app->setLocale('tr');
        $message = (string) __('coolify.errors.compose_domains_before_raw');

        $diagnosis = (new DeploymentFailureClassifier)->classify($message, null);

        $this->assertSame('compose_domains_before_raw', $diagnosis->code);
        $this->assertSame('redeploy', $diagnosis->autoFixKey);
    }

    public function test_unknown_output_asks_for_container_logs_and_offers_redeploy(): void
    {
        $diagnosis = (new DeploymentFailureClassifier)->classify('Something odd happened.', 'nothing recognisable here');

        $this->assertTrue($diagnosis->isUnknown());
        $this->assertTrue($diagnosis->needsContainerLogs);
        $this->assertSame(['redeploy'], $diagnosis->fixes);
        $this->assertNull($diagnosis->evidence);
    }

    public function test_every_code_has_wording_in_both_locales_and_only_safe_auto_fixes(): void
    {
        foreach (['tr', 'en'] as $locale) {
            // Read the file itself: the translator collapses an empty `commands` list to the key.
            $catalog = require lang_path($locale.'/deploy_diagnosis.php');
            foreach (DeploymentFailureClassifier::knownCodes() as $code) {
                $entry = $catalog['codes'][$code] ?? null;
                $this->assertIsArray($entry, "$locale wording missing for $code");
                $this->assertNotSame('', trim((string) ($entry['title'] ?? '')), "$locale title empty for $code");
                $this->assertNotSame('', trim((string) ($entry['cause'] ?? '')), "$locale cause empty for $code");
                $this->assertIsArray($entry['steps'] ?? null, "$locale steps for $code");
                $this->assertIsArray($entry['commands'] ?? null, "$locale commands for $code");
            }
        }

        $reflection = new \ReflectionClass(DeploymentFailureClassifier::class);
        foreach ($reflection->getConstant('RULES') as $rule) {
            if ($rule['auto'] !== null) {
                $this->assertContains($rule['auto'], DeploymentFailureClassifier::AUTO_SAFE, $rule['code'].' auto fix must be safe');
                $this->assertContains($rule['auto'], $rule['fixes'], $rule['code'].' auto fix must also be offered');
            }
        }
    }

    public function test_round_trip_through_array_keeps_every_field(): void
    {
        $original = (new DeploymentFailureClassifier)
            ->classify(null, self::WETSAN_EXCERPT)
            ->withContainerLogs('[ERROR] boom', null)
            ->withAutoFix('redeploy', DeploymentDiagnosis::AUTO_SKIPPED, 'repeated')
            ->stamped();

        $copy = DeploymentDiagnosis::fromArray($original->toArray());

        $this->assertSame($original->toArray(), $copy->toArray());
        $this->assertSame('[ERROR] boom', $copy->containerLogs);
        $this->assertSame('repeated', $copy->autoFix['reason'] ?? null);
        $this->assertNotNull($copy->diagnosedAt);
    }
}
