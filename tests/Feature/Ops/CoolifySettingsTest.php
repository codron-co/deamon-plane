<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Models\CoolifyConnection;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CoolifySettingsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'plane-settings-secret-token';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_settings_page_points_to_coolify_menu_and_never_renders_token(): void
    {
        CoolifyConnection::factory()->create([
            'api_token' => self::TOKEN,
            'base_url' => 'https://coolify.example',
        ]);

        $this->actingAs($this->operator())
            ->get(route('ops.settings'))
            ->assertOk()
            ->assertSee('Coolify menüsünden', false)
            ->assertSee('/webhooks/coolify', false)
            ->assertDontSee(self::TOKEN, false)
            ->assertDontSee('name="base_url"', false);
    }

    public function test_legacy_settings_update_redirects_to_coolify_menu(): void
    {
        $this->actingAs($this->operator())
            ->post(route('ops.settings.update'), [
                'api_token' => self::TOKEN,
            ])
            ->assertRedirect(route('ops.coolify.index'));
    }

    public function test_viewer_cannot_save_legacy_settings_update(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole(OpsRole::Viewer->value);

        $this->actingAs($viewer)
            ->post(route('ops.settings.update'), [
                'api_token' => self::TOKEN,
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
