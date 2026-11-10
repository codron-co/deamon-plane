<?php

namespace Tests\Feature\Themes;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Enums\ThemeInstallationStatus;
use App\Models\Site;
use App\Models\SiteThemeInstallation;
use App\Models\Theme;
use App\Models\ThemeGitConnection;
use App\Models\User;
use App\Services\Themes\ThemeRolloutException;
use App\Services\Themes\ThemeRolloutService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * codron-co/deamon-plane#50: a theme branch, tag or commit must install what was asked,
 * not the catalog's default-branch head.
 */
class ThemeInstallRefTest extends TestCase
{
    use RefreshDatabase;

    private const MAIN_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const BRANCH_SHA = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const BRANCH_NEXT_SHA = 'cccccccccccccccccccccccccccccccccccccccc';

    private const COMMIT_SHA = '298c7c96a4110f29c40b2d1bb74a5b0a50490760';

    private Theme $theme;

    /** @var array<string, string> branch/tag/short commit => head the fake GitHub reports */
    private array $heads = [
        'fix/hero-video' => self::BRANCH_SHA,
        '298c7c9' => self::COMMIT_SHA,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();

        $connection = ThemeGitConnection::factory()->pat('github-test-token')->organization('deamon-themes')->connected()->create([
            'repo_name_prefix' => 'premium-',
        ]);
        $this->theme = Theme::factory()->publicCatalog()->create([
            'theme_id' => 'adanapelet',
            'repo_full_name' => 'deamon-themes/premium-adanapelet',
            'default_ref' => 'main',
            'latest_sha' => self::MAIN_SHA,
            'theme_git_connection_id' => $connection->id,
        ]);

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_starts_with($url, 'https://api.github.com/repos/deamon-themes/premium-adanapelet/commits')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $head = $this->heads[(string) ($query['sha'] ?? '')] ?? null;

                return $head === null
                    ? Http::response(['message' => 'No commit found for SHA'], 422)
                    : Http::response([['sha' => $head]], 200);
            }

            if (str_contains($url, '/internal/control/v1/themes/install') || str_contains($url, '/internal/control/v1/themes/update')) {
                $data = $request->data();

                return Http::response([
                    'ok' => true,
                    'theme_id' => 'adanapelet',
                    'version' => '1.0.3',
                    'is_valid' => true,
                    'active_theme_id' => 'default',
                    'auto_update' => false,
                    'ref' => $data['ref'] ?? null,
                    'sha' => $data['sha'] ?? null,
                ], 200);
            }

