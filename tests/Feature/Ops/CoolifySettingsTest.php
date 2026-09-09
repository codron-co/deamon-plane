<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Models\CoolifySetting;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_settings_page_never_renders_stored_token(): void
    {
        CoolifySetting::factory()->create([
            'api_token' => self::TOKEN,
            'base_url' => 'https://coolify.example',
        ]);

        $operator = $this->operator();

        $this->actingAs($operator)
            ->get(route('ops.settings'))
            ->assertOk()
            ->assertSee('Coolify connection', false)
            ->assertSee('Token configured', false)
            ->assertDontSee(self::TOKEN, false);
    }

    public function test_operator_saves_token_encrypted(): void
    {
        $operator = $this->operator();

        $this->actingAs($operator)
            ->post(route('ops.settings.update'), [
                'base_url' => 'https://coolify.example/api/v1',
                'api_token' => self::TOKEN,
                'default_project_uuid' => 'proj-1',
                'default_server_uuid' => 'srv-1',
            ])
            ->assertRedirect();

        $raw = DB::table('coolify_settings')->value('api_token');
        $this->assertNotSame(self::TOKEN, $raw);
        $this->assertNotEmpty($raw);

        $settings = CoolifySetting::current();
        $this->assertSame(self::TOKEN, $settings->api_token);
        $this->assertSame('https://coolify.example', $settings->base_url);
        $this->assertArrayNotHasKey('api_token', $settings->toArray());
    }

    public function test_test_connection_uses_http_fake_and_list_servers(): void
    {
        Http::fake([
            'https://coolify.example/api/v1/servers' => Http::response([
                ['uuid' => 'srv-1', 'name' => 'localhost'],
                ['uuid' => 'srv-2', 'name' => 'edge'],
            ], 200),
        ]);

        $operator = $this->operator();

        $this->actingAs($operator)
            ->from(route('ops.settings'))
            ->post(route('ops.settings.coolify.test'), [
                'base_url' => 'https://coolify.example',
                'api_token' => self::TOKEN,
            ])
            ->assertRedirect(route('ops.settings'))
            ->assertSessionHas('status', 'Coolify connection OK — 2 server(s).');

        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://coolify.example/api/v1/servers'
                && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN);
        });
    }

    public function test_test_connection_failure_does_not_echo_token(): void
    {
        Http::fake([
            'https://coolify.example/api/v1/servers' => Http::response([
                'message' => 'Unauthorized Bearer '.self::TOKEN,
            ], 401),
        ]);

        $operator = $this->operator();

        $this->actingAs($operator)
            ->from(route('ops.settings'))
            ->post(route('ops.settings.coolify.test'), [
                'base_url' => 'https://coolify.example',
                'api_token' => self::TOKEN,
            ])
            ->assertRedirect(route('ops.settings'))
            ->assertSessionHas('error')
            ->assertSessionMissing('status');

        $error = session('error');
        $this->assertIsString($error);
        $this->assertStringNotContainsString(self::TOKEN, $error);
    }

    public function test_viewer_cannot_save_or_test_connection(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole(OpsRole::Viewer->value);

        $this->actingAs($viewer)
            ->post(route('ops.settings.update'), [
                'api_token' => self::TOKEN,
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('ops.settings.coolify.test'), [
                'api_token' => self::TOKEN,
            ])
            ->assertForbidden();
    }

    public function test_blank_token_on_save_keeps_existing_value(): void
    {
        CoolifySetting::factory()->create([
            'api_token' => self::TOKEN,
            'base_url' => 'https://coolify.example',
        ]);

        $this->actingAs($this->operator())
            ->post(route('ops.settings.update'), [
                'base_url' => 'https://coolify.example',
                'api_token' => '',
            ])
            ->assertRedirect();

        $this->assertSame(self::TOKEN, CoolifySetting::current()->api_token);
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }
}
