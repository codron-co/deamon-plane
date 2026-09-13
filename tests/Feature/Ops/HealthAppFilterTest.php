<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Jobs\InspectSiteAppHealthJob;
use App\Models\CoolifyConnection;
use App\Models\OpsBackgroundJob;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentHealthReason;
use App\Services\Agent\AgentHealthStatus;
use App\Services\Agent\SiteHealthChecker;
use App\Services\Fleet\FleetDashboardKpis;
use App\Services\Sites\SiteAppHealthInspector;
use App\Support\Lists\ListFragment;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ADR-10: health=unhealthy and app=issues are SQL over a persisted verdict.
 * The fleet card and the list must resolve the same set, and the list path
 * must never walk the fleet in PHP the way P0-2 just stopped doing.
 */
class HealthAppFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_the_health_filter_lists_only_unhealthy_sites(): void
    {
        $broken = Site::factory()->create([
            'name' => 'Kırık Site',
            'status' => SiteStatus::Error,
        ]);
        $timeout = $this->timeoutSite('Zaman Aşımı');
        $healthy = Site::factory()->withSecrets()->create([
            'name' => 'Sağlam Site',
            'status' => SiteStatus::Active,
            'last_health_at' => now(),
            'last_health_payload' => [
                'ok' => true,
                'status' => AgentHealthStatus::Ok,
                'http_status' => 200,
            ],
        ]);
        $needsSecret = Site::factory()->create([
            'name' => 'Secret Bekleyen',
            'status' => SiteStatus::Active,
            'last_health_at' => now()->subHours(2),
            'last_health_payload' => [
                'ok' => false,
                'status' => AgentHealthStatus::NeedsSecret,
                'reason' => AgentHealthReason::NeedsSecret,
            ],
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['health' => 'unhealthy']))
            ->assertOk()
            ->assertSee($broken->name)
            ->assertSee($timeout->name)
            ->assertDontSee($healthy->name)
            ->assertDontSee($needsSecret->name);
    }

    public function test_a_site_that_goes_stale_matches_without_rewriting_the_column(): void
    {
        $site = Site::factory()->withSecrets()->create([
            'name' => 'Eskiyen Site',
            'status' => SiteStatus::Active,
            'last_health_at' => now(),
            'last_health_payload' => [
                'ok' => true,
                'status' => AgentHealthStatus::Ok,
                'http_status' => 200,
            ],
        ]);

        $this->assertFalse((bool) $site->fresh()->health_unhealthy);

        Site::query()->whereKey($site->id)->update([
            'last_health_at' => now()->subHours(2),
            'health_unhealthy' => false,
        ]);

        $this->assertSame(
            1,
            Site::query()->matchingListFilters('', '', '', '', '', '', '', 'unhealthy')->count(),
        );
    }

    public function test_the_filtered_row_count_equals_the_number_on_the_fleet_card(): void
    {
        Site::factory()->create(['status' => SiteStatus::Error, 'name' => 'Hata Bir']);
        $this->timeoutSite('Hata İki');
        Site::factory()->create(['status' => SiteStatus::Error, 'name' => 'Hata Üç']);
        Site::factory()->count(4)->create(['status' => SiteStatus::Active]);

        $card = app(FleetDashboardKpis::class)->unhealthySiteCount();

        $this->assertSame(3, $card);
        $this->assertSame(
            $card,
            Site::query()->matchingListFilters('', '', '', '', '', '', '', 'unhealthy')->count(),
            'The card and /sites?health=unhealthy must be the same set.',
        );
    }

    public function test_the_fleet_card_and_the_overflow_line_link_to_the_filter(): void
    {
        config(['ops.fleet.attention_limit' => 1]);
        Site::factory()->create(['status' => SiteStatus::Error, 'name' => 'Hata Bir']);
        Site::factory()->create(['status' => SiteStatus::Error, 'name' => 'Hata İki']);

        $response = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.fleet'))
            ->assertOk()
            ->assertSee(__('fleet.attention.see_all'));

        $html = $response->getContent();
        $target = e(route('ops.sites', ['health' => 'unhealthy']));

        $this->assertStringContainsString('<a class="kpi-link" href="'.$target.'"', $html);
        $this->assertStringContainsString(
            '<a href="'.$target.'">'.e(__('fleet.attention.more', ['count' => 1])).'</a>',
            $html,
            'The "+N site daha" line has to be the way into those N sites.',
        );
    }

    public function test_the_card_offers_no_drill_down_when_nothing_is_unhealthy(): void
    {
        Site::factory()->count(3)->create(['status' => SiteStatus::Active]);

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.fleet'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            route('ops.sites', ['health' => 'unhealthy']),
            $html,
            'A quiet unhealthy card must not invent a drill-down.',
        );
    }

    public function test_the_active_health_filter_shows_a_chip(): void
    {
        Site::factory()->create(['status' => SiteStatus::Error, 'name' => 'Kırık Site']);

        $response = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['health' => 'unhealthy', 'status' => 'draft']))
            ->assertOk()
            ->assertSee(__('sites.filter_health'))
            ->assertSee(__('sites.health_states.unhealthy'));

        // Chips live on the filtered-empty panel; this pair matches nothing.
        $response->assertSee(e(route('ops.sites', ['status' => 'draft'])), false);
        $response->assertSee(e(route('ops.sites', ['health' => 'unhealthy'])), false);
    }

    public function test_an_unknown_health_filter_is_ignored_rather_than_emptying_the_list(): void
    {
        $site = Site::factory()->create(['name' => 'Normal Site']);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['health' => 'kaboom']))
            ->assertOk()
            ->assertSee($site->name)
            ->assertDontSee(__('sites.empty.filtered_title'));
    }

    public function test_the_async_region_keeps_the_health_filter_and_ships_it_to_the_bulk_form(): void
    {
        $broken = Site::factory()->create([
            'name' => 'Kırık Site',
            'status' => SiteStatus::Error,
        ]);
        Site::factory()->create(['name' => 'Sağlam Site', 'status' => SiteStatus::Active]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.sites', ['health' => 'unhealthy']))
            ->assertOk()
            ->assertHeader('X-Ops-List-Region', '1')
            ->assertSee($broken->name)
            ->assertDontSee('Sağlam Site')
            ->assertSee('name="filter_health" value="unhealthy"', false);
    }

    public function test_a_bulk_select_all_under_the_health_filter_resolves_only_unhealthy_sites(): void
    {
        Queue::fake();

        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => 'plane-health-filter-token',
        ]);

        $broken = Site::factory()->create([
            'name' => 'Kırık Site',
            'status' => SiteStatus::Error,
            'coolify_connection_id' => $connection->id,
            'coolify_app_uuid' => 'app-broken',
        ]);
        $healthy = Site::factory()->create([
            'name' => 'Sağlam Site',
            'status' => SiteStatus::Active,
            'coolify_connection_id' => $connection->id,
            'coolify_app_uuid' => 'app-healthy',
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->postJson(route('ops.sites.bulk.sync'), [
                'all' => 1,
                'filter_health' => 'unhealthy',
            ])
            ->assertOk();

        $payload = OpsBackgroundJob::query()->latest('id')->firstOrFail()->payload;

        $this->assertSame(
            [$broken->id],
            $payload['site_ids'],
            'all=1 must stay inside the filter the operator was looking at.',
        );
        $this->assertNotContains($healthy->id, $payload['site_ids']);
    }

    public function test_a_health_check_writes_the_persisted_verdict(): void
    {
        $site = Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'agent_base_url' => 'https://shop.health-verdict.example.test',
        ]);

        Http::fake([
            'https://shop.health-verdict.example.test/internal/control/v1/health' => Http::response('nope', 500),
        ]);

        app(SiteHealthChecker::class)->check($site);

        $site->refresh();
        $this->assertTrue($site->health_unhealthy);
        $this->assertNotNull($site->health_verdict_at);
        $this->assertTrue($site->app_has_issues);
        $this->assertNotNull($site->app_health_verdict_at);
    }

    public function test_an_inspect_writes_the_app_issue_verdict(): void
    {
        $site = Site::factory()->dockerfilePack()->create([
            'name' => 'Dockerfile Kalan',
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => 'app-inspect-verdict',
        ]);

        app(SiteAppHealthInspector::class)->inspect($site, live: false);

        $site->refresh();
        $this->assertTrue($site->app_has_issues);
        $this->assertGreaterThan(0, $site->app_health_issue_count);
        $this->assertNotNull($site->app_health_verdict_at);
        $this->assertNotNull($site->last_app_health_payload);
    }

    public function test_the_inspect_job_stamps_the_app_verdict(): void
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => 'plane-inspect-job-token',
        ]);
        $site = Site::factory()->dockerfilePack()->create([
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => 'app-inspect-job',
            'coolify_connection_id' => $connection->id,
        ]);

        Http::fake([
            'https://coolify.example/*' => Http::response(['error' => 'gone'], 404),
        ]);

        (new InspectSiteAppHealthJob($site->id))->handle(app(SiteAppHealthInspector::class));

        $site->refresh();
        $this->assertTrue($site->app_has_issues);
        $this->assertNotNull($site->app_health_verdict_at);
        $this->assertNotNull($site->last_app_health_payload);
    }

    public function test_the_app_filter_lists_only_sites_with_needed_fixes(): void
    {
        $broken = Site::factory()->dockerfilePack()->create([
            'name' => 'Dockerfile Kalan',
            'status' => SiteStatus::Active,
        ]);
        $healthy = Site::factory()->create([
            'name' => 'Temiz Site',
            'status' => SiteStatus::Draft,
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['app' => 'issues']))
            ->assertOk()
            ->assertSee($broken->name)
            ->assertDontSee($healthy->name)
            ->assertSee(__('sites.filter_app'))
            ->assertSee(__('sites.app_states.issues'));
    }

    public function test_an_unknown_app_filter_is_ignored_rather_than_emptying_the_list(): void
    {
        $site = Site::factory()->create(['name' => 'Normal Site']);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['app' => 'kaboom']))
            ->assertOk()
            ->assertSee($site->name)
            ->assertDontSee(__('sites.empty.filtered_title'));
    }

    public function test_the_async_region_ships_the_app_filter(): void
    {
        Site::factory()->dockerfilePack()->create([
            'name' => 'Dockerfile Kalan',
            'status' => SiteStatus::Active,
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.sites', ['app' => 'issues']))
            ->assertOk()
            ->assertSee('Dockerfile Kalan')
            ->assertSee('name="filter_app" value="issues"', false);
    }

    public function test_filtered_copy_reads_as_turkish_for_a_turkish_operator(): void
    {
        Site::factory()->create(['status' => SiteStatus::Error, 'name' => 'Kırık Site']);
        Site::factory()->dockerfilePack()->create([
            'name' => 'Dockerfile Kalan',
            'status' => SiteStatus::Active,
        ]);

        $user = $this->user(OpsRole::Operator);
        $user->locale = 'tr';
        $user->save();

        $this->actingAs($user)
            ->get(route('ops.sites', ['health' => 'unhealthy']))
            ->assertOk()
            ->assertSee('Sağlıksız', false)
            ->assertSee('Tüm sağlık durumları', false)
            ->assertDontSee('All health states', false);

        $this->actingAs($user)
            ->get(route('ops.sites', ['app' => 'issues']))
            ->assertOk()
            ->assertSee('App hatası var', false)
            ->assertSee('Sorunlu siteleri gör', false)
            ->assertDontSee('Has App issues', false);
    }

    public function test_the_fix_app_menu_links_to_the_issues_filter(): void
    {
        Site::factory()->dockerfilePack()->create([
            'name' => 'Dockerfile Kalan',
            'status' => SiteStatus::Active,
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee(__('sites.app_health.see_issues'))
            ->assertSee(e(route('ops.sites', ['app' => 'issues'])), false);
    }

    public function test_a_bulk_select_all_under_the_app_filter_resolves_only_sites_with_issues(): void
    {
        Queue::fake();

        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => 'plane-app-filter-token',
        ]);

        $broken = Site::factory()->dockerfilePack()->create([
            'name' => 'Dockerfile Kalan',
            'status' => SiteStatus::Active,
            'coolify_connection_id' => $connection->id,
            'coolify_app_uuid' => 'app-issues',
        ]);
        $healthy = Site::factory()->withSecrets()->create([
            'name' => 'Temiz Site',
            'status' => SiteStatus::Active,
            'coolify_connection_id' => $connection->id,
            'coolify_app_uuid' => 'app-clean',
            'last_health_at' => now(),
            'last_health_payload' => [
                'ok' => true,
                'status' => AgentHealthStatus::Ok,
                'http_status' => 200,
            ],
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->postJson(route('ops.sites.bulk.sync'), [
                'all' => 1,
                'filter_app' => 'issues',
            ])
            ->assertOk();

        $payload = OpsBackgroundJob::query()->latest('id')->firstOrFail()->payload;

        $this->assertSame([$broken->id], $payload['site_ids']);
        $this->assertNotContains($healthy->id, $payload['site_ids']);
    }

    private function timeoutSite(string $name): Site
    {
        return Site::factory()->withSecrets()->create([
            'name' => $name,
            'status' => SiteStatus::Active,
            'last_health_at' => now(),
            'last_health_payload' => [
                'ok' => false,
                'status' => AgentHealthStatus::Unhealthy,
                'reason' => AgentHealthReason::Timeout,
            ],
        ]);
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
