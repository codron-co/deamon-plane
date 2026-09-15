<?php

namespace Tests\Feature\Sites;

use App\Enums\OpsRole;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteMetricIconsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_mail_and_theme_metrics_use_different_icons(): void
    {
        $site = Site::factory()->create(['coolify_app_uuid' => null]);
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Viewer->value);

        $html = (string) $this->actingAs($user)->get(route('ops.sites.show', $site))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'site-metric-icon is-theme'));
        $this->assertMatchesRegularExpression('#site-metric-icon is-mail"[^>]*><span></span></div>\s*<div>\s*<span>'.preg_quote(__('sites.detail.mail'), '#').'</span>#', $html);
        $this->assertStringContainsString('.site-metric-icon.is-mail span', (string) file_get_contents(public_path('css/ops-ui.css')));
    }
}
