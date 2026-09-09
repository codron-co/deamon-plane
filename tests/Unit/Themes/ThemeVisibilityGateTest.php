<?php

namespace Tests\Unit\Themes;

use App\Enums\ThemeVisibility;
use App\Models\Site;
use App\Models\Theme;
use App\Services\Themes\ThemeVisibilityGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeVisibilityGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_catalog_allows_any_site(): void
    {
        $theme = Theme::factory()->publicCatalog()->create();
        $site = Site::factory()->create();

        $this->assertTrue((new ThemeVisibilityGate)->canAssign($theme, $site));
    }

    public function test_allowlist_requires_access_row(): void
    {
        $theme = Theme::factory()->create(['visibility' => ThemeVisibility::Allowlist]);
        $site = Site::factory()->create();
        $gate = new ThemeVisibilityGate;

        $this->assertFalse($gate->canAssign($theme, $site));

        $theme->allowedSites()->attach($site->id);

        $this->assertTrue($gate->canAssign($theme->fresh(), $site));
    }

    public function test_private_allows_explicit_operator_assign(): void
    {
        $theme = Theme::factory()->create(['visibility' => ThemeVisibility::Private]);
        $site = Site::factory()->create();

        $this->assertTrue((new ThemeVisibilityGate)->canAssign($theme, $site));
    }
}
