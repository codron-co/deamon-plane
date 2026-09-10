<?php

namespace Tests\Feature\Themes;

use App\Enums\OpsRole;
use App\Enums\ThemeInstallationStatus;
use App\Enums\ThemeVisibility;
use App\Models\Site;
use App\Models\SiteThemeInstallation;
use App\Models\Theme;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeCatalogPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_index_is_a_scanable_catalog_that_opens_theme_show(): void
    {
        $theme = Theme::factory()->publicCatalog()->create([
            'name' => 'Nova Retail',
            'theme_id' => 'nova-retail',
            'repo_full_name' => 'deamon-themes/deamon-theme-nova-retail',
            'default_ref' => 'main',
            'last_synced_at' => now(),
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.themes'))
            ->assertOk()
            ->assertSee('Nova Retail', false)
            ->assertSee('nova-retail', false)
            ->assertSee('deamon-themes/deamon-theme-nova-retail', false)
            ->assertSee('main', false)
            ->assertSee('Public catalog', false)
            ->assertSee('Theme ID', false)
            ->assertSee('Repository', false)
            ->assertSee('Default ref', false)
            ->assertSee('Last sync', false)
            ->assertSee($theme->last_synced_at?->toDateTimeString(), false)
            ->assertSee('data-href="'.route('ops.themes.show', $theme).'"', false)
            ->assertSee('git-only', false)
            ->assertDontSee('type="file"', false)
            ->assertDontSee('name="zip"', false)
            ->assertDontSee('ZipArchive', false);
    }

    public function test_index_shows_never_synced_state_and_filtered_empty(): void
    {
        Theme::factory()->create([
            'theme_id' => 'stale-theme',
            'last_synced_at' => null,
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.themes'))
            ->assertOk()
            ->assertSee('Never synced', false);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.themes', ['q' => 'no-such-theme-zzzz']))
            ->assertOk()
            ->assertSee('No themes match these filters', false)
            ->assertDontSee('stale-theme', false);
    }

    public function test_show_uses_true_tabs_and_keeps_catalog_operations(): void
    {
        $theme = Theme::factory()->allowlist()->create([
            'name' => 'Corporate Light',
            'theme_id' => 'corporate-light',
            'repo_full_name' => 'deamon-themes/deamon-theme-corporate-light',
            'default_ref' => 'release',
            'latest_sha' => 'abc123def456',
            'minimum_deamon_version' => '1.2.5',
            'description' => 'Catalog theme for corporate storefronts',
        ]);
        $site = Site::factory()->create(['name' => 'Allowlisted Shop', 'slug' => 'allowlisted-shop']);
        $theme->allowedSites()->attach($site->id);

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.themes.show', $theme))
            ->assertOk()
            ->assertSee('Corporate Light', false)
            ->assertSee('corporate-light', false)
            ->assertSee('deamon-themes/deamon-theme-corporate-light', false)
            ->assertSee('release', false)
            ->assertSee('abc123def456', false)
            ->assertSee('1.2.5', false)
            ->assertSee('Catalog theme for corporate storefronts', false)
            ->assertSee('role="tablist"', false)
            ->assertSee('href="#overview"', false)
            ->assertSee('href="#sites"', false)
            ->assertSee('href="#version"', false)
            ->assertSee('href="#sync"', false)
            ->assertSee('href="#operations"', false)
            ->assertSee('Installed / linked', false)
            ->assertSee('Version / ref', false)
            ->assertSee('js/ops-ui.js', false)
            ->assertSee('Allowlisted Shop', false)
            ->assertSee('Save visibility', false)
            ->assertSee('Save default ref', false)
            ->assertSee('Grant access', false)
            ->assertSee('Sync catalog', false)
            ->assertSee('data-confirm="Remove Allowlisted Shop from this theme allowlist?"', false)
            ->assertSee('data-confirm-title="Revoke access"', false)
            ->assertDontSee('type="file"', false)
            ->assertDontSee('name="zip"', false)
            ->getContent();

        $this->assertStringContainsString('aria-controls="overview"', $html);
        $this->assertStringContainsString('data-ops-tabs', $html);
        $this->assertStringContainsString('data-ops-panel', $html);
    }

    public function test_show_surfaces_critical_install_failures(): void
    {
        $theme = Theme::factory()->publicCatalog()->create([
            'theme_id' => 'failing-theme',
        ]);
        $site = Site::factory()->create(['name' => 'Broken Shop']);
        SiteThemeInstallation::factory()->create([
            'site_id' => $site->id,
            'theme_id' => $theme->id,
            'status' => ThemeInstallationStatus::Error,
            'last_error' => 'The default system theme cannot be installed or updated.',
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.themes.show', $theme))
            ->assertOk()
            ->assertSee('Critical install failure', false)
            ->assertSee('Broken Shop', false)
            ->assertSee('The default system theme cannot be installed or updated.', false)
            ->assertSee('error', false);
    }

    public function test_viewer_can_open_catalog_pages_without_write_actions(): void
    {
        $theme = Theme::factory()->create([
            'name' => 'Read Only Theme',
            'theme_id' => 'read-only-theme',
        ]);

        $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.themes'))
            ->assertOk()
            ->assertSee('Read Only Theme', false)
            ->assertSee('data-href="'.route('ops.themes.show', $theme).'"', false)
            ->assertDontSee('Sync catalog', false);

        $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.themes.show', $theme))
            ->assertOk()
            ->assertSee('Read Only Theme', false)
            ->assertSee('Overview', false)
            ->assertDontSee('Save visibility', false)
            ->assertDontSee('Save default ref', false)
            ->assertDontSee('Grant access', false)
            ->assertDontSee('Sync catalog', false);
    }

    public function test_operator_can_update_visibility_and_default_ref_from_detail_forms(): void
    {
        $theme = Theme::factory()->create([
            'visibility' => ThemeVisibility::Private,
            'default_ref' => 'main',
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->from(route('ops.themes.show', $theme))
            ->put(route('ops.themes.update', $theme), [
                'visibility' => ThemeVisibility::PublicCatalog->value,
                'default_ref' => 'release',
            ])
            ->assertRedirect(route('ops.themes.show', $theme))
            ->assertSessionHas('status');

        $theme->refresh();
        $this->assertSame(ThemeVisibility::PublicCatalog, $theme->visibility);
        $this->assertSame('release', $theme->default_ref);
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
