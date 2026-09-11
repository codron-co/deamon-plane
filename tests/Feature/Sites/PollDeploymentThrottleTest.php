<?php

namespace Tests\Feature\Sites;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\SiteStatus;
use App\Jobs\PollDeploymentJob;
use App\Models\CoolifyConnection;
use App\Models\Deployment;
use App\Models\Site;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Coolify\CoolifyDeploymentSync;
use App\Services\Sites\ChannelSwitcher;
use App\Services\Sites\SiteProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A throttled status read says nothing about the build. Recording it as a failure
 * is what turned healthy deploys into English "Too Many Attempts." rows.
 */
class PollDeploymentThrottleTest extends TestCase
{
    use RefreshDatabase;

    private const APP = 'poll-app-1';

    private const DEPLOY = 'poll-dep-1';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config()->set('ops.coolify.rate.min_interval_ms', 0);
        config()->set('ops.coolify.retry.max_attempts', 1);
    }

    public function test_a_throttled_poll_reschedules_instead_of_failing_the_deployment(): void
    {
        Queue::fake();
        $deployment = $this->deployment();

        Http::fake([
            'https://coolify.example/api/v1/deployments/'.self::DEPLOY => Http::response(
                ['message' => 'Too Many Attempts.'],
                429,
            ),
        ]);

        (new PollDeploymentJob($deployment->id))->handle(...$this->dependencies());

        $deployment->refresh();
        $this->assertSame(DeploymentStatus::InProgress, $deployment->status);
        $this->assertNull($deployment->error_message);
        $this->assertNull($deployment->finished_at);

        Queue::assertPushed(PollDeploymentJob::class, function (PollDeploymentJob $job): bool {
            return $job->transientAttempt === 1 && $job->pollAttempt === 1;
        });
    }

    public function test_a_gateway_error_also_reschedules(): void
    {
        Queue::fake();
        $deployment = $this->deployment();

        Http::fake([
            'https://coolify.example/api/v1/deployments/'.self::DEPLOY => Http::response(['message' => 'nope'], 503),
        ]);

        (new PollDeploymentJob($deployment->id))->handle(...$this->dependencies());

        $this->assertSame(DeploymentStatus::InProgress, $deployment->refresh()->status);
        Queue::assertPushed(PollDeploymentJob::class);
    }

    public function test_the_transient_budget_eventually_fails_with_turkish_copy(): void
    {
        Queue::fake();
        $this->app->setLocale('tr');
        $deployment = $this->deployment();
        config()->set('ops.provision.poll_transient_max_attempts', 2);

        Http::fake([
            'https://coolify.example/api/v1/deployments/'.self::DEPLOY => Http::response(
                ['message' => 'Too Many Attempts.'],
                429,
            ),
        ]);

        (new PollDeploymentJob($deployment->id, null, null, 1, 2))->handle(...$this->dependencies());

        $deployment->refresh();
        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        $this->assertNotNull($deployment->error_message);
        $this->assertStringNotContainsStringIgnoringCase('Too Many Attempts', (string) $deployment->error_message);
        $this->assertSame(__('coolify.errors.rate_limited_deploy'), $deployment->error_message);
        Queue::assertNotPushed(PollDeploymentJob::class);
    }

    public function test_a_real_coolify_error_still_fails_the_deployment(): void
    {
        Queue::fake();
        $deployment = $this->deployment();

        Http::fake([
            'https://coolify.example/api/v1/deployments/'.self::DEPLOY => Http::response(
                ['message' => 'Deployment not found.'],
                404,
            ),
        ]);

        (new PollDeploymentJob($deployment->id))->handle(...$this->dependencies());

        $this->assertSame(DeploymentStatus::Failed, $deployment->refresh()->status);
        Queue::assertNotPushed(PollDeploymentJob::class);
    }

    public function test_a_row_the_webhook_already_finished_stops_polling_without_an_api_call(): void
    {
        Queue::fake();
        $deployment = $this->deployment();
        $deployment->status = DeploymentStatus::Finished;
        $deployment->finished_at = now();
        $deployment->save();

        (new PollDeploymentJob($deployment->id))->handle(...$this->dependencies());

        Http::assertNothingSent();
        Queue::assertNotPushed(PollDeploymentJob::class);
    }

    public function test_repeat_polls_are_spaced_out_so_sibling_polls_do_not_stay_in_lockstep(): void
    {
        Queue::fake();
        $deployment = $this->deployment();

        Http::fake([
            'https://coolify.example/api/v1/deployments/'.self::DEPLOY => Http::response(
                ['uuid' => self::DEPLOY, 'status' => 'in_progress'],
                200,
            ),
        ]);

        $delays = [];
        for ($i = 0; $i < 12; $i++) {
            Queue::fake();
            (new PollDeploymentJob($deployment->id, null, null, 3))->handle(...$this->dependencies());
            Queue::assertPushed(PollDeploymentJob::class, function (PollDeploymentJob $job) use (&$delays): bool {
                $delays[] = $job->delay?->diffInSeconds(now()) ?? 0;

                return true;
            });
        }

        $this->assertGreaterThan(1, count(array_unique($delays)), 'Poll delay should be jittered.');
    }

    /**
     * @return array{0: SiteProvisioner, 1: CoolifyApplicationService, 2: ChannelSwitcher, 3: CoolifyDeploymentSync}
     */
    private function dependencies(): array
    {
        return [
            app(SiteProvisioner::class),
            app(CoolifyApplicationService::class),
            app(ChannelSwitcher::class),
            app(CoolifyDeploymentSync::class),
        ];
    }

    private function deployment(): Deployment
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => 'plane-coolify-ops-token',
        ]);

        $site = Site::factory()->create([
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => self::APP,
            'coolify_connection_id' => $connection->id,
        ]);

        return $site->deployments()->create([
            'channel' => $site->channel,
            'trigger' => DeploymentTrigger::Manual,
            'coolify_deployment_uuid' => self::DEPLOY,
            'status' => DeploymentStatus::InProgress,
            'started_at' => now(),
        ]);
    }
}
