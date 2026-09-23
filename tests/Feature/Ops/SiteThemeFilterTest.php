<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Models\Site;
use App\Models\SiteThemeInstallation;
use App\Models\Theme;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sites list: theme-source filter, Git theme cell and the fused branch/version pill.
 */
class SiteThemeFilterTest extends TestCase
{
    use RefreshDatabase;

    private Site $gitCurrent;

    private Site $gitBehind;

    private Site $reported;

    private Site $bare;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();

        $theme = Theme::factory()->create([
            'theme_id' => 'kuzey',
            'name' => 'Kuzey',
            'latest_sha' => str_repeat('a', 40),
            'latest_tag' => 'v1.4.0',
        ]);

        $this->gitCurrent = Site::factory()->create(['name' => 'Git Guncel']);
        SiteThemeInstallation::factory()->active()->create([
            'site_id' => $this->gitCurrent->id,
            'theme_id' => $theme->id,
            'pinned_sha' => str_repeat('a', 40),
        ]);

        $this->gitBehind = Site::factory()->create(['name' => 'Git Geride']);
        SiteThemeInstallation::factory()->active()->create([
            'site_id' => $this->gitBehind->id,
            'theme_id' => $theme->id,
            'ref' => 'main',
            'pinned_sha' => str_repeat('b', 40),
        ]);

        $this->reported = Site::factory()->create([
            'name' => 'Cms Temali',
            'last_health_payload' => ['active_theme_id' => 'yerel-tema', 'deamon_version' => '1.2.29'],
        ]);

        $this->bare = Site::factory()->create(['name' => 'Temasiz']);
    }

    public function test_the_git_filter_lists_only_plane_installed_themes(): void
    {
        $this->assertSame(
            [$this->gitBehind->id, $this->gitCurrent->id],
            Site::query()->matchingListFilters(theme: 'git')->orderBy('name')->pluck('id')->all(),
        );
    }

    public function test_the_outdated_filter_lists_pins_behind_the_catalog_head(): void
    {
        $this->assertSame(
            [$this->gitBehind->id],
            Site::query()->matchingListFilters(theme: 'outdated')->pluck('id')->all(),
        );
    }

    public function test_reported_and_none_split_the_sites_plane_did_not_theme(): void
    {
        $this->assertSame([$this->reported->id], Site::query()->matchingListFilters(theme: 'reported')->pluck('id')->all());
        $this->assertSame([$this->bare->id], Site::query()->matchingListFilters(theme: 'none')->pluck('id')->all());
    }

    public function test_an_unknown_theme_filter_is_ignored(): void
    {
        $this->assertSame(4, Site::query()->matchingListFilters(theme: 'zip')->count());
    }

    public function test_the_list_renders_the_git_theme_with_its_version(): void
    {
        $response = $this->actingAs($this->user())
            ->get(route('ops.sites', ['theme' => 'git']))
            ->assertOk()
            ->assertSee('plane-theme is-git', false)
            ->assertSee('v1.4.0')
            ->assertSee('main@bbbbbbb')
            ->assertSee('plane-theme-update', false)
            ->assertSee('name="filter_theme" value="git"', false)
            ->assertSee('name="theme" value="git" form="sites-list-filters" data-ops-list-filter checked', false);

        $response->assertDontSee('Cms Temali');
    }

    public function test_branch_and_version_share_one_pill(): void
    {
        $this->actingAs($this->user())
            ->get(route('ops.sites', ['theme' => 'reported']))
            ->assertOk()
            ->assertSee('class="plane-ref "', false)
            ->assertSee('1.2.29')
            ->assertSee('yerel-tema');
    }

    public function test_the_active_theme_filter_is_a_removable_chip(): void
    {
        $this->actingAs($this->user())
            ->get(route('ops.sites', ['theme' => 'outdated']))
            ->assertOk()
            ->assertSee('ops-filter-chips', false)
            ->assertSee(__('sites.theme_states.outdated'));
    }

    private function user(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Operator->value);

        return $user;
    }
}
