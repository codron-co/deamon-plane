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

    public function test_sync_recovers_from_a_missing_cms_data_package(): void
    {
        $site = $this->readySite();
        $theme = Theme::factory()->publicCatalog()->create([
            'theme_id' => 'izyem',
            'repo_full_name' => 'deamon-themes/premium-izyem',
        ]);

        Http::fake([
            'https://shop.example.test/internal/control/v1/themes/install' => Http::response([
                'ok' => true,
                'theme_id' => 'izyem',
                'ref' => 'main',
            ], 200),
            'https://shop.example.test/internal/control/v1/themes/data-install' => Http::response([
                'ok' => true,
                'theme_id' => 'izyem',
            ], 200),
            'https://shop.example.test/internal/control/v1/themes/sync' => Http::sequence()
                ->push([
                    'ok' => false,
                    'error' => 'data_package_missing',
                    'message' => 'Bu tema için sync.json tanımlı değil.',
                ], 422)
                ->push([
                    'ok' => true,
                    'queued' => true,
                    'task_id' => 'task-1',
                    'active_theme_id' => 'izyem',
                ], 200),
        ]);

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.assign', $site), [
                'theme_id' => $theme->theme_id,
                'ref' => 'main',
                'activate' => '0',
                'sync' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $installation = SiteThemeInstallation::query()->where('site_id', $site->id)->firstOrFail();
        $this->assertSame(ThemeInstallationStatus::Active, $installation->status);
        $this->assertNull($installation->last_error);

        $this->assertTrue($site->auditLogs()->where('action', 'theme.data_installed')->exists());

        $repaired = false;
        Http::assertSent(function (Request $request) use (&$repaired): bool {
            if (str_ends_with($request->url(), ControlPlaneAgentContract::THEME_DATA_INSTALL_PATH)) {
                $repaired = true;
                $this->assertSame('izyem', $request['theme_id']);
            }

            return true;
        });
        $this->assertTrue($repaired, 'Plane must repair the data package before failing the sync.');
    }

    public function test_sync_now_repairs_the_data_package_and_clears_a_stale_error(): void
    {
        $site = $this->readySite();
        $theme = Theme::factory()->publicCatalog()->create(['theme_id' => 'izyem']);
        $installation = SiteThemeInstallation::factory()->active()->create([
            'site_id' => $site->id,
            'theme_id' => $theme->id,
            'status' => ThemeInstallationStatus::Error,
            'last_error' => 'Bu tema için sync.json tanımlı değil.',
        ]);

        Http::fake([
            'https://shop.example.test/internal/control/v1/themes/data-install' => Http::response([
                'ok' => true,
                'theme_id' => 'izyem',
            ], 200),
            'https://shop.example.test/internal/control/v1/themes/sync' => Http::sequence()
                ->push([
                    'ok' => false,
                    'error' => 'data_package_missing',
                    'message' => 'Bu tema için sync.json tanımlı değil.',
                ], 422)
                ->push([
                    'ok' => true,
                    'queued' => true,
                    'task_id' => 'task-1',
                    'active_theme_id' => 'izyem',
                ], 200),
        ]);

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.sync', [$site, $installation]))
            ->assertRedirect()
            ->assertSessionHas('status');

        $installation->refresh();
        $this->assertSame(ThemeInstallationStatus::Active, $installation->status);
        $this->assertNull($installation->last_error);

        $this->assertTrue($site->auditLogs()->where('action', 'theme.data_installed')->exists());
        $this->assertTrue($site->auditLogs()->where('action', 'theme.sync_succeeded')->exists());

        $paths = [];
        Http::assertSent(function (Request $request) use (&$paths): bool {
            $paths[] = parse_url($request->url(), PHP_URL_PATH);

            return true;
        });
        $this->assertSame([
            ControlPlaneAgentContract::THEME_SYNC_PATH,
            ControlPlaneAgentContract::THEME_DATA_INSTALL_PATH,
            ControlPlaneAgentContract::THEME_SYNC_PATH,
        ], $paths);
    }

    public function test_sync_now_sends_merge_by_default(): void
    {
        [$site, $installation] = $this->activeInstallation();

        Http::fake([
            'https://shop.example.test/internal/control/v1/themes/sync' => Http::response(['ok' => true, 'queued' => true], 200),
        ]);

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.sync', [$site, $installation]))
            ->assertRedirect()
            ->assertSessionHas('status', __('sites.theme_flash.sync_requested'));

        Http::assertSent(fn (Request $request): bool => ($request->data()['mode'] ?? null) === ControlPlaneAgentContract::SYNC_MODE_MERGE);
    }

    public function test_overwrite_sync_is_refused_without_confirmation(): void
    {
        [$site, $installation] = $this->activeInstallation();

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.sync', [$site, $installation]), ['mode' => 'overwrite', 'confirmed' => '0'])
            ->assertRedirect()
            ->assertSessionHas('error', __('sites.theme_flash.sync_overwrite_needs_confirm'));

        Http::assertNothingSent();
    }

    public function test_confirmed_overwrite_sync_sends_overwrite_mode_and_audits_it(): void
    {
        [$site, $installation] = $this->activeInstallation(cmsVersion: '1.2.21');

        Http::fake([
            'https://shop.example.test/internal/control/v1/themes/sync' => Http::response(['ok' => true, 'queued' => true], 200),
        ]);

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.sync', [$site, $installation]), ['mode' => 'overwrite', 'confirmed' => '1'])
            ->assertRedirect()
            ->assertSessionHas('status', __('sites.theme_flash.sync_overwrite_requested'));

        Http::assertSent(fn (Request $request): bool => ($request->data()['mode'] ?? null) === ControlPlaneAgentContract::SYNC_MODE_OVERWRITE);
        $this->assertSame('overwrite', $site->auditLogs()->where('action', 'theme.sync_succeeded')->latest()->first()?->after['mode']);
    }

    public function test_sync_now_remembers_the_cms_task_for_rollback(): void
    {
        [$site, $installation] = $this->activeInstallation();

        Http::fake([
            'https://shop.example.test/internal/control/v1/themes/sync' => Http::response(['ok' => true, 'queued' => true, 'task_id' => 42], 200),
            'https://shop.example.test/internal/control/v1/themes/sync-rollback' => Http::response(['ok' => true, 'restored' => 3], 200),
        ]);

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.sync', [$site, $installation]))
            ->assertRedirect();

        $this->assertSame('42', $installation->fresh()->last_sync_task_id);

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.sync-rollback', [$site, $installation]), ['confirmed' => '1'])
            ->assertRedirect()
            ->assertSessionHas('status', __('sites.theme_flash.sync_rollback_done'));

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/themes/sync-rollback')
            && ($request->data()['task_id'] ?? null) === '42');
        $this->assertNull($installation->fresh()->last_sync_task_id);
        $this->assertTrue($site->auditLogs()->where('action', 'theme.sync_rollback_succeeded')->exists());
    }

    public function test_sync_rollback_needs_confirmation_and_a_recorded_task(): void
    {
        [$site, $installation] = $this->activeInstallation();

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.sync-rollback', [$site, $installation]), ['confirmed' => '0'])
            ->assertSessionHas('error', __('sites.theme_flash.rollback_needs_confirm'));

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.sync-rollback', [$site, $installation]), ['confirmed' => '1'])
            ->assertSessionHas('error', __('sites.theme_flash.sync_rollback_unavailable'));

        Http::assertNothingSent();
    }

    public function test_update_keeps_the_previous_sha_and_files_rollback_swaps_back(): void
    {
        $site = $this->readySite();
        $theme = Theme::factory()->publicCatalog()->create([
            'theme_id' => 'beyazoglu',
            'repo_full_name' => 'deamon-themes/deamon-theme-beyazoglu',
            'latest_sha' => 'bbb222',
        ]);
        $installation = SiteThemeInstallation::factory()->active()->create([
            'site_id' => $site->id,
            'theme_id' => $theme->id,
            'ref' => 'main',
            'pinned_sha' => 'aaa111',
        ]);

        Http::fake([
            'https://shop.example.test/internal/control/v1/themes/update' => fn (Request $request) => Http::response([
                'ok' => true,
                'theme_id' => 'beyazoglu',
                'sha' => $request->data()['sha'] ?? null,
            ], 200),
        ]);

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.update', [$site, $installation]))
            ->assertRedirect();

        $installation->refresh();
        $this->assertSame('bbb222', $installation->pinned_sha);
        $this->assertSame('aaa111', $installation->previous_pinned_sha);

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.files-rollback', [$site, $installation]), ['confirmed' => '1'])
            ->assertRedirect()
            ->assertSessionHas('status', __('sites.theme_flash.files_rollback_done'));

        $installation->refresh();
        $this->assertSame('aaa111', $installation->pinned_sha);
        $this->assertSame('bbb222', $installation->previous_pinned_sha);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/themes/update')
            && ($request->data()['sha'] ?? null) === 'aaa111');
        $this->assertTrue($site->auditLogs()->where('action', 'theme.files_rollback_succeeded')->exists());
    }

    public function test_files_rollback_without_a_previous_sha_is_refused(): void
    {
        [$site, $installation] = $this->activeInstallation();

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.files-rollback', [$site, $installation]), ['confirmed' => '1'])
            ->assertSessionHas('error', __('sites.theme_flash.files_rollback_unavailable'));

        Http::assertNothingSent();
    }

    public function test_overwrite_is_refused_before_calling_an_old_cms(): void
    {
        $this->assertOverwriteRefusedFor('1.2.20');
    }

    public function test_overwrite_is_refused_when_the_cms_version_is_unknown(): void
    {
        $this->assertOverwriteRefusedFor(null);
    }

    private function assertOverwriteRefusedFor(?string $version): void
    {
        [$site, $installation] = $this->activeInstallation(cmsVersion: $version);

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.sync', [$site, $installation]), ['mode' => 'overwrite', 'confirmed' => '1'])
            ->assertRedirect()
            ->assertSessionHas('error', __('sites.theme_flash.sync_overwrite_needs_cms', [
                'version' => ControlPlaneAgentContract::THEME_SYNC_EDIT_SAFE_VERSION,
                'reported' => $version ?? __('ops.unknown'),
            ]));

        Http::assertNothingSent();
    }

    public function test_theme_card_disables_overwrite_and_warns_merge_on_an_old_cms(): void
    {
        [$site, $installation] = $this->activeInstallation(cmsVersion: '1.2.20');

        $html = (string) $this->actingAs($this->operator())
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-theme-sync="overwrite"', $html);
        $this->assertStringContainsString('data-theme-sync-overwrite-disabled', $html);
        $this->assertStringContainsString(e(__('sites.themes.sync_confirm_legacy', [
            'theme' => 'izyem',
            'version' => '1.2.21',
            'reported' => '1.2.20',
        ])), $html);
    }

    public function test_theme_card_offers_overwrite_and_the_safe_merge_copy_on_a_current_cms(): void
    {
        [$site, $installation] = $this->activeInstallation(cmsVersion: '1.2.21');

        $html = (string) $this->actingAs($this->operator())
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-theme-sync="overwrite"', $html);
        $this->assertStringNotContainsString('data-theme-sync-overwrite-disabled', $html);
        $this->assertStringContainsString(e(__('sites.themes.sync_confirm', ['theme' => 'izyem'])), $html);
    }

    public function test_sync_rejects_the_reset_mode(): void
    {
        [$site, $installation] = $this->activeInstallation();

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.sync', [$site, $installation]), ['mode' => 'reset', 'confirmed' => '1'])
            ->assertSessionHasErrors('mode');

        Http::assertNothingSent();
    }

    public function test_sync_now_surfaces_the_cms_message_when_the_repair_route_is_missing(): void
    {
        $site = $this->readySite();
        $theme = Theme::factory()->publicCatalog()->create(['theme_id' => 'izyem']);
        $installation = SiteThemeInstallation::factory()->active()->create([
            'site_id' => $site->id,
            'theme_id' => $theme->id,
        ]);

        Http::fake([
            // CMS older than 1.2.14 never registered the repair route.
            'https://shop.example.test/internal/control/v1/themes/data-install' => Http::response('', 404),
            'https://shop.example.test/internal/control/v1/themes/sync' => Http::response([
                'ok' => false,
                'error' => 'data_package_missing',
                'message' => 'Bu tema için sync.json tanımlı değil.',
            ], 422),
        ]);

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.sync', [$site, $installation]))
            ->assertRedirect()
            ->assertSessionHas('error', 'Bu tema için sync.json tanımlı değil.');

        $installation->refresh();
        $this->assertSame(ThemeInstallationStatus::Error, $installation->status);
        $this->assertSame('Bu tema için sync.json tanımlı değil.', $installation->last_error);

        $this->assertTrue($site->auditLogs()->where('action', 'theme.data_install_failed')->exists());
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

    public function test_assignable_themes_are_searchable_and_sorted_a_to_z(): void
    {
        $site = $this->readySite();
        Theme::factory()->publicCatalog()->create([
            'theme_id' => 'zebra-theme',
            'name' => 'Zebra',
        ]);
        Theme::factory()->publicCatalog()->create([
            'theme_id' => 'alpha-theme',
            'name' => 'Alpha',
        ]);
        Theme::factory()->publicCatalog()->create([
            'theme_id' => 'middle-theme',
            'name' => 'Middle',
        ]);

        $html = $this->actingAs($this->operator())
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee('data-ops-select-search', false)
            ->assertSee('data-ops-select-search-placeholder="'.__('sites.themes.search_placeholder').'"', false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/id="site-theme-id"[^>]*>.*Alpha.*Middle.*Zebra/s',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="site-theme-id"[^>]*>.*Zebra.*Alpha/s',
            $html,
        );
    }

    public function test_themes_tab_shows_health_theme_and_confirms_mutations(): void
    {
        $site = $this->readySite([
            'last_health_payload' => [
                'ok' => true,
                'active_theme_id' => 'izyem',
                // A CMS that keeps site edits, so the card offers the safe merge copy.
                'deamon_version' => '1.2.21',
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

    public function test_update_to_latest_sends_catalog_latest_sha_instead_of_the_old_pin(): void
    {
        $site = $this->readySite();
        $theme = Theme::factory()->publicCatalog()->create([
            'theme_id' => 'beyazoglu',
            'repo_full_name' => 'deamon-themes/deamon-theme-beyazoglu',
            'latest_sha' => 'bbb222',
        ]);
        $installation = SiteThemeInstallation::factory()->active()->create([
            'site_id' => $site->id,
            'theme_id' => $theme->id,
            'ref' => 'main',
            'pinned_sha' => 'aaa111',
        ]);

        Http::fake([
            'https://shop.example.test/internal/control/v1/themes/update' => fn (Request $request) => Http::response([
                'ok' => true,
                'theme_id' => 'beyazoglu',
                'ref' => 'main',
                'sha' => $request->data()['sha'] ?? null,
            ], 200),
        ]);

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.update', [$site, $installation]))
            ->assertRedirect();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/themes/update')
            && ($request->data()['sha'] ?? null) === 'bbb222');
        $this->assertSame('bbb222', $installation->fresh()->pinned_sha);
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

    /**
     * @return array{0: Site, 1: SiteThemeInstallation}
     */
    private function activeInstallation(?string $cmsVersion = null): array
    {
        $site = $this->readySite($cmsVersion === null ? [] : [
            'last_health_at' => now(),
            'last_health_payload' => ['ok' => true, 'status' => 'healthy', 'deamon_version' => $cmsVersion],
        ]);
        $theme = Theme::factory()->publicCatalog()->create(['theme_id' => 'izyem']);
        $installation = SiteThemeInstallation::factory()->active()->create([
            'site_id' => $site->id,
            'theme_id' => $theme->id,
        ]);

        return [$site, $installation];
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }
}
