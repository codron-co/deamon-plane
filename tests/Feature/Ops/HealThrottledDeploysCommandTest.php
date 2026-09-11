<?php

namespace Tests\Feature\Ops;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\SiteStatus;
use App\Models\CoolifyConnection;
use App\Models\Deployment;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HealThrottledDeploysCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config()->set('ops.coolify.rate.min_interval_ms', 0);
    }

    public function test_a_row_failed_by_a_throttle_is_restored_from_coolify_truth(): void
    {
        $deployment = $this->throttleFailedDeployment('Too Many Attempts.');
        $deployment->site->update(['status' => SiteStatus::Error]);

        Http::fake([
            'https://coolify.example/api/v1/deployments/dep-1' => Http::response([
                'uuid' => 'dep-1',
                'status' => 'finished',
            ], 200),
        ]);

        $this->artisan('ops:heal-throttled-deploys')->assertSuccessful();

        $deployment->refresh();
        $this->assertSame(DeploymentStatus::Finished, $deployment->status);
        $this->assertNull($deployment->error_message);
        $this->assertSame(SiteStatus::Active, $deployment->site->refresh()->status);
    }

    public function test_the_turkish_marker_is_also_matched(): void
    {
        $this->throttleFailedDeployment(__('coolify.errors.rate_limited_deploy', [], 'tr'));

        Http::fake([
            'https://coolify.example/api/v1/deployments/dep-1' => Http::response([
                'uuid' => 'dep-1',
                'status' => 'finished',
            ], 200),
        ]);

        $this->artisan('ops:heal-throttled-deploys')->assertSuccessful();

        $this->assertSame(DeploymentStatus::Finished, Deployment::query()->first()?->status);
    }

    public function test_a_generic_api_failure_that_coolify_calls_cancelled_is_corrected(): void
    {
        $deployment = $this->throttleFailedDeployment('Coolify API request failed.');

        Http::fake([
            'https://coolify.example/api/v1/deployments/dep-1' => Http::response([
                'uuid' => 'dep-1',
                'status' => 'cancelled-by-user',
            ], 200),
        ]);

        $this->artisan('ops:heal-throttled-deploys')->assertSuccessful();

        $deployment->refresh();
        $this->assertSame(DeploymentStatus::Cancelled, $deployment->status);
        $this->assertStringNotContainsString('Coolify API request failed', (string) $deployment->error_message);
    }

    public function test_a_real_failure_keeps_its_own_text_when_coolify_explains_nothing(): void
    {
        $deployment = $this->throttleFailedDeployment('Coolify API request failed.');

        Http::fake([
            'https://coolify.example/api/v1/deployments/dep-1' => Http::response([
                'uuid' => 'dep-1',
                'status' => 'failed',
            ], 200),
        ]);

        $this->artisan('ops:heal-throttled-deploys')->assertSuccessful();

        $deployment->refresh();
        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        $this->assertSame('Coolify API request failed.', $deployment->error_message);
        $this->assertNotNull($deployment->finished_at);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $deployment = $this->throttleFailedDeployment('Too Many Attempts.');

        $this->artisan('ops:heal-throttled-deploys', ['--dry-run' => true])->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(DeploymentStatus::Failed, $deployment->refresh()->status);
    }

    public function test_a_genuinely_failed_build_stays_failed(): void
    {
        $deployment = $this->throttleFailedDeployment('Too Many Attempts.');

        Http::fake([
            'https://coolify.example/api/v1/deployments/dep-1' => Http::response([
                'uuid' => 'dep-1',
                'status' => 'failed',
                'message' => 'mysql exited (1)',
            ], 200),
        ]);

        $this->artisan('ops:heal-throttled-deploys')->assertSuccessful();

        $deployment->refresh();
        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        $this->assertStringContainsString('mysql exited', (string) $deployment->error_message);
    }

    public function test_unrelated_failures_are_left_alone(): void
    {
        $deployment = $this->throttleFailedDeployment('Compose build failed.');

        $this->artisan('ops:heal-throttled-deploys')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame('Compose build failed.', $deployment->refresh()->error_message);
    }

    private function throttleFailedDeployment(string $message): Deployment
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => 'plane-coolify-ops-token',
        ]);

        $site = Site::factory()->create([
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => 'heal-app',
            'coolify_connection_id' => $connection->id,
        ]);

        return $site->deployments()->create([
            'channel' => $site->channel,
            'trigger' => DeploymentTrigger::Manual,
            'coolify_deployment_uuid' => 'dep-1',
            'status' => DeploymentStatus::Failed,
            'error_message' => $message,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(5),
        ]);
    }
}
