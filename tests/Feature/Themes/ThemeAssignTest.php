<?php

namespace Tests\Feature\Themes;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Enums\ThemeInstallationStatus;
use App\Enums\ThemeVisibility;
use App\Models\Site;
use App\Models\SiteThemeInstallation;
use App\Models\Theme;
use App\Models\User;
use App\Services\Agent\ControlPlaneAgentContract;
use App\Support\ControlPlaneAgentSignature;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ThemeAssignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_assign_calls_agent_install_activate_sync_and_marks_active(): void
    {
        $site = $this->readySite();
        $theme = Theme::factory()->publicCatalog()->create([
            'theme_id' => 'beyazoglu',
            'repo_full_name' => 'deamon-themes/deamon-theme-beyazoglu',
            'latest_sha' => 'abc123',
        ]);
        $secret = (string) $site->agent_secret_encrypted;

        Http::fake([
            'https://shop.example.test/internal/control/v1/themes/install' => Http::response([
                'ok' => true,
                'theme_id' => 'beyazoglu',
                'version' => '1.0.0',
                'is_valid' => true,
                'active_theme_id' => 'default',
                'auto_update' => false,
                'ref' => 'main',
                'sha' => 'abc123',
            ], 200),
            'https://shop.example.test/internal/control/v1/themes/activate' => Http::response([
                'ok' => true,
                'active_theme_id' => 'beyazoglu',
                'auto_update' => false,
                'installed' => [
                    ['theme_id' => 'beyazoglu', 'version' => '1.0.0', 'is_valid' => true, 'is_active' => true, 'ref' => 'main', 'sha' => 'abc123'],
                ],
            ], 200),
            'https://shop.example.test/internal/control/v1/themes/sync' => Http::response([
                'ok' => true,
                'queued' => true,
                'auto_update' => false,
                'task_id' => 'task-1',
                'type' => 'theme_sync',
                'status' => 'queued',
                'active_theme_id' => 'beyazoglu',
            ], 200),
        ]);

        $this->actingAs($this->operator())
            ->from(route('ops.sites.show', $site))
            ->post(route('ops.sites.themes.assign', $site), [
                'theme_id' => $theme->theme_id,
                'ref' => 'main',
                'activate' => '1',
                'sync' => '1',
                'confirmed' => '1',
            ])
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('status');

        $installation = SiteThemeInstallation::query()->where('site_id', $site->id)->first();
        $this->assertNotNull($installation);
        $this->assertTrue($installation->is_active);
        $this->assertFalse($installation->auto_update);
        $this->assertSame(ThemeInstallationStatus::Active, $installation->status);
        $this->assertSame('abc123', $installation->pinned_sha);

        $this->assertTrue($site->auditLogs()->where('action', 'theme.install_succeeded')->exists());
        $this->assertTrue($site->auditLogs()->where('action', 'theme.activated')->exists());

        $seen = ['install' => false, 'activate' => false, 'sync' => false];

        Http::assertSent(function (Request $request) use ($secret, &$seen): bool {
            $this->assertSame('POST', $request->method());
            $this->assertTrue($this->signatureMatches($request, $secret));
            $this->assertStringNotContainsString("\n", $request->body());
            $this->assertSame(
                ControlPlaneAgentContract::encodeJson($request->data()),
                $request->body(),
            );

            if (str_ends_with($request->url(), ControlPlaneAgentContract::THEME_INSTALL_PATH)) {
                $seen['install'] = true;
                $this->assertSame('beyazoglu', $request['theme_id']);
                $this->assertSame('deamon-themes/deamon-theme-beyazoglu', $request['repo']);
                $this->assertSame(ControlPlaneAgentContract::THEME_SOURCE_GIT, $request['source']);
                $this->assertSame('abc123', $request['sha']);
            }

            if (str_ends_with($request->url(), ControlPlaneAgentContract::THEME_ACTIVATE_PATH)) {
                $seen['activate'] = true;
                $this->assertSame('beyazoglu', $request['theme_id']);
                $this->assertArrayNotHasKey('repo', $request->data());
            }

            if (str_ends_with($request->url(), ControlPlaneAgentContract::THEME_SYNC_PATH)) {
                $seen['sync'] = true;
                $this->assertSame(ControlPlaneAgentContract::SYNC_ACTION_ALL, $request['action']);
                $this->assertSame(ControlPlaneAgentContract::SYNC_MODE_MERGE, $request['mode']);
                $this->assertSame('beyazoglu', $request['theme_id']);
            }

            return true;
        });

        $this->assertTrue($seen['install']);
        $this->assertTrue($seen['activate']);
        $this->assertTrue($seen['sync']);
    }

    public function test_system_theme_default_cannot_be_assigned(): void
    {
        $site = $this->readySite();
        $theme = Theme::factory()->publicCatalog()->create([
            'theme_id' => ControlPlaneAgentContract::SYSTEM_THEME_ID,
            'repo_full_name' => 'deamon-themes/deamon-theme-default',
        ]);

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.assign', $site), [
                'theme_id' => $theme->theme_id,
                'activate' => '0',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, SiteThemeInstallation::query()->count());
        Http::assertNothingSent();
    }

    public function test_cms_system_theme_error_is_mapped_without_leaking_secrets(): void
    {
        $site = $this->readySite();
        $theme = Theme::factory()->publicCatalog()->create([
            'theme_id' => 'beyazoglu',
            'repo_full_name' => 'deamon-themes/premium-beyazoglu',
        ]);
        $secret = (string) $site->agent_secret_encrypted;

        Http::fake([
            'https://shop.example.test/internal/control/v1/themes/install' => Http::response([
                'ok' => false,
                'error' => 'system_theme',
                'message' => 'default cannot be mutated '.$secret,
            ], 422),
        ]);

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.assign', $site), [
                'theme_id' => $theme->theme_id,
                'activate' => '0',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $installation = SiteThemeInstallation::query()->where('site_id', $site->id)->first();
        $this->assertNotNull($installation);
        $this->assertSame(ThemeInstallationStatus::Error, $installation->status);
        $this->assertSame('The default system theme cannot be installed or updated.', $installation->last_error);
        $this->assertStringNotContainsString($secret, (string) $installation->last_error);
    }

    public function test_activate_without_confirm_is_rejected(): void
    {
        $site = $this->readySite();
        $theme = Theme::factory()->publicCatalog()->create();

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.assign', $site), [
                'theme_id' => $theme->theme_id,
                'activate' => '1',
                'confirmed' => '0',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, SiteThemeInstallation::query()->count());
        Http::assertNothingSent();
    }

    public function test_allowlist_blocks_sites_not_on_access_list(): void
    {
        $site = $this->readySite();
        $theme = Theme::factory()->allowlist()->create();

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.assign', $site), [
                'theme_id' => $theme->theme_id,
                'activate' => '0',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $theme->allowedSites()->attach($site->id);

        Http::fake([
            'https://shop.example.test/internal/control/v1/themes/install' => Http::response(['ok' => true], 200),
        ]);

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.assign', $site), [
                'theme_id' => $theme->theme_id,
                'activate' => '0',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(1, SiteThemeInstallation::query()->count());
    }

    public function test_private_theme_can_be_assigned_explicitly(): void
    {
        $site = $this->readySite();
        $theme = Theme::factory()->create(['visibility' => ThemeVisibility::Private]);

        Http::fake([
            'https://shop.example.test/internal/control/v1/themes/install' => Http::response(['ok' => true], 200),
        ]);

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.assign', $site), [
                'theme_id' => $theme->theme_id,
                'activate' => '0',
            ])
            ->assertSessionHas('status');
    }

    public function test_site_themes_tab_has_no_zip_upload(): void
    {
        $site = $this->readySite();
        Theme::factory()->publicCatalog()->create();

        $html = $this->actingAs($this->operator())
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee('Themes', false)
            ->assertSee('Assign theme', false)
            ->assertDontSee('type="file"', false)
            ->assertDontSee('name="zip"', false)
            ->getContent();

        $this->assertStringNotContainsString('ZipArchive', $html);
        $this->assertStringNotContainsString('accept=".zip"', $html);
    }

    public function test_themes_tab_shows_health_theme_and_confirms_mutations(): void
    {
        $site = $this->readySite([
            'last_health_payload' => [
                'ok' => true,
                'active_theme_id' => 'izyem',
            ],
        ]);
        $theme = Theme::factory()->publicCatalog()->create([
            'theme_id' => 'izyem',
            'name' => 'Izyem',
        ]);
        $installation = SiteThemeInstallation::factory()->create([
            'site_id' => $site->id,
            'theme_id' => $theme->id,
            'is_active' => false,
        ]);

        $html = $this->actingAs($this->operator())
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee('izyem', false)
            ->assertSee('Izyem', false)
            ->assertSee(__('sites.themes.via_health'), false)
            ->assertSee('data-confirm="'.__('sites.themes.update_confirm', ['theme' => 'izyem']).'"', false)
            ->assertSee('data-confirm="'.__('sites.themes.sync_confirm', ['theme' => 'izyem']).'"', false)
            ->assertSee('data-confirm="'.__('sites.themes.auto_update_confirm', ['theme' => 'izyem']).'"', false)
            ->assertDontSee(__('sites.themes.empty'), false)
            ->getContent();

        $this->assertStringContainsString((string) $installation->id, $html);
    }

    public function test_viewer_cannot_assign(): void
    {
        $site = $this->readySite();
        $theme = Theme::factory()->publicCatalog()->create();
        $viewer = User::factory()->create();
        $viewer->assignRole(OpsRole::Viewer->value);

        $this->actingAs($viewer)
            ->post(route('ops.sites.themes.assign', $site), [
                'theme_id' => $theme->theme_id,
            ])
            ->assertForbidden();
    }

    public function test_update_to_latest_skips_when_site_version_is_below_minimum(): void
    {
        $site = $this->readySite([
            'last_health_payload' => [
                'ok' => true,
                'deamon_version' => '1.0.0',
            ],
        ]);
        $theme = Theme::factory()->publicCatalog()->create([
            'minimum_deamon_version' => '1.2.0',
        ]);
        $installation = SiteThemeInstallation::factory()->active()->create([
            'site_id' => $site->id,
            'theme_id' => $theme->id,
        ]);

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.update', [$site, $installation]))
            ->assertRedirect();

        $this->assertTrue($site->auditLogs()->where('action', 'theme.update_skipped_version')->exists());
        Http::assertNothingSent();
    }

    private function signatureMatches(Request $request, string $secret): bool
    {
        $timestamp = (string) ($request->header(ControlPlaneAgentContract::HEADER_TIMESTAMP)[0] ?? '');
        $nonce = (string) ($request->header(ControlPlaneAgentContract::HEADER_NONCE)[0] ?? '');
        $signature = (string) ($request->header(ControlPlaneAgentContract::HEADER_SIGNATURE)[0] ?? '');

        return ControlPlaneAgentSignature::matches($secret, $timestamp, $nonce, $request->body(), $signature);
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
