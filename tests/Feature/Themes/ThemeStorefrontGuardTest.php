<?php

namespace Tests\Feature\Themes;

use App\Enums\Channel;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Enums\ThemeInstallationStatus;
use App\Models\CoolifyConnection;
use App\Models\Site;
use App\Models\SiteThemeInstallation;
use App\Models\Theme;
use App\Models\User;
use App\Services\Agent\CoreThemeHealer;
use App\Services\Agent\SiteHealthChecker;
use App\Services\GitHub\ThemeManifest;
use App\Services\Sites\SiteAppHealthInspector;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * moonagro.com: the new theme installed cleanly and /timeline 500'd on a core
 * partial the CMS volume lacked. Plane now checks the storefront after every
 * theme change and tells a broken theme apart from a stale CMS core.
 */
class ThemeStorefrontGuardTest extends TestCase
{
    use RefreshDatabase;

    private const APP = 'moonagroappuuid000000001';

    private const HOST = 'https://moonagro.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        config(['ops.themes.smoke.enabled' => true]);
    }

    public function test_update_that_breaks_a_page_goes_back_to_the_previous_commit(): void
    {
        [$site, $installation] = $this->installation(['pinned_sha' => 'aaa111'], ['latest_sha' => 'bbb222']);
        $updates = [];

        Http::fake(function (Request $request) use (&$updates) {
            $url = $request->url();
            if (str_ends_with($url, '/internal/control/v1/themes/update')) {
                $updates[] = $request->data()['sha'] ?? null;

                return Http::response(['ok' => true, 'theme_id' => 'moonagro', 'sha' => $request->data()['sha'] ?? null], 200);
            }
            if (str_ends_with($url, '/internal/control/v1/health')) {
                return Http::response($this->healthPayload(inSync: true), 200);
            }
            if ($url === self::HOST.'/timeline') {
                // Broken while the new commit is live.
                return Http::response('Server Error', count($updates) === 1 ? 500 : 200);
            }
            if ($url === self::HOST.'/') {
                return Http::response('ok', 200);
            }

            return Http::response(['error' => 'unexpected '.$url], 404);
        });

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.update', [$site, $installation]))
            ->assertRedirect();

        $this->assertSame(['bbb222', 'aaa111'], $updates);

        $installation->refresh();
        $this->assertSame('aaa111', $installation->pinned_sha);
        $this->assertSame(ThemeInstallationStatus::Error, $installation->status);
        $this->assertSame(__('sites.theme_flash.smoke_rolled_back_files', ['pages' => '/timeline → 500']), $installation->last_error);

        $audit = $site->auditLogs()->where('action', 'theme.smoke_failed')->first();
        $this->assertNotNull($audit);
        $this->assertSame('files', $audit->after['rolled_back']);
        $this->assertSame(['/timeline' => 500], $audit->after['failures']);
        $this->assertFalse($audit->after['core_theme_stale']);
    }

    public function test_update_with_a_healthy_storefront_stays_active(): void
    {
        [$site, $installation] = $this->installation(['pinned_sha' => 'aaa111'], ['latest_sha' => 'bbb222']);

        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/internal/control/v1/themes/update')) {
                return Http::response(['ok' => true, 'theme_id' => 'moonagro', 'sha' => 'bbb222'], 200);
            }

            // The maintenance page (503) of an unpublished site is not a theme failure.
            return Http::response('ok', $request->url() === self::HOST.'/timeline' ? 503 : 200);
        });

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.update', [$site, $installation]))
            ->assertRedirect();

        $installation->refresh();
        $this->assertSame('bbb222', $installation->pinned_sha);
        $this->assertSame(ThemeInstallationStatus::Active, $installation->status);
        $this->assertFalse($site->auditLogs()->where('action', 'theme.smoke_failed')->exists());
    }

    public function test_sync_that_breaks_a_page_restores_the_rows_it_changed(): void
    {
        [$site, $installation] = $this->installation();

        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_ends_with($url, '/themes/sync') => Http::response(['ok' => true, 'queued' => true, 'task_id' => 77], 200),
                str_ends_with($url, '/themes/sync-rollback') => Http::response(['ok' => true, 'restored' => 4], 200),
                str_ends_with($url, '/internal/control/v1/health') => Http::response($this->healthPayload(inSync: true), 200),
                $url === self::HOST.'/' => Http::response('Server Error', 500),
                default => Http::response('ok', 200),
            };
        });

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.sync', [$site, $installation]))
            ->assertRedirect()
            ->assertSessionHas('error', __('sites.theme_flash.smoke_rolled_back_sync', ['pages' => '/ → 500']));

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/themes/sync-rollback')
            && ($request->data()['task_id'] ?? null) === '77');
        $this->assertSame(ThemeInstallationStatus::Error, $installation->fresh()->status);
    }

    public function test_stale_core_theme_restarts_the_app_and_keeps_the_theme_change(): void
    {
        [$site, $installation] = $this->installation(['pinned_sha' => 'aaa111'], ['latest_sha' => 'bbb222']);
        $restarts = 0;

        Http::fake(function (Request $request) use (&$restarts) {
            $url = $request->url();
            if (str_ends_with($url, '/themes/update')) {
                return Http::response(['ok' => true, 'theme_id' => 'moonagro', 'sha' => $request->data()['sha'] ?? null], 200);
            }
            if (str_ends_with($url, '/internal/control/v1/health')) {
                return Http::response($this->healthPayload(inSync: false), 200);
            }
            if ($request->method() === 'POST' && str_ends_with($url, '/applications/'.self::APP.'/restart')) {
                $restarts++;

                return Http::response(['message' => 'Restarted.'], 200);
            }

            return Http::response('Server Error', $url === self::HOST.'/timeline' ? 500 : 200);
        });

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.update', [$site, $installation]))
            ->assertRedirect();

        $installation->refresh();
        $this->assertSame('bbb222', $installation->pinned_sha, 'the theme is not at fault, so it is not rolled back');
        $this->assertSame(__('sites.theme_flash.smoke_core_stale', ['pages' => '/timeline → 500']), $installation->last_error);
        $this->assertSame(1, $restarts);
        $this->assertTrue($site->auditLogs()->where('action', 'site.core_theme_restarted')->exists());
        $this->assertTrue($site->auditLogs()->where('action', 'theme.smoke_failed')->first()?->after['core_theme_stale']);
    }

    public function test_health_poll_flags_a_stale_core_theme_and_restarts_once_per_window(): void
    {
        [$site] = $this->installation();
        $restarts = 0;

        Http::fake(function (Request $request) use (&$restarts) {
            if (str_ends_with($request->url(), '/internal/control/v1/health')) {
                return Http::response($this->healthPayload(inSync: false), 200);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/applications/'.self::APP.'/restart')) {
                $restarts++;

                return Http::response(['message' => 'Restarted.'], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $checker = app(SiteHealthChecker::class);
        $checker->check($site);
        $checker->check($site->fresh());

        $this->assertSame(1, $restarts);
        $site->refresh();
        $this->assertFalse($site->last_health_payload['core_theme_in_sync']);

        $issues = array_map(fn ($issue) => $issue->toArray(), app(SiteAppHealthInspector::class)->localIssues($site));
        $this->assertContains(['code' => 'core_theme_stale', 'fix' => 'restart_app', 'key' => null], $issues);
    }

    public function test_in_sync_or_old_cms_health_does_not_restart(): void
    {
        [$site] = $this->installation();
        $healer = app(CoreThemeHealer::class);

        Http::fake();

        $this->assertFalse($healer->healFromHealth($site, ['core_theme_in_sync' => true]));
        $this->assertFalse($healer->healFromHealth($site, ['ok' => true]));
        Http::assertNothingSent();
    }

    public function test_assign_is_refused_when_the_site_cms_is_older_than_the_theme_needs(): void
    {
        [$site] = $this->installation();
        $site->forceFill(['last_health_payload' => ['ok' => true, 'deamon_version' => '1.2.26']])->save();
        $theme = Theme::factory()->publicCatalog()->create([
            'theme_id' => 'timeline-theme',
            'minimum_deamon_version' => '1.2.27',
        ]);

        $this->actingAs($this->operator())
            ->post(route('ops.sites.themes.assign', $site), ['theme_id' => $theme->theme_id])
            ->assertSessionHas('error', __('sites.theme_flash.cms_too_old', ['minimum' => '1.2.27', 'reported' => '1.2.26']));

        $this->assertFalse(SiteThemeInstallation::query()->where('theme_id', $theme->id)->exists());
        Http::assertNothingSent();
    }

    public function test_manifest_smoke_paths_accept_only_storefront_paths(): void
    {
        $manifest = ThemeManifest::fromJson([
            'id' => 'moonagro',
            'smoke_paths' => ['/timeline', '/timeline', 'https://evil.example/', '//evil.example', '/../etc', 'hizmetler', '/duyurular?page=2', 42],
        ], 'moonagro', 'theme.json');

        $this->assertSame(['/timeline', '/duyurular?page=2'], $manifest->smokePaths);
    }

    /**
     * @param  array<string, mixed>  $installationOverrides
     * @param  array<string, mixed>  $themeOverrides
     * @return array{0: Site, 1: SiteThemeInstallation}
     */
    private function installation(array $installationOverrides = [], array $themeOverrides = []): array
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => 'guard-token',
        ]);

        $site = Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'channel' => Channel::Alpha,
            'coolify_app_uuid' => self::APP,
            'coolify_connection_id' => $connection->id,
            'primary_domain' => 'moonagro.example.test',
            'agent_base_url' => self::HOST,
        ]);

        $theme = Theme::factory()->publicCatalog()->create(array_merge([
            'theme_id' => 'moonagro',
            'repo_full_name' => 'deamon-themes/premium-moonagro',
            'smoke_paths' => ['/timeline'],
        ], $themeOverrides));

        $installation = SiteThemeInstallation::factory()->active()->create(array_merge([
            'site_id' => $site->id,
            'theme_id' => $theme->id,
            'ref' => 'main',
            'is_active' => true,
        ], $installationOverrides));

        return [$site, $installation];
    }

    /**
     * @return array<string, mixed>
     */
    private function healthPayload(bool $inSync): array
    {
        return [
            'deamon_version' => '1.2.30',
            'queue_ok' => true,
            'active_theme_id' => 'moonagro',
            'core_theme' => ['stamp' => str_repeat('a', 64), 'seed_stamp' => str_repeat($inSync ? 'a' : 'b', 64), 'in_sync' => $inSync],
        ];
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Operator->value);

        return $user;
    }
}
