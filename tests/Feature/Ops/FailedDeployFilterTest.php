<?php

namespace Tests\Feature\Ops;

use App\Enums\DeploymentStatus;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CoolifyConnection;
use App\Models\Deployment;
use App\Models\OpsBackgroundJob;
use App\Models\Site;
use App\Models\User;
use App\Services\Fleet\FleetDashboardKpis;
use App\Support\Lists\ListFragment;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The fleet failed-deploy card is a number the operator wants to open. It can
 * only become a link if the list behind it resolves the *same* set: the same
 * window, counted once per site. A drill-down that lands on a different set is
 * worse than no drill-down, so every test here compares the two.
 */
class FailedDeployFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_the_filter_lists_only_sites_that_failed_inside_the_window(): void
    {
        $broken = $this->siteWithFailedDeploy('Kırık Site', hoursAgo: 2);
        $healthy = Site::factory()->create(['name' => 'Sağlam Site']);
        $succeeded = Site::factory()->create(['name' => 'Biten Site']);
        Deployment::factory()->create([
            'site_id' => $succeeded->id,
            'status' => DeploymentStatus::Finished,
            'finished_at' => now()->subHour(),
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['deploy' => 'failed']))
            ->assertOk()
            ->assertSee($broken->name)
            ->assertDontSee($healthy->name)
            ->assertDontSee($succeeded->name);
    }

    public function test_a_failure_older_than_the_window_is_not_in_the_filtered_list(): void
    {
        $recent = $this->siteWithFailedDeploy('Yeni Hata', hoursAgo: 3);
        $stale = $this->siteWithFailedDeploy('Eski Hata', hoursAgo: 72);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['deploy' => 'failed']))
            ->assertOk()
            ->assertSee($recent->name)
            ->assertDontSee($stale->name);
    }

    public function test_the_filtered_row_count_equals_the_number_on_the_fleet_card(): void
    {
        // Two sites broken, one of them three times over, plus noise.
        $first = $this->siteWithFailedDeploy('Hata Bir', hoursAgo: 1);
        $this->failedDeploy($first, hoursAgo: 2);
        $this->failedDeploy($first, hoursAgo: 3);
        $this->siteWithFailedDeploy('Hata İki', hoursAgo: 4);
        $this->siteWithFailedDeploy('Geçmiş', hoursAgo: 96);
        Site::factory()->count(4)->create();

        $card = app(FleetDashboardKpis::class)->failedDeploySiteCount();

        $this->assertSame(2, $card);
        $this->assertSame(
            $card,
            Site::query()->matchingListFilters('', '', '', '', 'failed')->count(),
            'Retries must not multiply a site in the list the card links to.',
        );
    }

    public function test_the_window_is_measured_from_when_the_deploy_ended(): void
    {
        $site = Site::factory()->create(['name' => 'Geç Biten']);
        // Queued three days ago, failed an hour ago: still broken now.
        $this->failedDeploy($site, hoursAgo: 72)
            ->forceFill(['finished_at' => now()->subHour()])
            ->save();

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['deploy' => 'failed']))
            ->assertOk()
            ->assertSee($site->name);
    }

    public function test_the_fleet_card_and_the_overflow_line_link_to_the_filter(): void
    {
        config(['ops.fleet.attention_limit' => 1]);
        $this->siteWithFailedDeploy('Hata Bir', hoursAgo: 1);
        $this->siteWithFailedDeploy('Hata İki', hoursAgo: 2);

        $response = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.fleet'))
            ->assertOk()
            ->assertSee(__('fleet.attention.see_all'));

        $html = $response->getContent();
        $target = e(route('ops.sites', ['deploy' => 'failed']));

        $this->assertStringContainsString('<a class="kpi-link" href="'.$target.'"', $html);
        $this->assertStringContainsString(
            '<a href="'.$target.'">'.e(__('fleet.attention.more', ['count' => 1])).'</a>',
            $html,
            'The "+N site daha" line has to be the way into those N sites.',
        );
    }

    public function test_the_card_offers_no_drill_down_when_nothing_failed(): void
    {
        Site::factory()->count(3)->create();

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.fleet'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            route('ops.sites', ['deploy' => 'failed']),
            $html,
            'A quiet failed-deploy card must not invent a drill-down. Agent-secret links are a different card.',
        );
    }

    public function test_the_active_filter_shows_a_chip_that_names_its_window(): void
    {
        config(['ops.fleet.failed_deploy_window_hours' => 12]);
        $this->siteWithFailedDeploy('Kırık Site', hoursAgo: 1);

        $response = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['deploy' => 'failed', 'status' => 'error']))
            ->assertOk()
            ->assertSee(__('sites.filter_deploy'))
            ->assertSee(__('sites.deploy_states.failed', ['hours' => 12]));

        // Dropping the deploy chip keeps the status filter, and vice versa.
        $response->assertSee(e(route('ops.sites', ['status' => 'error'])), false);
        $response->assertSee(e(route('ops.sites', ['deploy' => 'failed'])), false);
    }

    public function test_an_unknown_deploy_filter_is_ignored_rather_than_emptying_the_list(): void
    {
        $site = Site::factory()->create(['name' => 'Normal Site']);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['deploy' => 'kaboom']))
            ->assertOk()
            ->assertSee($site->name)
            ->assertDontSee(__('sites.empty.filtered_title'));
    }

    public function test_the_async_region_keeps_the_filter_and_ships_it_to_the_bulk_form(): void
    {
        $broken = $this->siteWithFailedDeploy('Kırık Site', hoursAgo: 1);
        Site::factory()->create(['name' => 'Sağlam Site']);

        $this->actingAs($this->user(OpsRole::Operator))
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.sites', ['deploy' => 'failed']))
            ->assertOk()
            ->assertHeader('X-Ops-List-Region', '1')
            ->assertSee($broken->name)
            ->assertDontSee('Sağlam Site')
            ->assertSee('name="filter_deploy" value="failed"', false);
    }

    public function test_a_bulk_select_all_under_the_filter_resolves_only_the_failed_sites(): void
    {
        Queue::fake();

        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => 'plane-failed-filter-token',
        ]);

        $broken = $this->siteWithFailedDeploy('Kırık Site', hoursAgo: 1);
        $broken->forceFill([
            'status' => SiteStatus::Active,
            'coolify_connection_id' => $connection->id,
            'coolify_app_uuid' => 'app-broken',
        ])->save();

        $healthy = Site::factory()->create([
            'name' => 'Sağlam Site',
            'status' => SiteStatus::Active,
            'coolify_connection_id' => $connection->id,
            'coolify_app_uuid' => 'app-healthy',
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->postJson(route('ops.sites.bulk.sync'), [
                'all' => 1,
                'filter_deploy' => 'failed',
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

    private function siteWithFailedDeploy(string $name, int $hoursAgo): Site
    {
        $site = Site::factory()->create(['name' => $name]);
        $this->failedDeploy($site, $hoursAgo);

        return $site;
    }

    private function failedDeploy(Site $site, int $hoursAgo): Deployment
    {
        return Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::Failed,
            'created_at' => now()->subHours($hoursAgo),
            'started_at' => now()->subHours($hoursAgo),
            'finished_at' => now()->subHours($hoursAgo),
        ]);
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
