<?php

namespace Tests\Feature\Themes;

use App\Enums\OpsRole;
use App\Enums\ThemeGitSelectionMode;
use App\Enums\ThemeVisibility;
use App\Models\GithubSetting;
use App\Models\Theme;
use App\Models\ThemeGitConnection;
use App\Models\ThemeGitConnectionRepo;
use App\Models\User;
use App\Services\GitHub\GitHubAppClient;
use App\Services\Themes\ThemeCatalogSync;
use App\Support\ThemeGitOAuthState;
use Database\Factories\GithubSettingFactory;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ThemeGitConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const PAT = 'github-pat-secret-token';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_manifest_start_fails_on_localhost_and_shows_pat_fallback(): void
    {
        $this->actingAs($this->operator())
            ->from(route('ops.themes'))
            ->post(route('ops.themes.git.connect'))
            ->assertRedirect(route('ops.themes'))
            ->assertSessionHas('error');

        $html = $this->actingAs($this->operator())
            ->get(route('ops.themes'))
            ->assertOk()
            ->assertSee(__('themes.git.errors.public_url'), false)
            ->assertSee(__('themes.git.pat.summary'), false)
            ->getContent();

        $this->assertStringNotContainsString(self::PAT, $html);
    }

    public function test_manifest_start_and_callback_store_app_credentials(): void
    {
        $this->usePublicPlaneUrl();
        $pem = GithubSettingFactory::testPrivateKey();

        $html = $this->actingAs($this->operator())
            ->post(route('ops.themes.git.connect'))
            ->assertOk()
            ->assertSee('https://github.com/settings/apps/new', false)
            ->assertSee('Deamon Plane Themes', false)
            ->assertSee('&quot;metadata&quot;:&quot;read&quot;', false)
            ->assertSee('&quot;contents&quot;:&quot;read&quot;', false)
            ->assertSee(url('/webhooks/github'), false)
            ->getContent();

        $this->assertStringContainsString('data-ops-native', $html);
        $this->assertMatchesRegularExpression('/name="state" value="[a-f0-9]+"/', $html);
        preg_match('/name="state" value="([a-f0-9]+)"/', $html, $matches);
        $state = $matches[1] ?? '';
        $this->assertNotSame('', $state);

        Http::fake([
            'https://api.github.com/app-manifests/manifest-code/conversions' => Http::response([
                'id' => 99,
                'slug' => 'deamon-plane-themes',
                'pem' => $pem,
                'client_id' => 'Iv1.abc',
                'client_secret' => 'github-app-client-secret',
                'webhook_secret' => 'converted-hook-secret',
            ], 201),
        ]);

        $response = $this->actingAs($this->operator())
            ->get(route('ops.themes.git.callback', [
                'code' => 'manifest-code',
                'state' => $state,
            ]));

        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://github.com/apps/deamon-plane-themes/installations/new?state=', $location);
        $this->assertStringNotContainsString('github-app-client-secret', $location);
        $this->assertStringNotContainsString($pem, $location);

        $settings = GithubSetting::current();
        $this->assertSame('99', $settings->app_id);
        $this->assertSame('deamon-plane-themes', $settings->slug);
        $this->assertSame('converted-hook-secret', $settings->webhook_secret);
        $this->assertSame('github-app-client-secret', $settings->client_secret);
        $this->assertNotSame('github-app-client-secret', DB::table('github_settings')->value('client_secret'));
        $this->assertArrayNotHasKey('client_secret', $settings->toArray());
        $this->assertArrayNotHasKey('private_key', $settings->toArray());
    }

    public function test_themes_index_marks_github_connect_form_as_native(): void
    {
        $this->usePublicPlaneUrl();

        $this->actingAs($this->operator())
            ->get(route('ops.themes'))
            ->assertOk()
            ->assertSee(__('themes.git.connect'), false)
            ->assertSee('data-ops-native', false)
            ->assertSee('action="'.route('ops.themes.git.connect').'"', false);
    }

    public function test_manifest_callback_rejects_missing_state(): void
    {
        $this->usePublicPlaneUrl();

        $this->actingAs($this->operator())
            ->from(route('ops.themes'))
            ->get(route('ops.themes.git.callback', ['code' => 'manifest-code']))
            ->assertRedirect(route('ops.themes'))
            ->assertSessionHas('error', __('themes.git.errors.state'));
    }

    public function test_install_callback_creates_connection(): void
    {
        GithubSetting::factory()->withApp()->create();
        $this->actingAs($this->operator())->get(route('ops.themes'));
        $state = ThemeGitOAuthState::put('install');

        Http::fake([
            'https://api.github.com/app/installations/5551' => Http::response([
                'id' => 5551,
                'account' => [
                    'login' => 'acme-themes',
                    'type' => 'Organization',
                ],
            ], 200),
        ]);

        $this->actingAs($this->operator())
            ->get(route('ops.themes.git.installed', [
                'installation_id' => '5551',
                'setup_action' => 'install',
                'state' => $state,
            ]))
            ->assertRedirect()
            ->assertSessionHas('status');

        $connection = ThemeGitConnection::query()->where('installation_id', '5551')->first();
        $this->assertNotNull($connection);
        $this->assertSame('acme-themes', $connection->account_login);
        $this->assertSame('organization', $connection->account_type->value);
        $this->assertSame('github_app', $connection->kind->value);
        $this->assertSame('connected', $connection->status->value);
        $this->assertNull($connection->token);
    }

    public function test_operator_can_connect_pat_without_manifest(): void
    {
        $this->actingAs($this->operator())
            ->from(route('ops.themes'))
            ->post(route('ops.themes.git.pat'), [
                'account_login' => 'local-dev',
                'account_type' => 'user',
                'token' => self::PAT,
                'repo_name_prefix' => 'deamon-theme-',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $connection = ThemeGitConnection::query()->where('account_login', 'local-dev')->first();
        $this->assertNotNull($connection);
        $this->assertSame('pat', $connection->kind->value);
        $this->assertSame(self::PAT, $connection->token);
        $this->assertNotSame(self::PAT, DB::table('theme_git_connections')->value('token'));
        $this->assertArrayNotHasKey('token', $connection->toArray());

        $this->actingAs($this->operator())
            ->get(route('ops.themes.git.show', $connection))
            ->assertOk()
            ->assertSee('local-dev', false)
            ->assertDontSee(self::PAT, false);
    }

    public function test_catalog_sync_respects_all_versus_selected(): void
    {
        $all = ThemeGitConnection::factory()->pat('pat-all')->organization('org-all')->connected()->create([
            'repo_name_prefix' => null,
        ]);
        $selected = ThemeGitConnection::factory()->pat('pat-selected')->organization('org-sel')->connected()->selected()->create();

        ThemeGitConnectionRepo::factory()->create([
            'theme_git_connection_id' => $selected->id,
            'repo_full_name' => 'org-sel/keep-me',
            'included' => true,
            'default_branch' => 'main',
        ]);
        ThemeGitConnectionRepo::factory()->create([
            'theme_git_connection_id' => $selected->id,
            'repo_full_name' => 'org-sel/skip-me',
            'included' => false,
            'default_branch' => 'main',
        ]);

        $keepManifest = base64_encode((string) json_encode([
            'id' => 'keep-me',
            'name' => 'Keep Me',
        ], JSON_THROW_ON_ERROR));
        $shopManifest = base64_encode((string) json_encode([
            'id' => 'shop',
            'name' => 'Shop',
        ], JSON_THROW_ON_ERROR));

        Http::fake([
            'https://api.github.com/orgs/org-all/repos*' => Http::response([
                [
                    'name' => 'shop',
                    'full_name' => 'org-all/shop',
                    'default_branch' => 'main',
                    'private' => true,
                ],
                [
                    'name' => 'readme',
                    'full_name' => 'org-all/readme',
                    'default_branch' => 'main',
                    'private' => true,
                ],
            ], 200),
            'https://api.github.com/repos/org-all/shop/contents/theme.json*' => Http::response([
                'encoding' => 'base64',
                'content' => $shopManifest,
            ], 200),
            'https://api.github.com/repos/org-all/shop/commits*' => Http::response([['sha' => 'aaa111']], 200),
            'https://api.github.com/repos/org-all/readme/contents/theme.json*' => Http::response(['message' => 'Not Found'], 404),
            'https://api.github.com/repos/org-all/readme/contents/theme/theme.json*' => Http::response(['message' => 'Not Found'], 404),
            'https://api.github.com/repos/org-all/readme/commits*' => Http::response([['sha' => 'bbb222']], 200),
            'https://api.github.com/repos/org-sel/keep-me/contents/theme.json*' => Http::response([
                'encoding' => 'base64',
                'content' => $keepManifest,
            ], 200),
            'https://api.github.com/repos/org-sel/keep-me/commits*' => Http::response([['sha' => 'ccc333']], 200),
        ]);

        app(ThemeCatalogSync::class)->sync();

        $this->assertSame(3, Theme::query()->count());
        $this->assertSame($all->id, Theme::query()->where('theme_id', 'shop')->value('theme_git_connection_id'));
        $this->assertSame($all->id, Theme::query()->where('theme_id', 'readme')->value('theme_git_connection_id'));
        $this->assertSame($selected->id, Theme::query()->where('theme_id', 'keep-me')->value('theme_git_connection_id'));
        $this->assertNull(Theme::query()->where('repo_full_name', 'org-sel/skip-me')->first());
        $this->assertSame(ThemeVisibility::Private, Theme::query()->where('theme_id', 'shop')->first()?->visibility);
    }

    public function test_prefix_filter_applies_only_in_all_mode(): void
    {
        ThemeGitConnection::factory()->pat('pat-prefix')->organization('prefixed')->connected()->create([
            'repo_name_prefix' => 'deamon-theme-',
        ]);

        $manifest = base64_encode((string) json_encode([
            'id' => 'nova',
            'name' => 'Nova',
        ], JSON_THROW_ON_ERROR));

        Http::fake([
            'https://api.github.com/orgs/prefixed/repos*' => Http::response([
                [
                    'name' => 'deamon-theme-nova',
                    'full_name' => 'prefixed/deamon-theme-nova',
                    'default_branch' => 'main',
                    'private' => true,
                ],
                [
                    'name' => 'other-tooling',
                    'full_name' => 'prefixed/other-tooling',
                    'default_branch' => 'main',
                    'private' => true,
                ],
            ], 200),
            'https://api.github.com/repos/prefixed/deamon-theme-nova/contents/theme.json*' => Http::response([
                'encoding' => 'base64',
                'content' => $manifest,
            ], 200),
            'https://api.github.com/repos/prefixed/deamon-theme-nova/commits*' => Http::response([['sha' => 'ddd444']], 200),
        ]);

        app(ThemeCatalogSync::class)->sync();

        $this->assertSame(1, Theme::query()->count());
        $this->assertSame('nova', Theme::query()->value('theme_id'));
    }

    public function test_legacy_settings_become_a_prefixed_org_connection(): void
    {
        $settings = GithubSetting::factory()->withToken(self::PAT)->create([
            'org' => 'deamon-themes',
        ]);
        $theme = Theme::factory()->create([
            'theme_id' => 'legacy-one',
            'theme_git_connection_id' => null,
        ]);

        $connection = ThemeGitConnection::createFromLegacySettings($settings);

        $this->assertNotNull($connection);
        $this->assertSame('deamon-themes', $connection->account_login);
        $this->assertSame('all', $connection->selection_mode->value);
        $this->assertSame('deamon-theme-', $connection->repo_name_prefix);
        $this->assertSame('pat', $connection->kind->value);
        $this->assertSame(self::PAT, $connection->token);
        $this->assertSame($connection->id, $theme->fresh()?->theme_git_connection_id);

        $settings->refresh();
        $this->assertFalse($settings->hasToken());
        $this->assertNull($settings->installation_id);
        $this->assertNull(ThemeGitConnection::createFromLegacySettings($settings));
    }

    public function test_mint_clone_token_never_returns_a_pat(): void
    {
        $pat = ThemeGitConnection::factory()->pat(self::PAT)->connected()->create();
        $this->assertNull(GitHubAppClient::fromConnection($pat)->mintCloneToken($pat));

        GithubSetting::factory()->withApp('2002')->create();
        $app = ThemeGitConnection::factory()->githubApp('2002')->connected()->create();

        Http::fake([
            'https://api.github.com/app/installations/2002/access_tokens' => Http::response([
                'token' => 'ghs_install_only',
            ], 201),
        ]);

        $this->assertSame(
            'ghs_install_only',
            GitHubAppClient::fromConnection($app)->mintCloneToken($app),
        );
    }

    public function test_operator_can_update_selection_and_disconnect(): void
    {
        $connection = ThemeGitConnection::factory()->pat(self::PAT)->organization('acme')->connected()->create();
        ThemeGitConnectionRepo::factory()->create([
            'theme_git_connection_id' => $connection->id,
            'repo_full_name' => 'acme/one',
            'included' => true,
        ]);
        ThemeGitConnectionRepo::factory()->create([
            'theme_git_connection_id' => $connection->id,
            'repo_full_name' => 'acme/two',
            'included' => true,
        ]);

        $this->actingAs($this->operator())
            ->from(route('ops.themes.git.show', $connection))
            ->patch(route('ops.themes.git.update', $connection), [
                'name' => 'Acme catalog',
                'selection_mode' => ThemeGitSelectionMode::Selected->value,
                'repo_name_prefix' => '',
                'included' => ['acme/one'],
            ])
            ->assertRedirect(route('ops.themes.git.show', $connection))
            ->assertSessionHas('status');

        $connection->refresh();
        $this->assertSame('Acme catalog', $connection->name);
        $this->assertTrue($connection->usesSelectedRepos());
        $this->assertTrue($connection->repos()->where('repo_full_name', 'acme/one')->value('included'));
        $this->assertFalse($connection->repos()->where('repo_full_name', 'acme/two')->value('included'));

        $this->actingAs($this->operator())
            ->delete(route('ops.themes.git.destroy', $connection))
            ->assertRedirect(route('ops.themes'))
            ->assertSessionHas('status');

        $this->assertSame(0, ThemeGitConnection::query()->count());
    }

    public function test_sync_repos_refreshes_the_connection_list(): void
    {
        $connection = ThemeGitConnection::factory()->pat('pat-sync')->organization('sync-org')->connected()->create();

        Http::fake([
            'https://api.github.com/orgs/sync-org/repos*' => Http::response([
                [
                    'id' => 11,
                    'name' => 'alpha',
                    'full_name' => 'sync-org/alpha',
                    'default_branch' => 'main',
                    'private' => true,
                    'html_url' => 'https://github.com/sync-org/alpha',
                ],
            ], 200),
        ]);

        $this->actingAs($this->operator())
            ->from(route('ops.themes.git.show', $connection))
            ->post(route('ops.themes.git.sync-repos', $connection))
            ->assertRedirect(route('ops.themes.git.show', $connection))
            ->assertSessionHas('status');

        $this->assertSame(1, $connection->repos()->count());
        $this->assertSame('sync-org/alpha', $connection->repos()->value('repo_full_name'));
    }

    public function test_themes_index_rows_open_show_not_edit(): void
    {
        $theme = Theme::factory()->create([
            'name' => 'Row Theme',
            'theme_id' => 'row-theme',
        ]);
        $connection = ThemeGitConnection::factory()->pat('pat-row')->organization('row-org')->connected()->create();

        $this->actingAs($this->operator())
            ->get(route('ops.themes'))
            ->assertOk()
            ->assertSee('data-href="'.route('ops.themes.show', $theme).'"', false)
            ->assertSee('data-href="'.route('ops.themes.git.show', $connection).'"', false)
            ->assertDontSee('action="'.route('ops.themes.update', $theme).'"', false)
            ->assertDontSee(self::PAT, false);
    }

    public function test_viewer_cannot_mutate_git_connections(): void
    {
        $connection = ThemeGitConnection::factory()->pat(self::PAT)->connected()->create();
        $viewer = User::factory()->create();
        $viewer->assignRole(OpsRole::Viewer->value);

        $this->actingAs($viewer)
            ->post(route('ops.themes.git.connect'))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('ops.themes.git.pat'), [
                'account_login' => 'nope',
                'account_type' => 'user',
                'token' => self::PAT,
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->patch(route('ops.themes.git.update', $connection), [
                'selection_mode' => 'all',
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('ops.themes.git.sync-repos', $connection))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->delete(route('ops.themes.git.destroy', $connection))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get(route('ops.themes'))
            ->assertOk()
            ->assertDontSee('action="'.route('ops.themes.git.connect').'"', false)
            ->assertDontSee('action="'.route('ops.themes.git.pat').'"', false)
            ->assertDontSee(__('themes.git.pat.submit'), false);
    }

    public function test_connect_another_requires_existing_app(): void
    {
        $this->usePublicPlaneUrl();

        $this->actingAs($this->operator())
            ->from(route('ops.themes'))
            ->post(route('ops.themes.git.connect-another'))
            ->assertRedirect(route('ops.themes'))
            ->assertSessionHas('error', __('themes.git.errors.app_missing'));

        GithubSetting::factory()->withApp()->create();

        $response = $this->actingAs($this->operator())
            ->from(route('ops.themes'))
            ->post(route('ops.themes.git.connect-another'));

        $this->assertStringStartsWith(
            'https://github.com/apps/deamon-plane-themes/installations/new?state=',
            (string) $response->headers->get('Location'),
        );
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }

    private function usePublicPlaneUrl(): void
    {
        config(['app.url' => 'https://plane.example.com']);
        URL::forceRootUrl('https://plane.example.com');
        URL::forceScheme('https');
    }
}
