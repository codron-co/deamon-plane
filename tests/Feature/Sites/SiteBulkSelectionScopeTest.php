<?php

namespace Tests\Feature\Sites;

use App\Enums\Channel;
use App\Enums\OpsRole;
use App\Models\Site;
use App\Models\User;
use App\Support\Lists\ListFragment;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The header checkbox posts `all=1`, which every bulk endpoint reads as *every site
 * matching the current filters* — not the 25 rows on screen. The bulk bar therefore
 * has to publish that number and feed it to every confirm body, so nobody approves
 * a hard delete over an unnamed, uncounted set.
 */
class SiteBulkSelectionScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_the_bulk_bar_publishes_the_filtered_total_not_the_page_size(): void
    {
        Site::factory()->count(30)->create(['channel' => Channel::Main]);
        Site::factory()->count(4)->create(['channel' => Channel::Beta]);

        // Unfiltered: 34 matches across two pages of 25.
        $this->actingAs($this->operator())
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee('data-bulk-total="34"', false)
            ->assertSee(e(__('site_ops.bulk.summary_all', ['total' => 34])), false)
            ->assertSee(e(__('site_ops.bulk.select_all_matching', ['total' => 34])), false);

        // Filtered: the scope shrinks with the filter, not with the page.
        $this->actingAs($this->operator())
            ->get(route('ops.sites', ['channel' => 'beta']))
            ->assertOk()
            ->assertSee('data-bulk-total="4"', false)
            ->assertDontSee('data-bulk-total="34"', false);
    }

    public function test_every_bulk_confirm_carries_a_count_placeholder(): void
    {
        Site::factory()->create(['notes' => "[import] dockerfile_build_pack: leftover\n"]);

        $html = $this->actingAs($this->operator())
            ->get(route('ops.sites'))
            ->assertOk()
            ->getContent();

        $templates = [
            __('site_ops.bulk.confirm_branch', ['target' => '__TARGET__', 'count' => '__COUNT__']),
            __('site_ops.bulk.confirm_compose', ['count' => '__COUNT__']),
            __('site_ops.bulk.confirm_auto_on', ['count' => '__COUNT__']),
            __('site_ops.bulk.confirm_auto_off', ['count' => '__COUNT__']),
            __('site_ops.bulk.confirm_redeploy', ['count' => '__COUNT__']),
            __('site_ops.bulk.confirm_follow', ['count' => '__COUNT__']),
            __('site_ops.bulk.confirm_pin', ['count' => '__COUNT__']),
            __('sites.agent.bulk_confirm', ['count' => '__COUNT__']),
            __('sites.publish.bulk.confirm_publish', ['count' => '__COUNT__']),
            __('sites.publish.bulk.confirm_unpublish', ['count' => '__COUNT__']),
            __('sites.danger.hard_confirm_bulk', ['count' => '__COUNT__']),
        ];

        foreach ($templates as $template) {
            $this->assertStringContainsString(
                'data-confirm-template="'.e($template).'"',
                $html,
                'Every bulk confirm must be able to name the selection count.',
            );
        }
    }

    public function test_hard_delete_confirm_names_the_count_instead_of_only_selected_sites(): void
    {
        Site::factory()->count(3)->create();

        $turkish = trans('sites.danger.hard_confirm_bulk', ['count' => 3], 'tr');

        $this->assertStringContainsString('3', $turkish);
        $this->assertStringNotContainsString('Seçili siteler kalıcı', $turkish);

        // The rendered fallback names the widest scope the button can reach, so a broken
        // ops-ui.js over-warns instead of under-warning.
        $this->actingAs($this->operator())
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee('data-confirm="'.e(__('sites.danger.hard_confirm_bulk', ['count' => 3])).'"', false);
    }

    public function test_all_equals_one_with_a_filter_touches_exactly_the_filtered_set(): void
    {
        $target = Site::factory()->create(['name' => 'Alpha Purge', 'channel' => Channel::Alpha]);
        $spared = Site::factory()->create(['name' => 'Main Keeper', 'channel' => Channel::Main]);

        // The rendered summary is the promise; the POST has to keep it.
        $this->actingAs($this->admin())
            ->get(route('ops.sites', ['channel' => 'alpha']))
            ->assertOk()
            ->assertSee('data-bulk-total="1"', false);

        $this->actingAs($this->admin())
            ->from(route('ops.sites', ['channel' => 'alpha']))
            ->post(route('ops.sites.bulk.purge'), [
                'all' => '1',
                'filter_channel' => 'alpha',
                'confirmed' => '1',
            ])
            ->assertRedirect();

        $this->assertNull(Site::withTrashed()->find($target->id));
        $this->assertNotNull(Site::query()->find($spared->id));
    }

    public function test_the_async_region_reships_the_summary_so_no_stale_count_survives(): void
    {
        Site::factory()->count(6)->create(['channel' => Channel::Main]);
        Site::factory()->count(2)->create(['channel' => Channel::Beta]);

        $this->actingAs($this->operator())
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.sites', ['channel' => 'beta']))
            ->assertOk()
            ->assertHeader('X-Ops-List-Region', '1')
            // A fresh region means a fresh, unchecked form: ops-ui.js re-binds and recounts.
            ->assertSee('data-bulk-total="2"', false)
            ->assertSee('data-ops-bulk-summary-text', false);
    }

    private function operator(): User
    {
        return $this->userWith(OpsRole::Operator);
    }

    private function admin(): User
    {
        return $this->userWith(OpsRole::SuperAdmin);
    }

    private function userWith(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
