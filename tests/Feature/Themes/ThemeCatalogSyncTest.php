<?php

namespace Tests\Feature\Themes;

use App\Enums\OpsRole;
use App\Enums\ThemeVisibility;
use App\Models\Theme;
use App\Models\ThemeGitConnection;
use App\Models\User;
use App\Services\Themes\ThemeCatalogSync;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ThemeCatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();

        ThemeGitConnection::factory()->pat('github-test-token')->organization('deamon-themes')->connected()->create([
            'repo_name_prefix' => 'deamon-theme-',
        ]);
    }

    public function test_sync_upserts_prefixed_repos_and_parses_theme_json(): void
    {
        $manifest = base64_encode((string) json_encode([
            'id' => 'beyazoglu',
            'name' => 'Beyazoğlu',
            'minimum_deamon_version' => '1.1.0',
            'description' => 'Catalog theme',
        ], JSON_THROW_ON_ERROR));

        Http::fake([
            'https://api.github.com/orgs/deamon-themes/repos*' => Http::response([
                [
                    'name' => 'deamon-theme-beyazoglu',
                    'full_name' => 'deamon-themes/deamon-theme-beyazoglu',
                    'default_branch' => 'main',
                    'private' => true,
                ],
                [
                    'name' => 'other-tooling',
                    'full_name' => 'deamon-themes/other-tooling',
                    'default_branch' => 'main',
                    'private' => true,
                ],
            ], 200),
            'https://api.github.com/repos/deamon-themes/deamon-theme-beyazoglu/contents/theme.json*' => Http::response([
                'encoding' => 'base64',
                'content' => $manifest,
            ], 200),
            'https://api.github.com/repos/deamon-themes/deamon-theme-beyazoglu/commits*' => Http::response([
                ['sha' => 'abc123def456'],
            ], 200),
        ]);

        $result = app(ThemeCatalogSync::class)->sync();

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['updated']);

        $theme = Theme::query()->where('theme_id', 'beyazoglu')->first();
        $this->assertNotNull($theme);
        $this->assertSame('deamon-themes/deamon-theme-beyazoglu', $theme->repo_full_name);
        $this->assertSame('Beyazoğlu', $theme->name);
        $this->assertSame('1.1.0', $theme->minimum_deamon_version);
        $this->assertSame('abc123def456', $theme->latest_sha);
        $this->assertSame(ThemeVisibility::Private, $theme->visibility);
        $this->assertNotNull($theme->last_synced_at);
        $this->assertNotNull($theme->theme_git_connection_id);
    }

    public function test_sync_falls_back_to_theme_slash_theme_json(): void
    {
        $manifest = base64_encode((string) json_encode([
            'id' => 'izyem',
            'name' => 'İzyem',
        ], JSON_THROW_ON_ERROR));

        Http::fake([
            'https://api.github.com/orgs/deamon-themes/repos*' => Http::response([
                [
                    'name' => 'deamon-theme-izyem',
                    'full_name' => 'deamon-themes/deamon-theme-izyem',
                    'default_branch' => 'main',
                    'private' => false,
                ],
            ], 200),
            'https://api.github.com/repos/deamon-themes/deamon-theme-izyem/contents/theme.json*' => Http::response(['message' => 'Not Found'], 404),
            'https://api.github.com/repos/deamon-themes/deamon-theme-izyem/contents/theme/theme.json*' => Http::response([
                'encoding' => 'base64',
                'content' => $manifest,
            ], 200),
            'https://api.github.com/repos/deamon-themes/deamon-theme-izyem/commits*' => Http::response([
                ['sha' => 'deadbeef'],
            ], 200),
        ]);

        app(ThemeCatalogSync::class)->sync();

        $this->assertSame('İzyem', Theme::query()->where('theme_id', 'izyem')->value('name'));
    }

    public function test_operator_can_trigger_sync_from_ui(): void
    {
        Http::fake([
            'https://api.github.com/orgs/deamon-themes/repos*' => Http::response([], 200),
        ]);

        $this->actingAs($this->operator())
            ->from(route('ops.themes'))
            ->post(route('ops.themes.sync'))
            ->assertRedirect(route('ops.themes'))
            ->assertSessionHas('status');
    }

    public function test_viewer_cannot_sync(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole(OpsRole::Viewer->value);

        $this->actingAs($viewer)
            ->post(route('ops.themes.sync'))
            ->assertForbidden();
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }
}
