<?php

namespace Tests\Feature\Ops;

use App\Enums\Channel;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\User;
use App\Support\Lists\ListFragment;
use App\Support\Lists\SiteSavedViews;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An empty sites table has two very different causes: nothing has been created
 * yet, or the current filters exclude everything. The operator must be able to
 * tell them apart and get out of the filtered one without guessing.
 */
class SiteListEmptyStatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_an_empty_fleet_explains_itself_and_offers_the_create_action(): void
    {
        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee(__('sites.empty.title'))
            ->assertSee(__('sites.empty.hint'))
            ->assertSee(route('ops.sites.create'), false)
            ->assertDontSee(__('sites.empty.filtered_title'))
            ->assertDontSee('ops-filter-chips', false);
    }

    public function test_a_viewer_sees_why_there_is_no_create_action(): void
    {
        $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee(__('sites.empty.title'))
            ->assertSee(__('ops.viewer_readonly'))
            ->assertDontSee(route('ops.sites.create'), false);
    }

    public function test_a_filtered_empty_result_names_the_filters_and_the_fleet_size(): void
    {
        Site::factory()->count(3)->create(['channel' => Channel::Main, 'status' => SiteStatus::Active]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['q' => 'nothing-here', 'channel' => 'alpha', 'status' => 'error']))
            ->assertOk()
            ->assertSee(__('sites.empty.filtered_title'))
            ->assertSee(__('sites.empty.filtered_hint', ['total' => 3]))
            ->assertDontSee(__('sites.empty.title'))
            // Each active filter is named with its value, not just counted.
            ->assertSee(__('sites.filter_search'))
            ->assertSee('nothing-here')
            ->assertSee(__('sites.filter_branch'))
            ->assertSee(__('ops.site_status.error'));
    }

    public function test_each_filter_chip_drops_only_itself_and_clearing_all_stays_prominent(): void
    {
        Site::factory()->create();

        $response = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites', ['channel' => 'alpha', 'status' => 'error']))
            ->assertOk();

        // Removing the branch filter keeps the status filter, and vice versa.
        $response->assertSee(e(route('ops.sites', ['status' => 'error'])), false);
        $response->assertSee(e(route('ops.sites', ['channel' => 'alpha'])), false);

        $html = $response->getContent();
        $clearAll = strpos($html, 'class="btn btn-primary" href="'.e(SiteSavedViews::clearUrl()).'"');

        $this->assertNotFalse($clearAll, 'Clearing every filter must be the primary way out of an empty result.');
    }

    public function test_the_async_region_carries_the_same_empty_states(): void
    {
        Site::factory()->count(2)->create(['channel' => Channel::Main]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.sites', ['channel' => 'alpha']))
            ->assertOk()
            ->assertHeader('X-Ops-List-Region', '1')
            ->assertSee(__('sites.empty.filtered_title'))
            ->assertSee(__('sites.empty.filtered_hint', ['total' => 2]))
            ->assertSee('ops-filter-chips', false);
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
