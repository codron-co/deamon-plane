<?php

namespace Tests\Feature\Themes;

use App\Enums\OpsRole;
use App\Models\SiteThemeInstallation;
use App\Models\Theme;
use App\Models\User;
use App\Support\Lists\ListFragment;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Themes list in the workspace pattern: catalog tiles above, a visibility
 * segment in the toolbar, and per-theme install / behind counts in the table.
 */
class ThemeListSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_tiles_count_the_catalog_by_visibility_installs_and_themes_behind(): void
    {
        $nova = Theme::factory()->publicCatalog()->create(['theme_id' => 'nova', 'latest_sha' => str_repeat('a', 40)]);
        Theme::factory()->publicCatalog()->create(['theme_id' => 'lumen', 'latest_sha' => str_repeat('b', 40)]);
        Theme::factory()->allowlist()->create(['theme_id' => 'silo']);
        Theme::factory()->create(['theme_id' => 'secret']);

        // nova: one current, one behind, one never pinned (behind), one inactive (ignored).
        SiteThemeInstallation::factory()->active()->create(['theme_id' => $nova->id, 'pinned_sha' => str_repeat('a', 40)]);
        SiteThemeInstallation::factory()->active()->create(['theme_id' => $nova->id, 'pinned_sha' => str_repeat('c', 40)]);
        SiteThemeInstallation::factory()->active()->create(['theme_id' => $nova->id, 'pinned_sha' => null]);
        SiteThemeInstallation::factory()->create(['theme_id' => $nova->id, 'pinned_sha' => str_repeat('c', 40)]);

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.themes'))
            ->assertOk()
            ->assertSee('class="plane-metrics"', false)
            ->assertSee(__('themes.summary.total'))
            ->assertSee(__('themes.summary.installs'))
            ->assertSee(__('themes.summary.outdated'))
            ->assertSee(e(route('ops.themes', ['visibility' => 'allowlist'])), false)
            ->assertSee(e(route('ops.sites', ['theme' => 'outdated'])), false)
            ->assertSee('name="visibility"', false)
            ->assertSee('form="themes-list-filters"', false)
            ->getContent();

        $this->assertMatchesRegularExpression('#'.preg_quote(__('themes.summary.total'), '#').'</span>[\s\S]*?<strong>4</strong>#', $html);
        $this->assertMatchesRegularExpression('#data-themes-summary="installs"[\s\S]*?<strong>3</strong>#', $html);
        $this->assertMatchesRegularExpression('#data-themes-summary="outdated"[\s\S]*?<strong>1</strong>#', $html);
        // Segment counts per visibility: public 2, allowlist 1, private 1.
        $this->assertMatchesRegularExpression('#value="public_catalog"[^>]*>\s*<span>[^<]+</span>\s*<span class="plane-segment-count">2</span>#', $html);
    }

    public function test_rows_show_installed_sites_and_outdated_installs_with_the_latest_release(): void
    {
        $nova = Theme::factory()->publicCatalog()->create([
            'theme_id' => 'nova',
            'latest_sha' => 'abcdef1234567890abcdef1234567890abcdef12',
            'latest_tag' => null,
        ]);
        $tagged = Theme::factory()->create(['theme_id' => 'tagged', 'latest_tag' => 'v1.4.0', 'latest_sha' => str_repeat('d', 40)]);

        SiteThemeInstallation::factory()->active()->create(['theme_id' => $nova->id, 'pinned_sha' => $nova->latest_sha]);
        SiteThemeInstallation::factory()->active()->create(['theme_id' => $nova->id, 'pinned_sha' => str_repeat('0', 40)]);
        SiteThemeInstallation::factory()->create(['theme_id' => $nova->id]);

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.themes'))
            ->assertOk()
            ->assertSee(__('themes.columns.installed_sites'))
            ->assertSee(__('themes.columns.outdated'))
            ->assertSee('abcdef1', false)
            ->assertSee('v1.4.0', false)
            ->assertSee('class="plane-git-icon"', false)
            ->assertSee('data-href="'.route('ops.themes.show', $nova).'"', false)
            ->assertSee('data-href="'.route('ops.themes.show', $tagged).'"', false)
            ->assertSee(e(route('ops.sites', ['theme' => 'outdated', 'theme_id' => 'nova'])), false)
            ->getContent();

        $this->assertMatchesRegularExpression('#data-theme-installs>3</td>\s*<td class="ops-num-col" data-theme-outdated>\s*<a class="plane-count is-warning"[^>]*>1</a>#', $html);
        $this->assertMatchesRegularExpression('#data-theme-installs>0</td>\s*<td class="ops-num-col" data-theme-outdated>\s*<span class="muted">0</span>#', $html);
    }

    public function test_install_counts_do_not_grow_queries_with_the_number_of_themes(): void
    {
        $this->seedThemes(2);
        $this->countQueries(); // Warms the permission cache, which is loaded once per process.
        $small = $this->countQueries();

        $this->seedThemes(8);
        $large = $this->countQueries();

        $this->assertSame($small, $large, 'Install and outdated counts must come from withCount, not per row.');
    }

    public function test_the_async_region_leaves_the_tiles_alone(): void
    {
        Theme::factory()->publicCatalog()->create(['theme_id' => 'nova']);

        $this->actingAs($this->user(OpsRole::Operator))
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.themes', ['visibility' => 'public_catalog']))
            ->assertOk()
            ->assertHeader('X-Ops-List-Region', '1')
            ->assertSee('nova', false)
            ->assertDontSee('plane-metrics', false)
            ->assertDontSee('data-ops-list-toolbar', false);
    }

    private function seedThemes(int $count): void
    {
        Theme::factory()->count($count)->create(['latest_sha' => str_repeat('e', 40)])->each(function (Theme $theme): void {
            SiteThemeInstallation::factory()->active()->create(['theme_id' => $theme->id, 'pinned_sha' => str_repeat('f', 40)]);
        });
    }

    private function countQueries(): int
    {
        $user = $this->user(OpsRole::Operator);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->get(route('ops.themes'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
