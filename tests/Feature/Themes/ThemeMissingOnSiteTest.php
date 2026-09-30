<?php

namespace Tests\Feature\Themes;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Enums\ThemeInstallationStatus;
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

/**
 * A site that lost its theme files (volume reset, fresh DB) answers theme_not_found.
 * Plane re-sends the theme instead of parking an error, and the operator can send
 * a theme again or remove it from the site.
 */
class ThemeMissingOnSiteTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://shop.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_activate_reinstalls_a_theme_the_site_lost_and_retries(): void
    {
        [$site, $installation] = $this->installation(isActive: false);
        $activateCalls = 0;

        Http::fake(function (Request $request) use (&$activateCalls) {
            $path = $this->path($request);
            if ($path === ControlPlaneAgentContract::THEME_ACTIVATE_PATH) {
                $activateCalls++;

                return $activateCalls === 1
                    ? Http::response(['ok' => false, 'error' => 'theme_not_found', 'message' => 'Theme is not installed on this instance.'], 404)
                    : Http::response(['ok' => true, 'active_theme_id' => 'wetsan'], 200);
            }
            if ($path === ControlPlaneAgentContract::THEME_INSTALL_PATH) {
                return Http::response(['ok' => true, 'theme_id' => 'wetsan', 'sha' => 'fresh-sha'], 200);
            }

            return Http::response(['ok' => true], 200);
        });

        $this->actingAs($this->operator())
            ->from(route('ops.sites.show', $site))
            ->post(route('ops.sites.themes.activate', [$site, $installation]), ['confirmed' => '1'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $installation->refresh();
        $this->assertSame(2, $activateCalls);
        $this->assertTrue($installation->is_active);
        $this->assertSame(ThemeInstallationStatus::Active, $installation->status);
        $this->assertNull($installation->last_error);
        $this->assertSame('fresh-sha', $installation->pinned_sha);
        $this->assertTrue($site->auditLogs()->where('action', 'theme.reinstalled_missing')->exists());
    }

    public function test_sync_reinstalls_a_missing_theme_before_syncing(): void
    {
        [$site, $installation] = $this->installation(isActive: true);
        $syncCalls = 0;

        Http::fake(function (Request $request) use (&$syncCalls) {
            $path = $this->path($request);
            if ($path === ControlPlaneAgentContract::THEME_SYNC_PATH) {
                $syncCalls++;

                return $syncCalls === 1
                    ? Http::response(['ok' => false, 'error' => 'theme_not_found'], 404)
                    : Http::response(['ok' => true, 'queued' => true, 'task_id' => 'task-9'], 200);
            }
            if ($path === ControlPlaneAgentContract::THEME_INSTALL_PATH) {
                return Http::response(['ok' => true, 'theme_id' => 'wetsan', 'sha' => 'sha-2'], 200);
            }

            return Http::response('', 200);
        });

        $this->actingAs($this->operator())
            ->from(route('ops.sites.show', $site))
            ->post(route('ops.sites.themes.sync', [$site, $installation]))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(2, $syncCalls);
        $this->assertSame('task-9', $installation->fresh()->last_sync_task_id);
    }

    public function test_a_failed_reinstall_surfaces_the_install_error_once(): void
    {
        [$site, $installation] = $this->installation(isActive: false);

        Http::fake(function (Request $request) {
            $path = $this->path($request);
            if ($path === ControlPlaneAgentContract::THEME_ACTIVATE_PATH) {
                return Http::response(['ok' => false, 'error' => 'theme_not_found'], 404);
            }
            if ($path === ControlPlaneAgentContract::THEME_INSTALL_PATH) {
                return Http::response(['ok' => false, 'error' => 'git_failed'], 502);
            }

            return Http::response('', 200);
        });

        $this->actingAs($this->operator())
            ->from(route('ops.sites.show', $site))
            ->post(route('ops.sites.themes.activate', [$site, $installation]), ['confirmed' => '1'])
            ->assertRedirect()
            ->assertSessionHas('error', __('agent.theme.git_failed'));

        Http::assertSentCount(2);
        $this->assertTrue($site->auditLogs()->where('action', 'theme.reinstall_missing_failed')->exists());
    }

    public function test_reinstall_sends_the_theme_again_and_keeps_it_active(): void
    {
        [$site, $installation] = $this->installation(isActive: true, status: ThemeInstallationStatus::Error);

        Http::fake(function (Request $request) {
            $path = $this->path($request);
            if ($path === ControlPlaneAgentContract::THEME_INSTALL_PATH) {
                return Http::response(['ok' => true, 'theme_id' => 'wetsan', 'sha' => 'sha-3'], 200);
            }
            if ($path === ControlPlaneAgentContract::THEME_ACTIVATE_PATH) {
                return Http::response(['ok' => true, 'active_theme_id' => 'wetsan'], 200);
            }

            return Http::response('', 200);
        });

        $this->actingAs($this->operator())
            ->from(route('ops.sites.show', $site))
            ->post(route('ops.sites.themes.reinstall', [$site, $installation]))
            ->assertRedirect()
            ->assertSessionHas('status', __('sites.theme_flash.reinstall_queued'));

        $installation->refresh();
        $this->assertSame(ThemeInstallationStatus::Active, $installation->status);
        $this->assertTrue($installation->is_active);
        $this->assertSame('sha-3', $installation->pinned_sha);
        Http::assertSent(fn (Request $r): bool => $this->path($r) === ControlPlaneAgentContract::THEME_INSTALL_PATH);
        Http::assertSent(fn (Request $r): bool => $this->path($r) === ControlPlaneAgentContract::THEME_ACTIVATE_PATH);
    }

    public function test_remove_deletes_the_theme_on_the_site_and_the_plane_row(): void
    {
        [$site, $installation] = $this->installation(isActive: false);
        Http::fake([self::HOST.ControlPlaneAgentContract::THEME_REMOVE_PATH => Http::response(['ok' => true, 'removed' => true, 'theme_id' => 'wetsan'], 200)]);

        $this->actingAs($this->operator())
            ->from(route('ops.sites.show', $site))
            ->delete(route('ops.sites.themes.remove', [$site, $installation]))
            ->assertRedirect()
            ->assertSessionHas('status', __('sites.theme_flash.removed_removed'));

        $this->assertModelMissing($installation);
        Http::assertSent(fn (Request $r): bool => $this->path($r) === ControlPlaneAgentContract::THEME_REMOVE_PATH && $r['theme_id'] === 'wetsan');
    }

    public function test_remove_of_a_theme_the_site_already_lost_still_drops_the_row(): void
    {
        [$site, $installation] = $this->installation(isActive: false);
        Http::fake([self::HOST.ControlPlaneAgentContract::THEME_REMOVE_PATH => Http::response(['ok' => false, 'error' => 'theme_not_found'], 404)]);

        $this->actingAs($this->operator())
            ->from(route('ops.sites.show', $site))
            ->delete(route('ops.sites.themes.remove', [$site, $installation]))
            ->assertSessionHas('status', __('sites.theme_flash.removed_already_gone'));

        $this->assertModelMissing($installation);
    }

    public function test_remove_on_an_old_cms_drops_only_the_plane_row_and_says_so(): void
    {
        [$site, $installation] = $this->installation(isActive: false);
        Http::fake([self::HOST.ControlPlaneAgentContract::THEME_REMOVE_PATH => Http::response('Not Found', 404)]);

        $this->actingAs($this->operator())
            ->from(route('ops.sites.show', $site))
            ->delete(route('ops.sites.themes.remove', [$site, $installation]))
            ->assertSessionHas('status', __('sites.theme_flash.removed_plane_only'));

        $this->assertModelMissing($installation);
    }

    public function test_remove_refuses_the_active_theme_without_calling_the_site(): void
    {
        [$site, $installation] = $this->installation(isActive: false, reportedActive: 'wetsan');
        Http::fake();

        $this->actingAs($this->operator())
            ->from(route('ops.sites.show', $site))
            ->delete(route('ops.sites.themes.remove', [$site, $installation]))
            ->assertSessionHas('error', __('agent.theme.active'));

        $this->assertModelExists($installation);
        Http::assertNothingSent();
    }

    public function test_remove_keeps_the_row_when_the_site_refuses(): void
    {
        [$site, $installation] = $this->installation(isActive: false);
        Http::fake([self::HOST.ControlPlaneAgentContract::THEME_REMOVE_PATH => Http::response(['ok' => false, 'error' => 'theme_active'], 409)]);

        $this->actingAs($this->operator())
            ->from(route('ops.sites.show', $site))
            ->delete(route('ops.sites.themes.remove', [$site, $installation]))
            ->assertSessionHas('error', __('agent.theme.active'));

        $this->assertModelExists($installation);
    }

    public function test_theme_rows_offer_send_again_and_remove(): void
    {
        [$site] = $this->installation(isActive: false);

        $this->actingAs($this->operator())
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee(__('sites.themes.reinstall'))
            ->assertSee(__('sites.themes.remove'));
    }

    /**
     * @return array{0: Site, 1: SiteThemeInstallation}
     */
    private function installation(bool $isActive, ThemeInstallationStatus $status = ThemeInstallationStatus::Error, ?string $reportedActive = 'default'): array
    {
        $site = Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'primary_domain' => 'shop.example.test',
            'agent_base_url' => self::HOST,
            'last_health_at' => now(),
            'last_health_payload' => ['ok' => true, 'status' => 'healthy', 'deamon_version' => '1.2.37', 'active_theme_id' => $reportedActive],
        ]);
        $theme = Theme::factory()->publicCatalog()->create([
            'theme_id' => 'wetsan',
            'repo_full_name' => 'deamon-themes/premium-wetsan',
            'latest_sha' => 'latest-sha',
        ]);
        $installation = SiteThemeInstallation::factory()->create([
            'site_id' => $site->id,
            'theme_id' => $theme->id,
            'is_active' => $isActive,
            'status' => $status,
            'last_error' => 'Theme was not found on the CMS instance.',
            'pinned_sha' => 'old-sha',
        ]);

        return [$site, $installation];
    }

    private function path(Request $request): string
    {
        return (string) parse_url($request->url(), PHP_URL_PATH);
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }
}
