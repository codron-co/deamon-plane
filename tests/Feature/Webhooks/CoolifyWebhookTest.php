<?php

namespace Tests\Feature\Webhooks;

use App\Enums\Channel;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CoolifySetting;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use App\Support\CoolifyWebhookSignature;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CoolifyWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'plane-coolify-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        CoolifySetting::factory()->create([
            'base_url' => 'https://coolify.test',
            'webhook_secret' => self::SECRET,
            'default_project_uuid' => 'proj_test',
        ]);
    }

    public function test_invalid_signature_is_rejected_and_does_not_update_status(): void
    {
        $deployment = $this->inProgressDeployment();

        $this->signedPost([
            'event' => 'deployment_success',
            'deployment_uuid' => $deployment->coolify_deployment_uuid,
            'application_uuid' => $deployment->site->coolify_app_uuid,
        ], 'wrong-secret')->assertUnauthorized();

        $deployment->refresh();
        $this->assertSame(DeploymentStatus::InProgress, $deployment->status);
        $this->assertSame(SiteStatus::Provisioning, $deployment->site->fresh()->status);
    }

    public function test_missing_signature_is_rejected(): void
    {
        $deployment = $this->inProgressDeployment();
        $payload = json_encode([
            'event' => 'deployment_success',
            'deployment_uuid' => $deployment->coolify_deployment_uuid,
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/webhooks/coolify', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertUnauthorized();

        $this->assertSame(DeploymentStatus::InProgress, $deployment->fresh()->status);
    }

    public function test_empty_secret_rejects_even_with_hmac_of_empty_key(): void
    {
        CoolifySetting::query()->update(['webhook_secret' => null]);
        config(['ops.coolify.webhook_secret' => null]);

        $deployment = $this->inProgressDeployment();
        $body = json_encode([
            'event' => 'deployment_success',
            'deployment_uuid' => $deployment->coolify_deployment_uuid,
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/webhooks/coolify', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_COOLIFY_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, ''),
        ], $body)->assertUnauthorized();
    }

    public function test_valid_signature_maps_success_event_to_finished(): void
    {
        $deployment = $this->inProgressDeployment();

        $this->signedPost([
            'success' => true,
            'event' => 'deployment_success',
            'deployment_uuid' => $deployment->coolify_deployment_uuid,
            'application_uuid' => $deployment->site->coolify_app_uuid,
            'commit' => 'abc123def456',
        ])->assertOk()->assertJson(['ok' => true, 'updated' => true]);

        $deployment->refresh();
        $this->assertSame(DeploymentStatus::Finished, $deployment->status);
        $this->assertSame('abc123def456', $deployment->commit_sha);
        $this->assertNotNull($deployment->finished_at);
        $this->assertSame(SiteStatus::Active, $deployment->site->fresh()->status);
    }

    public function test_valid_signature_maps_failed_event_to_failed(): void
    {
        $deployment = $this->inProgressDeployment();

        $this->signedPost([
            'success' => false,
            'event' => 'deployment_failed',
            'deployment_uuid' => $deployment->coolify_deployment_uuid,
            'application_uuid' => $deployment->site->coolify_app_uuid,
        ])->assertOk();

        $deployment->refresh();
        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        $this->assertSame(SiteStatus::Error, $deployment->site->fresh()->status);
    }

    public function test_api_shaped_payload_status_field_is_mapped(): void
    {
        $deployment = $this->inProgressDeployment();

        $this->signedPost([
            'uuid' => $deployment->coolify_deployment_uuid,
            'status' => 'finished',
            'commit' => 'deadbeef',
            'application_uuid' => $deployment->site->coolify_app_uuid,
        ], self::SECRET, 'X-Hub-Signature-256')->assertOk();

        $this->assertSame(DeploymentStatus::Finished, $deployment->fresh()->status);
        $this->assertSame('deadbeef', $deployment->fresh()->commit_sha);
    }

    public function test_env_secret_is_used_when_database_secret_is_empty(): void
    {
        CoolifySetting::query()->update(['webhook_secret' => null]);
        config(['ops.coolify.webhook_secret' => 'env-only-secret']);

        $deployment = $this->inProgressDeployment();

        $this->signedPost([
            'event' => 'deployment_success',
            'deployment_uuid' => $deployment->coolify_deployment_uuid,
            'application_uuid' => $deployment->site->coolify_app_uuid,
        ], 'env-only-secret')->assertOk();

        $this->assertSame(DeploymentStatus::Finished, $deployment->fresh()->status);
    }

    public function test_secret_is_never_written_to_logs(): void
    {
        Log::spy();

        $this->signedPost([
            'event' => 'deployment_success',
            'deployment_uuid' => 'missing',
        ], 'wrong-secret')->assertUnauthorized();

        Log::shouldHaveReceived('warning')->withArgs(function (string $message): bool {
            $this->assertStringNotContainsString(self::SECRET, $message);
            $this->assertStringNotContainsString('wrong-secret', $message);

            return true;
        });
    }

    public function test_site_edit_shows_deployments_tab(): void
    {
        $deployment = $this->inProgressDeployment([
            'commit_sha' => 'abcdef123456',
        ]);
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        $this->actingAs($operator)
            ->get(route('ops.sites.edit', $deployment->site))
            ->assertOk()
            ->assertSee('Deployments', false)
            ->assertSee('in_progress', false)
            ->assertSee('abcdef1', false)
            ->assertSee('Open in Coolify', false);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signedPost(array $payload, string $secret = self::SECRET, string $header = 'X-Coolify-Signature'): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = CoolifyWebhookSignature::sign($secret, $body);

        return $this->call('POST', '/webhooks/coolify', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_'.strtoupper(str_replace('-', '_', $header)) => $signature,
        ], $body);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function inProgressDeployment(array $overrides = []): Deployment
    {
        $site = Site::factory()->create([
            'status' => SiteStatus::Provisioning,
            'channel' => Channel::Beta,
            'coolify_app_uuid' => 'coolify-app-1',
            'primary_domain' => 'shop.example.test',
        ]);

        return Deployment::factory()->create(array_merge([
            'site_id' => $site->id,
            'channel' => Channel::Beta,
            'trigger' => DeploymentTrigger::Create,
            'coolify_deployment_uuid' => 'dep-1',
            'status' => DeploymentStatus::InProgress,
            'started_at' => now()->subMinute(),
        ], $overrides));
    }
}
