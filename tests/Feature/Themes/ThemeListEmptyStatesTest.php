<?php

namespace Tests\Feature\Themes;

use App\Enums\OpsRole;
use App\Enums\ThemeVisibility;
use App\Models\Theme;
use App\Models\User;
use App\Support\Lists\ListFragment;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Catalog-empty and filter-empty are different problems. One needs a sync;
 * the other needs the chips cleared. The Sites pattern is reused here.
 */
class ThemeListEmptyStatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_an_empty_catalog_explains_itself_and_offers_sync(): void
    {
        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.themes'))
            ->assertOk()
            ->assertSee(__('themes.empty.title'))
            ->assertSee(__('themes.empty.hint'))
            ->assertDontSee('Http::fake', false)
            ->assertSee(route('ops.themes.sync'), false)
            ->assertDontSee(__('themes.empty.filtered_title'))
            ->assertDontSee('ops-filter-chips', false);
    }

    public function test_a_viewer_sees_why_there_is_no_sync_action(): void
    {
        $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.themes'))
            ->assertOk()
            ->assertSee(__('themes.empty.title'))
            ->assertSee(__('ops.viewer_readonly'))
            ->assertDontSee(route('ops.themes.sync'), false);
    }

    public function test_a_filtered_empty_result_names_the_filters_and_the_catalog_size(): void
    {
        Theme::factory()->count(3)->publicCatalog()->create();

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.themes', ['q' => 'nothing-here', 'visibility' => ThemeVisibility::Private->value]))
            ->assertOk()
            ->assertSee(__('themes.empty.filtered_title'))
            ->assertSee(__('themes.empty.filtered_hint', ['total' => 3]))
            ->assertDontSee(__('themes.empty.title'))
            ->assertSee(__('themes.filter_search'))
            ->assertSee('nothing-here')
            ->assertSee(__('themes.filter_visibility'))
            ->assertSee(ThemeVisibility::Private->label());
    }

    public function test_each_filter_chip_drops_only_itself_and_clearing_all_stays_prominent(): void
    {
        Theme::factory()->create();

        $response = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.themes', [
                'q' => 'nope',
                'visibility' => ThemeVisibility::Allowlist->value,
            ]))
            ->assertOk();

        $response->assertSee(e(route('ops.themes', ['visibility' => ThemeVisibility::Allowlist->value])), false);
        $response->assertSee(e(route('ops.themes', ['q' => 'nope'])), false);
        $response->assertSee(__('ops.actions.clear_filters'));
        $this->assertStringContainsString(
            'btn-primary',
            $response->getContent(),
        );
    }

    public function test_the_async_region_carries_the_same_empty_states(): void
    {
        Theme::factory()->create();

        $this->actingAs($this->user(OpsRole::Operator))
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.themes', ['q' => 'no-such-theme']))
            ->assertOk()
            ->assertHeader('X-Ops-List-Region', '1')
            ->assertSee(__('themes.empty.filtered_title'))
            ->assertSee('ops-filter-chips', false)
            ->assertDontSee('ops-sidebar', false);
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