            return Http::response(['message' => 'unexpected '.$url], 500);
        });
    }

    public function test_a_branch_installs_that_branch_head_not_the_catalog_main_sha(): void
    {
        $site = $this->readySite();

        $this->assignThroughPanel($site, 'fix/hero-video');

        $this->assertInstallSent('fix/hero-video', self::BRANCH_SHA);
        $installation = $this->installation($site);
        $this->assertSame('fix/hero-video', $installation->ref);
        $this->assertSame(self::BRANCH_SHA, $installation->pinned_sha);
        $this->assertSame(ThemeInstallationStatus::Active, $installation->status);
    }

    public function test_a_commit_clones_the_default_branch_and_checks_the_commit_out(): void
    {
        $site = $this->readySite();

        $this->assignThroughPanel($site, strtoupper(self::COMMIT_SHA));

        // git clone --branch takes no SHA: clone main, then fetch + checkout the commit.
        $this->assertInstallSent('main', self::COMMIT_SHA);
        $installation = $this->installation($site);
        $this->assertSame(self::COMMIT_SHA, $installation->ref);
        $this->assertSame(self::COMMIT_SHA, $installation->pinned_sha);
    }

    public function test_an_abbreviated_commit_is_expanded_through_github_first(): void
    {
        $site = $this->readySite();

        $this->assignThroughPanel($site, '298c7c9');

        $this->assertInstallSent('main', self::COMMIT_SHA);
        $this->assertSame(self::COMMIT_SHA, $this->installation($site)->ref);
    }

    public function test_an_abbreviated_commit_github_cannot_find_is_refused(): void
    {
        $site = $this->readySite();

        try {
            app(ThemeRolloutService::class)->assign($site, $this->theme, $this->operator(), '127.0.0.1', ['ref' => 'deadbee']);
            $this->fail('An unknown short SHA must be refused.');
        } catch (ThemeRolloutException $exception) {
            $this->assertStringContainsString('deadbee', $exception->getMessage());
        }

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/themes/install'));
    }

    public function test_reassigning_with_another_ref_drops_the_old_pin(): void
    {
        $site = $this->readySite();
        SiteThemeInstallation::factory()->active()->create([
            'site_id' => $site->id,
            'theme_id' => $this->theme->id,
            'ref' => 'main',
            'pinned_sha' => self::MAIN_SHA,
        ]);

        $this->assignThroughPanel($site, 'fix/hero-video');

        // Before the fix the old main pin won and main was installed again.
        $this->assertInstallSent('fix/hero-video', self::BRANCH_SHA);
        $installation = $this->installation($site);
        $this->assertSame(self::BRANCH_SHA, $installation->pinned_sha);
        $this->assertSame(self::MAIN_SHA, $installation->previous_pinned_sha);
    }

    public function test_the_default_ref_still_installs_the_catalog_head(): void
    {
        $site = $this->readySite();

        $this->assignThroughPanel($site, '');

        $this->assertInstallSent('main', self::MAIN_SHA);
        $this->assertSame('main', $this->installation($site)->ref);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.github.com'));
    }

    public function test_an_update_moves_a_branch_install_to_the_branch_head(): void
    {
        $site = $this->readySite();
        $installation = SiteThemeInstallation::factory()->active()->create([
            'site_id' => $site->id,
            'theme_id' => $this->theme->id,
            'ref' => 'fix/hero-video',
            'pinned_sha' => self::BRANCH_SHA,
        ]);
        $this->heads['fix/hero-video'] = self::BRANCH_NEXT_SHA;

        app(ThemeRolloutService::class)->performUpdate($installation, null, null);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/themes/update')
            && $request['ref'] === 'fix/hero-video'
            && $request['sha'] === self::BRANCH_NEXT_SHA);
        $installation->refresh();
        $this->assertSame(self::BRANCH_NEXT_SHA, $installation->pinned_sha);
        $this->assertSame(self::BRANCH_SHA, $installation->previous_pinned_sha);
    }

    public function test_branch_and_commit_installs_are_not_behind_the_catalog(): void
    {
        $onBranch = $this->readySite(['primary_domain' => 'branch.example.test', 'agent_base_url' => 'https://branch.example.test']);
        $onCommit = $this->readySite(['primary_domain' => 'commit.example.test', 'agent_base_url' => 'https://commit.example.test']);
        $onOldMain = $this->readySite(['primary_domain' => 'old.example.test', 'agent_base_url' => 'https://old.example.test']);
        foreach ([[$onBranch, 'fix/hero-video', self::BRANCH_SHA], [$onCommit, self::COMMIT_SHA, self::COMMIT_SHA], [$onOldMain, 'main', self::BRANCH_SHA]] as [$site, $ref, $pin]) {
            SiteThemeInstallation::factory()->active()->create([
                'site_id' => $site->id,
                'theme_id' => $this->theme->id,
                'ref' => $ref,
                'pinned_sha' => $pin,
            ]);
        }

        $behind = SiteThemeInstallation::query()->behindCatalog()->pluck('site_id')->all();

        $this->assertSame([$onOldMain->id], $behind);
        $this->assertFalse($onBranch->fresh()->hasThemeUpdate());
        $this->assertFalse($onCommit->fresh()->hasThemeUpdate());
        $this->assertTrue($onOldMain->fresh()->hasThemeUpdate());
    }

    private function assignThroughPanel(Site $site, string $ref): void
    {
        $this->actingAs($this->operator())
            ->from(route('ops.sites.show', $site))
            ->post(route('ops.sites.themes.assign', $site), [
                'theme_id' => $this->theme->theme_id,
                'ref' => $ref,
            ])
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHasNoErrors();
    }

    private function assertInstallSent(string $ref, string $sha): void
    {
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/internal/control/v1/themes/install')
            && $request['ref'] === $ref
            && $request['sha'] === $sha
            && $request['repo'] === 'deamon-themes/premium-adanapelet');
    }

    private function installation(Site $site): SiteThemeInstallation
    {
        return SiteThemeInstallation::query()->where('site_id', $site->id)->where('theme_id', $this->theme->id)->firstOrFail();
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
