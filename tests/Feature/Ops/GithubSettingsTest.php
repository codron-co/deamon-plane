<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Models\GithubSetting;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GithubSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'github-pat-secret-token';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_settings_page_never_renders_github_token(): void
    {
        GithubSetting::factory()->create([
            'token' => self::TOKEN,
            'org' => 'deamon-themes',
        ]);

        $this->actingAs($this->operator())
            ->get(route('ops.settings'))
            ->assertOk()
            ->assertSee('GitHub theme catalog', false)
            ->assertSee('/webhooks/github', false)
            ->assertDontSee(self::TOKEN, false);
    }

    public function test_operator_saves_github_token_encrypted(): void
    {
        $this->actingAs($this->operator())
            ->post(route('ops.settings.github.update'), [
                'org' => 'deamon-themes',
                'token' => self::TOKEN,
                'webhook_secret' => 'gh-hook-secret',
            ])
            ->assertRedirect();

        $raw = DB::table('github_settings')->value('token');
        $this->assertNotSame(self::TOKEN, $raw);
        $this->assertSame(self::TOKEN, GithubSetting::current()->token);
        $this->assertArrayNotHasKey('token', GithubSetting::current()->toArray());
        $this->assertArrayNotHasKey('webhook_secret', GithubSetting::current()->toArray());
    }

    public function test_test_connection_lists_prefixed_repos(): void
    {
        GithubSetting::factory()->withToken(self::TOKEN)->create();

        Http::fake([
            'https://api.github.com/orgs/deamon-themes/repos*' => Http::response([
                ['name' => 'deamon-theme-a', 'full_name' => 'deamon-themes/deamon-theme-a', 'default_branch' => 'main'],
                ['name' => 'readme', 'full_name' => 'deamon-themes/readme', 'default_branch' => 'main'],
            ], 200),
        ]);

        $this->actingAs($this->operator())
            ->from(route('ops.settings'))
            ->post(route('ops.settings.github.test'))
            ->assertRedirect(route('ops.settings'))
            ->assertSessionHas('status');

        $status = session('status');
        $this->assertIsString($status);
        $this->assertStringContainsString('1 theme repo', $status);
        $this->assertStringNotContainsString(self::TOKEN, $status);
    }

    public function test_viewer_cannot_save_github_settings(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole(OpsRole::Viewer->value);

        $this->actingAs($viewer)
            ->post(route('ops.settings.github.update'), [
                'token' => self::TOKEN,
            ])
            ->assertForbidden();
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }
}
