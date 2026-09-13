<?php

namespace Tests\Feature\Sites;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\SiteAppHealthFixer;
use App\Support\Lists\ListFragment;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * The Sites toolbar re-requests this URL on every keystroke. The fleet-wide app-health
 * counts feed only the page header menu, which a region response never renders, so
 * typing must not drag the whole `sites` table through `neededFixes()` each time.
 */
class SiteListAppHealthCountCostTest extends TestCase
{
    use RefreshDatabase;

    private const CACHE_KEY = 'ops.sites.app_health_category_counts';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Cache::flush();
    }

    public function test_a_region_request_never_runs_the_fleet_scan(): void
    {
        Site::factory()->count(5)->create();
        $this->expectScans(0);

        $this->actingAs($this->operator())
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.sites', ['q' => 'a']))
            ->assertOk()
            ->assertHeader('X-Ops-List-Region', '1');
    }

    public function test_a_full_page_render_still_reports_the_counts(): void
    {
        $this->siteNeedingAFix();

        $this->actingAs($this->operator())
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee(__('sites.app_health.fix_all_sites'), false)
            ->assertSee(__('sites.app_health.fixes.migrate_compose'), false);

        $this->assertArrayHasKey(
            'migrate_compose',
            app(SiteAppHealthFixer::class)->cachedCategoryCounts()['counts'],
        );
    }

    public function test_repeated_page_renders_scan_once_within_the_cache_window(): void
    {
        $this->siteNeedingAFix();
        $this->expectScans(1);

        $user = $this->operator();
        $this->actingAs($user)->get(route('ops.sites'))->assertOk();
        $this->actingAs($user)->get(route('ops.sites'))->assertOk();
        $this->actingAs($user)->get(route('ops.sites', ['status' => 'error']))->assertOk();
    }

    public function test_the_page_says_how_old_the_counts_are(): void
    {
        $this->siteNeedingAFix();

        // Prime and render under one clock. diffForHumans() otherwise ticks
        // between the two ("0 seconds ago" on the page, "1 second ago" here).
        Carbon::setTestNow('2026-09-13 02:40:00');
        CarbonImmutable::setTestNow('2026-09-13 02:40:00');

        try {
            $cached = app(SiteAppHealthFixer::class)->cachedCategoryCounts();
            $this->assertNotNull($cached['computed_at']);

            $this->actingAs($this->operator())
                ->get(route('ops.sites'))
                ->assertOk()
                ->assertSee(__('sites.app_health.counts_age', ['ago' => $cached['computed_at']->diffForHumans()]), false);
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }

    public function test_applying_a_fix_invalidates_the_cached_counts(): void
    {
        $site = $this->siteNeedingAFix();

        $fixer = app(SiteAppHealthFixer::class);
        $this->assertNotSame([], $fixer->cachedCategoryCounts()['counts']);
        $this->assertTrue(Cache::has(self::CACHE_KEY));

        // The fix itself needs Coolify; only the invalidation contract matters here.
        try {
            $fixer->fix($site, 'migrate_compose');
        } catch (\Throwable) {
            // Failure still means the site may have changed.
        }

        $this->assertFalse(Cache::has(self::CACHE_KEY), 'A fix attempt must drop the cached counts.');
    }

    public function test_a_viewer_never_triggers_the_scan(): void
    {
        Site::factory()->count(3)->create();
        $this->expectScans(0);

        $this->actingAs($this->userWith(OpsRole::Viewer))
            ->get(route('ops.sites'))
            ->assertOk();
    }

    /**
     * Binds a partial mock that counts full-fleet scans while leaving the cached
     * wrapper — the thing under test — running for real.
     */
    private function expectScans(int $times): void
    {
        $real = app(SiteAppHealthFixer::class);

        $spy = Mockery::mock(SiteAppHealthFixer::class)->makePartial();
        $spy->shouldReceive('categoryCounts')
            ->times($times)
            ->andReturnUsing(fn (): array => $real->categoryCounts());

        $this->instance(SiteAppHealthFixer::class, $spy);
    }

    private function siteNeedingAFix(): Site
    {
        // A Dockerfile-pack leftover is resolved from stored notes, so no Coolify call.
        return Site::factory()->create([
            'name' => 'Legacy Pack',
            'status' => SiteStatus::Active,
            'notes' => "[import] dockerfile_build_pack: leftover\n",
        ]);
    }

    private function operator(): User
    {
        return $this->userWith(OpsRole::Operator);
    }

    private function userWith(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
