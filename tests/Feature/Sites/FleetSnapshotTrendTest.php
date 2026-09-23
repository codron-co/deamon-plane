<?php

namespace Tests\Feature\Sites;

use App\Enums\OpsRole;
use App\Models\FleetDailySnapshot;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\SiteListSummary;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetSnapshotTrendTest extends TestCase
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

    public function test_service_counts_match_the_list_filters_the_tiles_link_to(): void
    {
        Site::factory()->count(3)->create();
        $this->sitesWithAppIssues(2);

        $this->assertSame([
            'total' => Site::query()->count(),
            'unhealthy' => Site::query()->unhealthy()->count(),
            'failed_deploys' => Site::query()->matchingListFilters(deploy: 'failed')->count(),
            'app_issues' => Site::query()->withAppIssues()->count(),
            'git_themes' => Site::query()->matchingListFilters(theme: 'git')->count(),
        ], app(SiteListSummary::class)->counts());
        $this->assertSame(5, app(SiteListSummary::class)->counts()['total']);
        $this->assertSame(2, app(SiteListSummary::class)->counts()['app_issues']);
    }

    public function test_command_creates_then_upserts_todays_snapshot(): void
    {
        Site::factory()->count(2)->create();

        $this->artisan('ops:snapshot-fleet')->assertSuccessful();

        $this->assertSame(1, FleetDailySnapshot::query()->count());
        $row = FleetDailySnapshot::query()->firstOrFail();
        $this->assertSame('2026-09-23', substr((string) $row->snapshot_date, 0, 10));
        $this->assertSame(2, $row->total);

        $this->sitesWithAppIssues(1);
        $this->artisan('ops:snapshot-fleet')->assertSuccessful();

        $this->assertSame(1, FleetDailySnapshot::query()->count());
        $row->refresh();
        $this->assertSame(3, $row->total);
        $this->assertSame(1, $row->app_issues);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-24 23:50:00', config('app.timezone')));
        $this->artisan('ops:snapshot-fleet')->assertSuccessful();
        $this->assertSame(2, FleetDailySnapshot::query()->count());
    }

    public function test_command_is_scheduled_daily_late_evening(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'ops:snapshot-fleet'));

        $this->assertNotNull($event);
        $this->assertSame('50 23 * * *', $event->expression);
    }

    public function test_tiles_show_the_change_since_yesterday_with_tone_and_words(): void
    {
        $this->sitesWithAppIssues(2);
        $current = app(SiteListSummary::class)->counts();

        FleetDailySnapshot::query()->create([
            'snapshot_date' => '2026-09-22',
            'total' => $current['total'] + 3,
            'unhealthy' => $current['unhealthy'],
            'failed_deploys' => $current['failed_deploys'] + 1,
            'app_issues' => $current['app_issues'] - 2,
            'git_themes' => $current['git_themes'],
        ]);
        // Today's own row is never the baseline.
        FleetDailySnapshot::query()->create(['snapshot_date' => '2026-09-23'] + $current);

        $html = $this->sitesPage();

        $this->assertMatchesRegularExpression('#class="plane-metric-trend is-up is-bad" data-summary-trend="app_issues">\s*<span aria-hidden="true">▲ '.preg_quote(__('sites.summary.trend.up', ['count' => 2]), '#').'</span>\s*<span class="visually-hidden">'.preg_quote(__('sites.summary.trend.sr_up', ['count' => 2]), '#').'</span>#', $html);
        $this->assertMatchesRegularExpression('#class="plane-metric-trend is-down is-good" data-summary-trend="failed_deploys">\s*<span aria-hidden="true">▼ '.preg_quote(__('sites.summary.trend.down', ['count' => 1]), '#').'#', $html);
        $this->assertStringContainsString('class="plane-metric-trend is-down is-neutral" data-summary-trend="total"', $html);
        $this->assertStringContainsString('class="plane-metric-trend is-same is-neutral" data-summary-trend="unhealthy"', $html);
        $this->assertStringContainsString(e(__('sites.summary.trend.same')), $html);
    }

    public function test_an_older_baseline_names_its_date_instead_of_yesterday(): void
    {
        Site::factory()->count(2)->create();
        $current = app(SiteListSummary::class)->counts();

        FleetDailySnapshot::query()->create(['snapshot_date' => '2026-09-18', 'total' => $current['total'] - 1] + $current);

        $html = $this->sitesPage();

        $date = CarbonImmutable::parse('2026-09-18')->format(__('sites.summary.trend.date_format'));
        $this->assertStringContainsString(e(__('sites.summary.trend.up_since', ['count' => 1, 'date' => $date])), $html);
        $this->assertStringNotContainsString(e(__('sites.summary.trend.up', ['count' => 1])), $html);
    }

    public function test_no_earlier_snapshot_renders_no_trend(): void
    {
        Site::factory()->count(2)->create();
        FleetDailySnapshot::query()->create(['snapshot_date' => '2026-09-23'] + app(SiteListSummary::class)->counts());

        $html = $this->sitesPage();

        $this->assertStringContainsString('plane-metrics', $html);
        $this->assertStringNotContainsString('data-summary-trend', $html);
    }

    private function sitesWithAppIssues(int $count): void
    {
        Site::factory()->count($count)->create()
            ->each(fn (Site $site) => $site->forceFill(['app_has_issues' => true])->save());
    }

    private function sitesPage(): string
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Viewer->value);

        return (string) $this->actingAs($user)->get(route('ops.sites'))->assertOk()->getContent();
    }
}
