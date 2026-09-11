<?php

namespace Tests\Feature\Webhooks;

use App\Enums\Channel;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CoolifyConnection;
use App\Models\CoolifySetting;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use App\Support\CoolifyWebhookSignature;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_missing_signature_and_token_is_rejected(): void
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

    public function test_unsigned_post_with_correct_token_maps_success_event_to_finished(): void
    {
        $deployment = $this->inProgressDeployment();

        $this->unsignedPost([
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

    public function test_unsigned_post_with_secret_query_alias_is_accepted(): void
    {
        $deployment = $this->inProgressDeployment();

        $this->unsignedPost([
            'event' => 'deployment_success',
            'deployment_uuid' => $deployment->coolify_deployment_uuid,
            'application_uuid' => $deployment->site->coolify_app_uuid,
        ], self::SECRET, 'secret')->assertOk();

        $this->assertSame(DeploymentStatus::Finished, $deployment->fresh()->status);
    }

    public function test_wrong_query_token_is_rejected(): void
    {
        $deployment = $this->inProgressDeployment();

        $this->unsignedPost([
            'event' => 'deployment_success',
            'deployment_uuid' => $deployment->coolify_deployment_uuid,
            'application_uuid' => $deployment->site->coolify_app_uuid,
        ], 'wrong-token')->assertUnauthorized();

        $this->assertSame(DeploymentStatus::InProgress, $deployment->fresh()->status);
    }

    public function test_empty_secret_rejects_even_with_query_token(): void
    {
        CoolifySetting::query()->update(['webhook_secret' => null]);
        config(['ops.coolify.webhook_secret' => null]);

        $deployment = $this->inProgressDeployment();

        $this->unsignedPost([
            'event' => 'deployment_success',
            'deployment_uuid' => $deployment->coolify_deployment_uuid,
            'application_uuid' => $deployment->site->coolify_app_uuid,
        ], 'any-token')->assertUnauthorized();

        $this->unsignedPost([
            'event' => 'deployment_success',
            'deployment_uuid' => $deployment->coolify_deployment_uuid,
        ], '')->assertUnauthorized();

        $this->assertSame(DeploymentStatus::InProgress, $deployment->fresh()->status);
    }

    public function test_invalid_hmac_is_not_bypassed_by_query_token(): void
    {
        $deployment = $this->inProgressDeployment();
        $payload = [
            'event' => 'deployment_success',
            'deployment_uuid' => $deployment->coolify_deployment_uuid,
            'application_uuid' => $deployment->site->coolify_app_uuid,
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = CoolifyWebhookSignature::sign('wrong-secret', $body);

        $this->call('POST', '/webhooks/coolify?token='.urlencode(self::SECRET), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_COOLIFY_SIGNATURE' => $signature,
        ], $body)->assertUnauthorized();

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
            'message' => 'Validation failed.',
            'errors' => ['fqdn' => ['This field is not allowed.']],
            'logs' => 'compose failed on app',
        ])->assertOk();

        $deployment->refresh();
        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        $this->assertSame(SiteStatus::Error, $deployment->site->fresh()->status);
        $this->assertStringContainsString('Validation failed.', (string) $deployment->error_message);
        $this->assertStringContainsString('This field is not allowed.', (string) $deployment->error_message);
        $this->assertStringContainsString('compose failed on app', (string) $deployment->log_excerpt);
    }

    public function test_failed_webhook_fetches_deployment_logs_when_connection_has_token(): void
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.test',
            'api_token' => 'test-coolify-token',
        ]);

        $deployment = $this->inProgressDeployment();
        $deployment->site->forceFill(['coolify_connection_id' => $connection->id])->save();

        Http::fake([
            'https://coolify.test/api/v1/deployments/dep-1' => Http::response([
                'uuid' => 'dep-1',
                'status' => 'failed',
                'message' => 'Build failed.',
                'errors' => ['git_branch' => ['invalid']],
                'logs' => 'remote log tail',
            ], 200),
        ]);

        $this->signedPost([
            'event' => 'deployment_failed',
            'deployment_uuid' => $deployment->coolify_deployment_uuid,
            'application_uuid' => $deployment->site->coolify_app_uuid,
        ])->assertOk();

        $deployment->refresh();
        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        $this->assertStringContainsString('Build failed.', (string) $deployment->error_message);
        $this->assertStringContainsString('git_branch', (string) $deployment->error_message);
        $this->assertStringContainsString('remote log tail', (string) $deployment->log_excerpt);
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

    public function test_query_token_is_never_written_to_logs(): void
    {
        Log::spy();

        $this->unsignedPost([
            'event' => 'deployment_success',
            'deployment_uuid' => 'missing',
        ], 'visible-query-token-xyz')->assertUnauthorized();

        Log::shouldHaveReceived('warning')->withArgs(function (string $message): bool {
            $this->assertStringNotContainsString(self::SECRET, $message);
            $this->assertStringNotContainsString('visible-query-token-xyz', $message);

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
            ->get(route('ops.sites.show', $deployment->site))
            ->assertOk()
            ->assertSee('Deployments', false)
            ->assertSee('in progress', false)
            ->assertSee('abcdef1', false)
            ->assertSee('Open in Coolify', false)
            ->assertSee('data-href="'.route('ops.sites.deployments.show', [$deployment->site, $deployment]).'"', false);
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
     * @param  array<string, mixed>  $payload
     */
    private function unsignedPost(array $payload, string $token = self::SECRET, string $queryKey = 'token'): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $query = $token === ''
            ? '?'.$queryKey.'='
            : '?'.$queryKey.'='.rawurlencode($token);

        return $this->call('POST', '/webhooks/coolify'.$query, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public function test_cancelled_event_maps_to_cancelled_without_site_error_for_manual(): void
    {
        $site = Site::factory()->create([
            'status' => SiteStatus::Active,
            'channel' => Channel::Beta,
            'coolify_app_uuid' => 'coolify-app-manual',
            'primary_domain' => 'manual.example.test',
        ]);
        $deployment = Deployment::factory()->create([
            'site_id' => $site->id,
            'channel' => Channel::Beta,
            'trigger' => DeploymentTrigger::Manual,
            'coolify_deployment_uuid' => 'dep-cancel-wh',
            'status' => DeploymentStatus::InProgress,
            'started_at' => now()->subMinute(),
        ]);

        $this->unsignedPost([
            'event' => 'deployment_cancelled',
            'deployment_uuid' => $deployment->coolify_deployment_uuid,
            'application_uuid' => $site->coolify_app_uuid,
        ])->assertOk()->assertJson(['ok' => true, 'updated' => true]);

        $deployment->refresh();
        $this->assertSame(DeploymentStatus::Cancelled, $deployment->status);
        $this->assertNotNull($deployment->finished_at);
        $this->assertSame(SiteStatus::Active, $site->fresh()->status);
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
