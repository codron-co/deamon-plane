<?php

namespace Tests\Feature\Ops;

use App\Enums\DeploymentStatus;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use App\Services\Fleet\FleetDashboardKpis;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The failed-deploy KPI used to be a lifetime total, so it only ever grew and still
 * counted rows from the throttle storm. It has to be a statement about now: a window,
 * one count per site, and a list that agrees with the number above it.
 */
class FleetFailedDeployWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_a_failure_older_than_the_window_is_not_counted(): void
    {
        $recent = $this->failedDeploy(hoursAgo: 2);
        $stale = $this->failedDeploy(hoursAgo: 72);

        $kpis = app(FleetDashboardKpis::class);

        $this->assertSame(1, $kpis->failedDeploySiteCount());
        $this->assertSame([$recent->id], $kpis->recentFailedDeploys()->pluck('id')->all());
        $this->assertNotContains($stale->id, $kpis->recentFailedDeploys()->pluck('id')->all());
    }

    public function test_the_window_is_measured_from_when_the_deploy_ended(): void
    {
        // Created three days ago, failed an hour ago: still a problem happening now.
        $long = $this->failedDeploy(hoursAgo: 72);
        $long->forceFill(['finished_at' => now()->subHour()])->save();

        $this->assertSame(1, app(FleetDashboardKpis::class)->failedDeploySiteCount());
    }

    public function test_retries_of_one_site_count_once_and_list_once(): void
    {
        $site = Site::factory()->create(['name' => 'Retry Loop']);
        $first = $this->failedDeploy(hoursAgo: 3, site: $site);
        $latest = $this->failedDeploy(hoursAgo: 1, site: $site);

        $kpis = app(FleetDashboardKpis::class);

        $this->assertSame(1, $kpis->failedDeploySiteCount(), 'Five retries of one site are one problem.');
        $rows = $kpis->recentFailedDeploys();
        $this->assertSame([$latest->id], $rows->pluck('id')->all());
        $this->assertNotContains($first->id, $rows->pluck('id')->all());
    }

    public function test_the_card_and_the_list_agree_and_the_overflow_is_named(): void
    {
        config(['ops.fleet.attention_limit' => 3]);

        foreach (range(1, 5) as $index) {
            $this->failedDeploy(hoursAgo: $index, site: Site::factory()->create(['name' => 'Failing '.$index]));
        }

        $html = $this->actingAs($this->operator())
            ->get(route('ops.fleet'))
            ->assertOk()
            ->assertSee(__('fleet.attention.more', ['count' => 2]), false)
            ->getContent();

        // The chip above the list reports the real total, not the capped row count.
        $this->assertSame(5, app(FleetDashboardKpis::class)->failedDeploySiteCount());
        $this->assertSame(3, substr_count($html, 'class="status-chip status-failed">'.__('ops.deploy_status.failed')));
        $this->assertStringContainsString('<span class="status-chip status-failed">5</span>', $html);
    }

    public function test_the_kpi_label_names_the_window(): void
    {
        config(['ops.fleet.failed_deploy_window_hours' => 12]);
        $this->failedDeploy(hoursAgo: 1);

        $this->actingAs($this->operator())
            ->get(route('ops.fleet'))
            ->assertOk()
            ->assertSee(__('fleet.kpis.failed', ['hours' => 12]), false);
    }

    public function test_the_dashboard_stays_bounded_on_a_larger_fleet(): void
    {
        Site::factory()->count(60)->create(['status' => SiteStatus::Error]);
        foreach (Site::query()->limit(30)->pluck('id') as $siteId) {
            Deployment::factory()->create([
                'site_id' => $siteId,
                'status' => DeploymentStatus::Failed,
                'finished_at' => now()->subMinutes(10),
            ]);
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $html = $this->actingAs($this->operator())
            ->get(route('ops.fleet'))
            ->assertOk()
            ->getContent();

        // No per-site query anywhere: the whole dashboard is a handful of statements.
        $this->assertLessThan(25, $queries, 'The fleet dashboard must not scale its query count with the fleet.');
        // Three capped lists of 8: 60 unhealthy, 30 failed-deploy, 60 missing a secret.
        $this->assertSame(24, substr_count($html, 'class="fleet-attention-name"'));
        $this->assertStringContainsString(__('fleet.attention.more', ['count' => 52]), $html);
        $this->assertStringContainsString(__('fleet.attention.more', ['count' => 22]), $html);
    }

    private function failedDeploy(int $hoursAgo, ?Site $site = null): Deployment
    {
        $site ??= Site::factory()->create();

        return Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::Failed,
            'created_at' => now()->subHours($hoursAgo),
            'started_at' => now()->subHours($hoursAgo),
            'finished_at' => now()->subHours($hoursAgo),
        ]);
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Operator->value);

        return $user;
    }
}
