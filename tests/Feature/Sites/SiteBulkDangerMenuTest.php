<?php

namespace Tests\Feature\Sites;

use App\Enums\OpsRole;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hard delete used to be the tenth button in a flat bulk row, immediately after
 * "Commite geç", with `all=1` making a mis-click fleet-wide. Destructive bulk actions
 * now require opening a separated menu first, and no action was lost on the way.
 */
class SiteBulkDangerMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_destructive_bulk_actions_are_only_reachable_through_the_menu(): void
    {
        Site::factory()->count(2)->create();

        $html = $this->actingAs($this->user(OpsRole::SuperAdmin))
            ->get(route('ops.sites'))
            ->assertOk()
            ->getContent();

        $menu = $this->dangerMenu($html);

        foreach ([route('ops.sites.bulk.purge'), route('ops.sites.bulk.publish-status'), 'value="draft"'] as $needle) {
            $this->assertStringContainsString($needle, $menu, 'A destructive bulk action must live inside the danger menu.');
        }

        // Nothing destructive is left loose in the row, and the old flat danger button is gone.
        $inlineRow = $this->beforeDangerMenu($html);
        $this->assertStringNotContainsString(route('ops.sites.bulk.purge'), $inlineRow);
        $this->assertStringNotContainsString('btn btn-danger btn-sm', $inlineRow);
    }

    public function test_the_menu_is_a_native_disclosure_so_the_keyboard_path_survives(): void
    {
        Site::factory()->create();

        $html = $this->actingAs($this->user(OpsRole::SuperAdmin))
            ->get(route('ops.sites'))
            ->assertOk()
            ->getContent();

        // <summary> is focusable and opens on Enter/Space without any JS, and the shared
        // primitive is what closes it on Escape and outside click.
        $this->assertStringContainsString('<details class="ops-action-menu sites-bulk-danger" data-ops-action-menu>', $html);
        $this->assertStringContainsString('<summary class="btn btn-ghost btn-sm is-danger">'.__('sites.bulk_danger.trigger'), $html);
        $this->assertStringContainsString('role="menu"', $this->dangerMenu($html));
        $this->assertSame(2, substr_count($this->dangerMenu($html), 'role="menuitem"'));
    }

    public function test_the_menu_actions_keep_the_counted_confirm(): void
    {
        Site::factory()->count(3)->create();

        $menu = $this->dangerMenu(
            $this->actingAs($this->user(OpsRole::SuperAdmin))
                ->get(route('ops.sites'))
                ->assertOk()
                ->getContent()
        );

        // P0-1's contract: the count is interpolated, and both items are flagged danger.
        $this->assertSame(2, substr_count($menu, 'data-confirm-danger="true"'));
        $this->assertSame(2, substr_count($menu, '__COUNT__'));
        $this->assertStringContainsString(e(__('sites.danger.hard_confirm_bulk', ['count' => 3])), $menu);
        $this->assertStringContainsString(e(__('sites.publish.bulk.confirm_unpublish', ['count' => 3])), $menu);
    }

    public function test_routine_bulk_actions_stay_inline(): void
    {
        Site::factory()->create();

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites'))
            ->assertOk()
            ->getContent();

        $inlineRow = $this->beforeDangerMenu($html);

        foreach ([
            route('ops.sites.bulk.deploy'),
            route('ops.sites.bulk.channel'),
            route('ops.sites.bulk.follow-head'),
            route('ops.sites.bulk.pin'),
            route('ops.sites.bulk.auto-deploy'),
            route('ops.sites.bulk.agent-secret'),
        ] as $routine) {
            $this->assertStringContainsString($routine, $inlineRow, 'Routine bulk actions must not need a menu.');
        }

        // Publishing is routine; only unpublishing moved.
        $this->assertStringContainsString('value="published"', $inlineRow);
        $this->assertStringNotContainsString('value="draft"', $inlineRow);
    }

    public function test_a_viewer_never_sees_the_danger_menu(): void
    {
        Site::factory()->create();

        $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertDontSee('sites-bulk-danger', false)
            ->assertDontSee(__('sites.bulk_danger.trigger'));
    }

    /** The markup between the danger disclosure and the end of its popover. */
    private function dangerMenu(string $html): string
    {
        $start = strpos($html, 'sites-bulk-danger');
        $this->assertNotFalse($start, 'The bulk bar must carry a separated destructive-actions menu.');

        $end = strpos($html, '</details>', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    /** The inline bulk action row: everything before the danger menu opens. */
    private function beforeDangerMenu(string $html): string
    {
        $start = strpos($html, 'sites-bulk-actions');
        $end = strpos($html, 'sites-bulk-danger');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
