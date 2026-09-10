<?php

namespace Tests\Unit\Sites;

use App\Enums\Channel;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Models\Deployment;
use App\Models\Site;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\Dto\CoolifyDeployment;
use App\Services\Sites\DeploymentFailureText;
use App\Support\SecretRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeploymentFailureTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_from_remote_includes_message_errors_json_and_redacted_logs(): void
    {
        $site = Site::factory()->create([
            'app_key_encrypted' => 'base64:super-secret-app-key-value-aaaa',
            'agent_secret_encrypted' => 'agent-secret-value',
        ]);

        $remote = CoolifyDeployment::fromArray([
            'uuid' => 'dep-1',
            'status' => 'failed',
            'message' => 'Validation failed.',
            'errors' => ['fqdn' => ['This field is not allowed.']],
            'logs' => "APP_KEY=base64:super-secret-app-key-value-aaaa\ncompose error",
        ]);

        $detail = DeploymentFailureText::fromRemote($site, $remote, 'Coolify deployment failed.');

        $this->assertStringContainsString('Validation failed.', $detail['error_message']);
        $this->assertStringContainsString('"fqdn"', $detail['error_message']);
        $this->assertStringContainsString('This field is not allowed.', $detail['error_message']);
        $this->assertStringContainsString('compose error', (string) $detail['log_excerpt']);
        $this->assertStringNotContainsString('super-secret-app-key-value-aaaa', (string) $detail['log_excerpt']);
        $this->assertArrayNotHasKey('logs', $remote->raw);
    }

    public function test_from_exception_includes_coolify_errors_json(): void
    {
        $site = Site::factory()->create();
        $exception = new CoolifyApiException(
            'Validation failed. fqdn: This field is not allowed.',
            422,
            [],
            ['message' => 'Validation failed.', 'errors' => ['fqdn' => ['This field is not allowed.']]],
        );

        $detail = DeploymentFailureText::fromException($site, $exception, 'Provisioning failed.');

        $this->assertStringContainsString('Validation failed.', $detail['error_message']);
        $this->assertStringContainsString('Coolify errors:', $detail['error_message']);
        $this->assertStringContainsString('This field is not allowed.', $detail['error_message']);
    }

    public function test_pasteable_report_includes_status_and_error(): void
    {
        $site = Site::factory()->create([
            'name' => 'Deamon Test',
            'slug' => 'deamon-test',
            'primary_domain' => 'test.deamon.codron.co',
            'coolify_app_uuid' => 'app-1',
        ]);
        $deployment = Deployment::factory()->create([
            'site_id' => $site->id,
            'channel' => Channel::Alpha,
            'trigger' => DeploymentTrigger::Create,
            'status' => DeploymentStatus::Failed,
            'commit_sha' => 'abc1234',
            'error_message' => 'Validation failed.',
            'log_excerpt' => 'build exploded',
        ]);
        $deployment->setRelation('site', $site);

        $text = DeploymentFailureText::pasteable($deployment);

        $this->assertStringContainsString('Status: failed', $text);
        $this->assertStringContainsString('Channel: alpha', $text);
        $this->assertStringContainsString('Trigger: create', $text);
        $this->assertStringContainsString('abc1234', $text);
        $this->assertStringContainsString('Validation failed.', $text);
        $this->assertStringContainsString('build exploded', $text);
        $this->assertStringContainsString('test.deamon.codron.co', $text);
    }

    public function test_secret_redactor_strips_env_patterns(): void
    {
        $text = SecretRedactor::redactSensitive(
            'Bearer tok-aaa APP_KEY=base64:yyyy CONTROL_PLANE_AGENT_SECRET=shh',
            [],
        );

        $this->assertStringNotContainsString('tok-aaa', $text);
        $this->assertStringNotContainsString('base64:yyyy', $text);
        $this->assertStringNotContainsString('shh', $text);
    }
}
