<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Models\GithubSetting;
use App\Models\ThemeGitConnection;
use App\Models\User;
use App\Support\ThemeGitOAuthState;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeamonGitConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        config(['app.url' => 'https://plane.codron.co']);
    }

    public function test_deamon_install_callback_writes_settings_not_theme_connection(): void
    {
        GithubSetting::factory()->withApp(null)->create([
            'installation_id' => null,
        ]);

        Http::fake([
            'api.github.com/app/installations/5555' => Http::response([
                'id' => 5555,
                'account' => ['login' => 'codron-co', 'type' => 'Organization'],
            ], 200),
        ]);

        $state = ThemeGitOAuthState::put('deamon_install');

        $this->actingAs($this->operator())
            ->get(route('ops.themes.git.installed', [
                'installation_id' => '5555',
                'setup_action' => 'install',
                'state' => $state,
            ]))
            ->assertRedirect(route('ops.settings').'#deamon-git-heading')
            ->assertSessionHas('status');

        $settings = GithubSetting::current();
        $this->assertSame('5555', $settings->installation_id);
        $this->assertSame('codron-co', $settings->cms_account_login);
        $this->assertSame(0, ThemeGitConnection::query()->count());
    }

    public function test_themes_install_still_creates_a_theme_connection(): void
    {
        GithubSetting::factory()->withApp(null)->create([
            'installation_id' => null,
        ]);

        Http::fake([
            'api.github.com/app/installations/5555' => Http::response([
                'id' => 5555,
                'account' => ['login' => 'deamon-themes', 'type' => 'Organization'],
            ], 200),
            'api.github.com/app/installations/5555/access_tokens' => Http::response([
                'token' => 'ghs_test',
                'expires_at' => now()->addHour()->toIso8601String(),
            ], 200),
            'api.github.com/installation/repositories*' => Http::response([
                'repositories' => [],
            ], 200),
        ]);

        $state = ThemeGitOAuthState::put('install');

        $this->actingAs($this->operator())
            ->get(route('ops.themes.git.installed', [
                'installation_id' => '5555',
                'setup_action' => 'install',
                'state' => $state,
            ]))
            ->assertRedirect();

        $this->assertSame(1, ThemeGitConnection::query()->count());
        $this->assertNull(GithubSetting::current()->installation_id);
    }

    public function test_pat_save_and_disconnect(): void
    {
        GithubSetting::factory()->withApp('1001')->create([
            'cms_account_login' => 'codron-co',
        ]);

        $this->actingAs($this->operator())
            ->post(route('ops.settings.deamon-git.pat'), ['token' => 'ghp-deamon-pat'])
            ->assertRedirect(route('ops.settings').'#deamon-git-heading')
            ->assertSessionHas('status');

        $this->assertTrue(GithubSetting::current()->hasToken());

        $this->actingAs($this->operator())
            ->post(route('ops.settings.deamon-git.disconnect'))
            ->assertRedirect(route('ops.settings').'#deamon-git-heading');

        $settings = GithubSetting::current();
        $this->assertNull($settings->installation_id);
        $this->assertNull($settings->cms_account_login);
        $this->assertTrue($settings->hasToken());
    }

    public function test_viewer_cannot_connect_deamon_git(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole(OpsRole::Viewer->value);

        $this->actingAs($viewer)
            ->post(route('ops.settings.deamon-git.connect'))
            ->assertForbidden();
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }
}
