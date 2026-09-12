<?php

namespace Tests\Feature\Themes;

use App\Enums\DeploymentStatus;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\SiteThemeInstallation;
use App\Models\Theme;
use App\Models\User;
use App\Services\Agent\ControlPlaneAgentContract;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ThemeSyncAfterDeployTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_sync_defers_when_site_has_open_deployment(): void
    {
        $site = $this->readySite();
        $theme = Theme::factory()->publicCatalog()->create(['theme_id' => 'beyazoglu']);
        $installation = SiteThemeInstallation::factory()->active()->create([
            'site_id' => $site->id,
            'theme_id' => $theme->id,
            'pending_sync_after_deploy' => false,
        ]);

        Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::InProgress,
            'started_at' => now()->subMinute(),
            'finished_at' => null,
        ]);

        Http::fake();

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.sync', [$site, $installation]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Theme sync deferred until the open Coolify deploy finishes.');

        $installation->refresh();
        $this->assertTrue($installation->pending_sync_after_deploy);

        Http::assertNothingSent();
        Http::assertNotSent(fn (Request $request): bool => str_ends_with(
            $request->url(),
            ControlPlaneAgentContract::THEME_SYNC_PATH,
        ));
    }

    public function test_sync_runs_when_site_has_no_open_deployment(): void
    {
        $site = $this->readySite();
        $theme = Theme::factory()->publicCatalog()->create(['theme_id' => 'beyazoglu']);
        $installation = SiteThemeInstallation::factory()->active()->create([
            'site_id' => $site->id,
            'theme_id' => $theme->id,
            'pending_sync_after_deploy' => true,
        ]);

        Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::Finished,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(5),
        ]);

        Http::fake([
            'https://shop.example.test/internal/control/v1/themes/sync' => Http::response([
                'ok' => true,
                'queued' => true,
                'task_id' => 'task-1',
                'type' => 'theme_sync',
                'status' => 'queued',
                'active_theme_id' => 'beyazoglu',
            ], 200),
        ]);

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.sync', [$site, $installation]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Theme sync requested on the CMS instance.');

        $installation->refresh();
        $this->assertFalse($installation->pending_sync_after_deploy);

        Http::assertSent(fn (Request $request): bool => str_ends_with(
            $request->url(),
            ControlPlaneAgentContract::THEME_SYNC_PATH,
        ));
        $this->assertTrue($site->auditLogs()->where('action', 'theme.sync_succeeded')->exists());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function readySite(array $overrides = []): Site
    {
        return Site::factory()->withSecrets()->create(array_merge([
            'status' => SiteStatus::Active,
            'primary_domain' => 'shop.example.test',
            'agent_base_url' => 'https://shop.example.test',
        ], $overrides));
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }
}
