<?php

namespace Tests\Feature\Sites;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\SiteAppHealthFixer;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteRowFixMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_row_fix_menu_is_rendered_only_for_sites_with_a_fix(): void
    {
        Site::factory()->create(['name' => 'Draft One', 'slug' => 'draft-one', 'status' => SiteStatus::Draft]);
        Site::factory()->create(['name' => 'Active Two', 'slug' => 'active-two', 'status' => SiteStatus::Active]);
        Site::factory()->create(['name' => 'Error Three', 'slug' => 'error-three', 'status' => SiteStatus::Error]);

        $user = User::factory()->create();
        $user->assignRole(OpsRole::Operator->value);

        $html = $this->actingAs($user)->get(route('ops.sites'))->assertOk()->getContent();

        $expected = Site::query()->get()
            ->filter(fn (Site $site): bool => SiteAppHealthFixer::orderedUniqueFixes($site->appHealth()) !== [])
            ->count();

        $this->assertSame($expected, substr_count($html, 'data-row-fix-menu'));
        $this->assertStringNotContainsString(__('sites.app_health.no_issues').'</span>', $this->rowActions($html));
    }

    public function test_viewer_never_gets_a_row_fix_menu(): void
    {
        Site::factory()->create(['status' => SiteStatus::Error]);

        $viewer = User::factory()->create();
        $viewer->assignRole(OpsRole::Viewer->value);

        $this->actingAs($viewer)
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertDontSee('data-row-fix-menu', false);
    }

    private function rowActions(string $html): string
    {
        preg_match_all('#<td class="ops-row-actions">(.*?)</td>#s', $html, $cells);

        return implode("\n", $cells[1] ?? []);
    }
}
