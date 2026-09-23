<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\FleetDailySnapshot;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\SiteListSummary;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sites keeps its workspace after the tiles, search and git icon moved into
 * shared x-ops components; Fleet reuses the tile look and the snapshot trend.
 */
class WorkspacePatternTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-23 14:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_sites_still_renders_tiles_search_segment_and_filter_panel(): void
    {
        Site::factory()->create(['name' => 'Workspace Shop']);

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee('Workspace Shop')
            ->assertSee('class="plane-metrics"', false)
            ->assertSee('data-ops-list-view="summary-total"', false)
            ->assertSee('data-ops-list-view="summary-git_themes"', false)
            ->assertSee(e(route('ops.sites', ['health' => 'unhealthy'])), false)
            ->assertSee('class="ops-search plane-search"', false)
            ->assertSee('placeholder="'.e(__('sites.search_placeholder')).'"', false)
            ->assertSee('data-sites-segment', false)
            ->assertSee('data-sites-filter-panel', false)
            ->assertSee('class="plane-git-icon"', false)
            ->getContent();

        $this->assertSame(5, substr_count($html, 'class="plane-metric is-'));
    }

    public function test_fleet_snapshot_uses_tiles_with_the_since_yesterday_trend(): void
    {
        Site::factory()->create(['status' => SiteStatus::Error]);
        $current = app(SiteListSummary::class)->counts();
        FleetDailySnapshot::query()->create([
            'snapshot_date' => '2026-09-22',
            'total' => $current['total'],
            'unhealthy' => $current['unhealthy'] - 1,
            'failed_deploys' => $current['failed_deploys'],
            'app_issues' => $current['app_issues'],
            'git_themes' => $current['git_themes'],
        ]);

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.fleet'))
            ->assertOk()
            ->assertSee('plane-metrics fleet-metrics', false)
            ->assertSee('class="plane-workspace"', false)
            ->assertSee('name="kind"', false)
            ->assertSee('form="fleet-list-filters"', false)
            ->assertSee(e(route('ops.sites', ['channel' => 'main'])), false)
            ->getContent();

        $this->assertSame(6, preg_match_all('/class="kpi-card plane-metric/', $html));
        $this->assertStringContainsString('class="plane-metric-trend is-up is-bad" data-summary-trend="unhealthy"', $html);
        $this->assertStringContainsString('class="plane-metric-trend is-same is-neutral" data-summary-trend="total"', $html);
        // Tiles lead, attention follows.
        $this->assertLessThan(strpos($html, 'data-ops-list-region'), strpos($html, 'fleet-metrics'));
    }

    public function test_fleet_without_a_snapshot_shows_no_trend(): void
    {
        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.fleet'))
            ->assertOk()
            ->assertSee('fleet-metrics', false)
            ->assertDontSee('data-summary-trend', false);
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
