<?php

namespace Tests\Feature\Ops;

use App\Console\Commands\WatchdogCommand;
use App\Enums\DeploymentStatus;
use App\Enums\SiteStatus;
use App\Enums\ThemeInstallationStatus;
use App\Jobs\PollDeploymentJob;
use App\Jobs\SwitchSiteChannelJob;
use App\Models\CoolifyConnection;
use App\Models\Deployment;
use App\Models\OpsBackgroundJob;
use App\Models\Site;
use App\Models\SiteThemeInstallation;
use App\Services\Coolify\CoolifyDeployGate;
use App\Services\Sites\ChannelSwitcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Audit P09: work left mid-way is closed or re-read, and a failure only ever
 * marks the row its own attempt created.
 */
class WatchdogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_a_failed_row_without_finished_at_no_longer_holds_the_deploy_gate(): void
    {
        $site = $this->site();
        $row = $this->deployment($site, DeploymentStatus::Failed, finished: false);

        // The gate itself already ignores it...
        app(CoolifyDeployGate::class)->assertCanStartDeploy($site);

        // ...and the watchdog closes it for good.
        $this->artisan('ops:watchdog')->assertSuccessful();

        $this->assertNotNull($row->fresh()->finished_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'watchdog.deployment_closed', 'subject_id' => $site->id]);
    }

    public function test_a_quiet_open_deployment_is_re_read_from_coolify_once_per_half_hour(): void
    {
        $site = $this->site();
        $row = $this->deployment($site, DeploymentStatus::InProgress, finished: false, uuid: 'coolify-dep-1');
        Deployment::query()->whereKey($row->id)->update(['updated_at' => now()->subMinutes(20)]);

        $this->artisan('ops:watchdog')->assertSuccessful();
        $this->artisan('ops:watchdog')->assertSuccessful();

        Queue::assertPushed(PollDeploymentJob::class, 1);
        Queue::assertPushed(PollDeploymentJob::class, fn (PollDeploymentJob $job): bool => $job->deploymentId === $row->id);
    }

    public function test_an_ancient_open_deployment_is_closed_as_failed(): void
    {
        $site = $this->site();
        $row = $this->deployment($site, DeploymentStatus::Queued, finished: false);
        Deployment::query()->whereKey($row->id)->update(['created_at' => now()->subHours(7)]);

        $this->artisan('ops:watchdog')->assertSuccessful();

        $fresh = $row->fresh();
        $this->assertSame(DeploymentStatus::Failed, $fresh->status);
        $this->assertNotNull($fresh->finished_at);
    }

    public function test_a_stalled_background_job_is_marked_failed(): void
    {
        $job = OpsBackgroundJob::query()->create(['type' => 'sites.bulk_deploy', 'title' => 'Bulk', 'status' => 'running']);
        OpsBackgroundJob::query()->whereKey($job->id)->update(['updated_at' => now()->subMinutes(30)]);
        $fresh = OpsBackgroundJob::query()->create(['type' => 'sites.bulk_deploy', 'title' => 'Bulk', 'status' => 'running']);

        $this->artisan('ops:watchdog')->assertSuccessful();

        $this->assertSame('failed', $job->fresh()->status);
        $this->assertSame('running', $fresh->fresh()->status);
    }

    public function test_a_site_stuck_deploying_with_nothing_open_is_set_to_error(): void
    {
        $stuck = $this->site(['status' => SiteStatus::Deploying]);
        $busy = $this->site(['status' => SiteStatus::Deploying]);
        $this->deployment($busy, DeploymentStatus::InProgress, finished: false, uuid: 'coolify-dep-busy');
        Site::query()->whereKey([$stuck->id, $busy->id])->update(['updated_at' => now()->subHours(3)]);

        $this->artisan('ops:watchdog')->assertSuccessful();

        $this->assertSame(SiteStatus::Error, $stuck->fresh()->status);
        $this->assertSame(SiteStatus::Deploying, $busy->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'watchdog.site_stuck', 'subject_id' => $stuck->id]);
    }

    public function test_a_theme_install_stuck_updating_is_marked_error(): void
    {
        $installation = SiteThemeInstallation::factory()->create(['status' => ThemeInstallationStatus::Updating]);
        SiteThemeInstallation::query()->whereKey($installation->id)->update(['updated_at' => now()->subHour()]);

        $this->artisan('ops:watchdog')->assertSuccessful();

        $this->assertSame(ThemeInstallationStatus::Error, $installation->fresh()->status);
    }

    public function test_one_run_repairs_at_most_the_budget(): void
    {
        $site = $this->site();
        foreach (range(1, WatchdogCommand::MAX_REPAIRS + 5) as $n) {
            $this->deployment($site, DeploymentStatus::Failed, finished: false);
        }

        $this->artisan('ops:watchdog')->assertSuccessful();

        $this->assertSame(5, Deployment::query()->whereNull('finished_at')->count());
    }

    public function test_a_failed_channel_switch_never_marks_an_older_deployment(): void
    {
        $site = $this->site(['status' => SiteStatus::Deploying]);
        $older = $this->deployment($site, DeploymentStatus::Finished, finished: true);

        $switcher = Mockery::mock(ChannelSwitcher::class);
        $switcher->shouldReceive('switchOnCoolify')->andThrow(new RuntimeException('PATCH failed'));
        $switcher->shouldReceive('safeFailureMessage')->andReturn('PATCH failed');
        $switcher->shouldReceive('markFailed')->once()->withArgs(
            fn (Site $failed, string $message, ?Deployment $row): bool => $row === null,
        );

        (new SwitchSiteChannelJob($site->id))->handle($switcher);

        $this->assertSame(DeploymentStatus::Finished, $older->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function site(array $overrides = []): Site
    {
        $connection = CoolifyConnection::factory()->create();

        return Site::factory()->create(array_merge([
            'status' => SiteStatus::Active,
            'coolify_connection_id' => $connection->id,
            'coolify_app_uuid' => 'app-'.bin2hex(random_bytes(4)),
        ], $overrides));
    }

    private function deployment(Site $site, DeploymentStatus $status, bool $finished, ?string $uuid = null): Deployment
    {
        return Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => $status,
            'coolify_deployment_uuid' => $uuid,
            'started_at' => now(),
            'finished_at' => $finished ? now() : null,
        ]);
    }
}
