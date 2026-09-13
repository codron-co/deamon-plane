<?php

namespace Tests\Feature\Ops;

use App\Enums\DeploymentStatus;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use App\Support\Lists\ListFragment;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `/` is not a second Sites list. The toolbar filters the attention cards
 * already on the dashboard: search before the cap, kind to hide other cards,
 * and a filtered miss that is not "the fleet is clean".
 */
class FleetDashboardListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_a_clean_fleet_explains_itself_and_offers_sites(): void
    {
        Site::factory()->withSecrets()->create([
            'name' => 'Healthy Shop',
            'status' => SiteStatus::Active,
            'last_health_at' => now(),
            'last_health_payload' => ['ok' => true, 'status' => 'ok', 'http_status' => 200],
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.fleet'))
            ->assertOk()
            ->assertSee('data-ops-list-toolbar', false)
            ->assertSee('data-ops-list-clear hidden>', false)
            ->assertSee(__('fleet.empty_title'))
            ->assertSee(__('fleet.empty_action'))
            ->assertDontSee(__('fleet.empty_filtered_title'))
            ->assertDontSee('Healthy Shop');
    }

    public function test_search_and_kind_keep_only_matching_attention_rows(): void
    {
        $unhealthy = Site::factory()->create([
            'name' => 'Broken Shop',
            'primary_domain' => 'broken.example.test',
            'status' => SiteStatus::Error,
        ]);
        $failed = Deployment::factory()->create([
            'status' => DeploymentStatus::Failed,
            'error_message' => 'Compose pull failed.',
            'finished_at' => now()->subMinutes(5),
        ]);
        $legacy = Site::factory()->dockerfilePack()->create([
            'name' => 'Legacy Pack Site',
            'primary_domain' => 'legacy.example.test',
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.fleet', ['q' => 'Broken']))
            ->assertOk()
            ->assertHeader('X-Ops-List-Region', '1')
            ->assertSee('Broken Shop')
            ->assertDontSee($failed->site?->name)
            ->assertDontSee('Legacy Pack Site')
            ->assertDontSee('ops-sidebar', false)
            ->assertDontSee('data-ops-list-toolbar', false)
            ->assertDontSee(__('fleet.kpis.aria'));

        $this->actingAs($this->user(OpsRole::Operator))
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.fleet', ['kind' => 'dockerfile']))
            ->assertOk()
            ->assertSee('Legacy Pack Site')
            ->assertDontSee('Broken Shop')
            ->assertDontSee('Compose pull failed.');

        $this->assertNotNull($unhealthy->id);
        $this->assertNotNull($legacy->id);
    }

    public function test_search_finds_a_site_hidden_by_the_attention_cap(): void
    {
        config(['ops.fleet.attention_limit' => 3]);

        foreach (range(1, 5) as $index) {
            Deployment::factory()->create([
                'status' => DeploymentStatus::Failed,
                'error_message' => 'Boom '.$index,
                'finished_at' => now()->subMinutes($index),
                'site_id' => Site::factory()->withSecrets()->create([
                    'name' => 'Failing '.$index,
                    'primary_domain' => 'fail-'.$index.'.example.test',
                    'status' => SiteStatus::Active,
                    'last_health_at' => now(),
                    'last_health_payload' => ['ok' => true, 'status' => 'ok', 'http_status' => 200],
                ])->id,
            ]);
        }

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.fleet', ['kind' => 'failed']))
            ->assertOk()
            ->assertSee('Failing 5')
            ->assertDontSee('Failing 1');

        $this->actingAs($this->user(OpsRole::Operator))
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.fleet', ['q' => 'Failing 1', 'kind' => 'failed']))
            ->assertOk()
            ->assertSee('Failing 1')
            ->assertDontSee('Failing 5');
    }

    public function test_a_filtered_miss_names_the_attention_size_and_clears(): void
    {
        Site::factory()->withSecrets()->create([
            'name' => 'Error Shop',
            'status' => SiteStatus::Error,
            'last_health_at' => now(),
            'last_health_payload' => ['ok' => true, 'status' => 'ok', 'http_status' => 200],
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.fleet', ['q' => 'no-such-site']))
            ->assertOk()
            ->assertSee(__('fleet.empty_filtered_title'))
            ->assertSee(__('fleet.empty_filtered_hint', ['total' => 1]))
            ->assertSee(__('ops.actions.clear_filters'))
            ->assertSee('ops-filter-chips', false)
            ->assertDontSee(__('fleet.empty_title'));
    }

    public function test_an_unknown_kind_is_dropped_not_applied(): void
    {
        Site::factory()->create([
            'name' => 'Error Shop',
            'status' => SiteStatus::Error,
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.fleet', ['kind' => 'kaboom']))
            ->assertOk()
            ->assertSee('Error Shop')
            ->assertSee('data-ops-list-clear hidden>', false)
            ->assertDontSee(__('fleet.empty_filtered_title'));
    }

    public function test_percent_and_underscore_stay_literal(): void
    {
        Site::factory()->create([
            'name' => '100% Shop',
            'status' => SiteStatus::Error,
        ]);
        Site::factory()->create([
            'name' => '100x Shop',
            'status' => SiteStatus::Error,
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.fleet', ['q' => '100%']))
            ->assertOk()
            ->assertSee('100% Shop')
            ->assertDontSee('100x Shop');
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
