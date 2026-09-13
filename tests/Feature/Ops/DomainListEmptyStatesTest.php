<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use App\Support\Lists\ListFragment;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A filtered miss on /domains used the same dead hint as an empty registry.
 * The operator must be able to tell the two apart and get out of the filtered
 * one without guessing — the same two-cause pattern Sites already uses.
 */
class DomainListEmptyStatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_an_empty_registry_offers_create_and_coolify_import(): void
    {
        Site::factory()->create(['name' => 'Hazır Site']);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.domains'))
            ->assertOk()
            ->assertSee(__('domains.empty.title'))
            ->assertSee(__('domains.empty.hint'))
            ->assertSee(__('domains.new'))
            ->assertSee(route('ops.coolify.index'), false)
            ->assertSee(__('domains.empty.import'))
            ->assertDontSee(__('domains.empty.filtered_title'))
            ->assertDontSee('ops-filter-chips', false);
    }

    public function test_a_viewer_sees_why_there_is_no_create_action(): void
    {
        $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.domains'))
            ->assertOk()
            ->assertSee(__('domains.empty.title'))
            ->assertSee(__('ops.viewer_readonly'))
            ->assertDontSee('name="domain"', false)
            ->assertSee(route('ops.coolify.index'), false);
    }

    public function test_a_filtered_empty_result_names_the_filters_and_the_registry_size(): void
    {
        SiteDomain::factory()->count(3)->create();

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.domains', ['q' => 'nothing-here', 'unbound' => 1]))
            ->assertOk()
            ->assertSee(__('domains.empty.filtered_title'))
            ->assertSee(__('domains.empty.filtered_hint', ['total' => 3]))
            ->assertDontSee(__('domains.empty.title'))
            ->assertSee(__('domains.filter_search'))
            ->assertSee('nothing-here')
            ->assertSee(__('domains.filter_unbound'))
            ->assertSee(__('domains.coolify.unbound'));
    }

    public function test_each_filter_chip_drops_only_itself_and_clearing_all_stays_prominent(): void
    {
        SiteDomain::factory()->create();

        $response = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.domains', ['q' => 'nothing-here', 'unbound' => 1]))
            ->assertOk();

        $response->assertSee(e(route('ops.domains', ['unbound' => '1'])), false);
        $response->assertSee(e(route('ops.domains', ['q' => 'nothing-here'])), false);

        $html = $response->getContent();
        $clearAll = strpos($html, 'class="btn btn-primary" href="'.e(route('ops.domains')).'"');

        $this->assertNotFalse($clearAll, 'Clearing every filter must be the primary way out of an empty result.');
    }

    public function test_the_async_region_carries_the_same_empty_states(): void
    {
        SiteDomain::factory()->count(2)->create();

        $this->actingAs($this->user(OpsRole::Operator))
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.domains', ['q' => 'nothing-here']))
            ->assertOk()
            ->assertHeader('X-Ops-List-Region', '1')
            ->assertSee(__('domains.empty.filtered_title'))
            ->assertSee(__('domains.empty.filtered_hint', ['total' => 2]))
            ->assertSee('ops-filter-chips', false);
    }

    public function test_filtered_copy_reads_as_turkish_for_a_turkish_operator(): void
    {
        SiteDomain::factory()->create();

        $this->actingAs($this->user(OpsRole::Operator, 'tr'))
            ->get(route('ops.domains', ['q' => 'yok']))
            ->assertOk()
            ->assertSee(trans('domains.empty.filtered_title', [], 'tr'), false)
            ->assertSee(trans('ops.actions.clear_filters', [], 'tr'), false)
            ->assertDontSee(trans('domains.empty.filtered_title', [], 'en'), false);
    }

    private function user(OpsRole $role, ?string $locale = null): User
    {
        $user = User::factory()->create($locale === null ? [] : ['locale' => $locale]);
        $user->assignRole($role->value);

        return $user;
    }
}
