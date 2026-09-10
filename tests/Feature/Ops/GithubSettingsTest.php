<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Models\GithubSetting;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_settings_page_points_to_themes_and_never_renders_a_credential_form(): void
    {
        GithubSetting::factory()->create([
            'token' => self::TOKEN,
            'org' => 'deamon-themes',
        ]);

        $this->actingAs($this->operator())
            ->get(route('ops.settings'))
            ->assertOk()
            ->assertSee('GitHub theme catalog', false)
            ->assertSee(__('settings.github.pointer'), false)
            ->assertSee(__('settings.github.open_themes'), false)
            ->assertSee(route('ops.themes'), false)
            ->assertSee(__('settings.coolify.title'), false)
            ->assertSee(route('ops.coolify.index'), false)
            ->assertSee('/webhooks/github', false)
            ->assertDontSee(self::TOKEN, false)
            ->assertDontSee('name="token"', false)
            ->assertDontSee('name="private_key"', false)
            ->assertDontSee('name="installation_id"', false)
            ->assertDontSee('name="current_password"', false)
            ->assertDontSee('id="account_name"', false)
            ->assertDontSee('coolify-connection-heading', false);
    }

    public function test_operator_cannot_save_github_credentials_on_settings(): void
    {
        GithubSetting::factory()->create([
            'org' => 'deamon-themes',
            'token' => null,
        ]);

        $this->actingAs($this->operator())
            ->from(route('ops.settings'))
            ->post(route('ops.settings.github.update'), [
                'org' => 'hacked-org',
                'token' => self::TOKEN,
                'webhook_secret' => 'gh-hook-secret',
            ])
            ->assertRedirect(route('ops.themes'))
            ->assertSessionHas('status');

        $settings = GithubSetting::current();
        $this->assertSame('deamon-themes', $settings->org);
        $this->assertFalse($settings->hasToken());
        $this->assertFalse($settings->hasWebhookSecret());
    }

    public function test_github_test_connection_redirects_to_themes(): void
    {
        $this->actingAs($this->operator())
            ->from(route('ops.settings'))
            ->post(route('ops.settings.github.test'))
            ->assertRedirect(route('ops.themes'))
            ->assertSessionHas('status');
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
