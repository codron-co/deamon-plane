<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Models\CoolifyConnection;
use App\Models\CoolifyGitSource;
use App\Models\CoolifyServer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CoolifyConnectionsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'plane-coolify-connection-token';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_nav_includes_coolify_and_operator_can_open_index(): void
    {
        $html = $this->actingAs($this->operator())
            ->get(route('ops.coolify.index'))
            ->assertOk()
            ->assertSee('Coolify', false)
            ->assertSee('Bağlantı ekle', false)
            ->getContent();

        $this->assertStringContainsString(route('ops.coolify.index'), $html);
    }

    public function test_operator_creates_connection_token_encrypted_never_rendered(): void
    {
        $this->actingAs($this->operator())
            ->post(route('ops.coolify.store'), [
                'name' => 'Prod Coolify',
                'base_url' => 'https://coolify.example/api/v1',
                'api_token' => self::TOKEN,
                'is_enabled' => '1',
                'is_default' => '1',
            ])
            ->assertRedirect();

        $connection = CoolifyConnection::query()->first();
        $this->assertNotNull($connection);
        $this->assertSame('https://coolify.example', $connection->base_url);
        $this->assertTrue($connection->is_default);
        $this->assertSame(self::TOKEN, $connection->api_token);
        $this->assertNotSame(self::TOKEN, DB::table('coolify_connections')->value('api_token'));
        $this->assertArrayNotHasKey('api_token', $connection->toArray());

        $html = $this->actingAs($this->operator())
            ->get(route('ops.coolify.show', $connection))
            ->assertOk()
            ->assertSee('Token kayıtlı', false)
            ->assertSee('>Sync<', false)
            ->assertDontSee(self::TOKEN, false)
            ->getContent();

        $this->assertStringContainsString('method="POST"', $html);
        $this->assertStringContainsString(route('ops.coolify.sync', $connection), $html);
        $this->assertDoesNotMatchRegularExpression(
            '/<a\b[^>]*\bhref="[^"]*\/coolify\/\d+\/sync"/i',
            $html,
        );
    }

    public function test_get_sync_redirects_to_show_and_does_not_call_coolify(): void
    {
        Http::fake();

        $connection = CoolifyConnection::factory()->create([
            'api_token' => self::TOKEN,
        ]);

        $this->actingAs($this->operator())
            ->get(route('ops.coolify.sync.get', $connection))
            ->assertRedirect(route('ops.coolify.show', $connection))
            ->assertSessionHas('status');

        Http::assertNothingSent();
    }

    public function test_test_connection_and_sync_persist_servers_projects_envs_git(): void
    {
        Http::fake([
            'https://coolify.example/api/v1/servers' => Http::response([
                ['uuid' => 'no48ksggg0k8sk4o4w08gks8', 'name' => 'localhost'],
                ['uuid' => 'edge-1', 'name' => 'edge'],
            ], 200),
            'https://coolify.example/api/v1/projects' => Http::response([
                ['uuid' => 'z8ocg8k04ww8osssccc088c0', 'name' => 'Deamon'],
            ], 200),
            'https://coolify.example/api/v1/projects/z8ocg8k04ww8osssccc088c0/environments' => Http::response([
                ['uuid' => 'env-prod', 'name' => 'production'],
            ], 200),
            'https://coolify.example/api/v1/github-apps' => Http::response([
                ['uuid' => 'gh-app-1', 'name' => 'codron'],
            ], 200),
            'https://coolify.example/api/v1/security/keys' => Http::response([
                ['uuid' => 'pk-1', 'name' => 'deploy'],
            ], 200),
        ]);

        $connection = CoolifyConnection::factory()->create([
            'name' => 'Prod',
            'base_url' => 'https://coolify.example',
            'api_token' => self::TOKEN,
            'is_default' => true,
        ]);

        $this->actingAs($this->operator())
            ->from(route('ops.coolify.show', $connection))
            ->post(route('ops.coolify.test', $connection), [
                'base_url' => 'https://coolify.example',
            ])
            ->assertRedirect(route('ops.coolify.show', $connection))
            ->assertSessionHas('status');

        $this->actingAs($this->operator())
            ->from(route('ops.coolify.show', $connection))
            ->post(route('ops.coolify.sync', $connection))
            ->assertRedirect(route('ops.coolify.show', $connection))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('coolify_servers', [
            'coolify_connection_id' => $connection->id,
            'uuid' => 'no48ksggg0k8sk4o4w08gks8',
            'is_active' => 1,
        ]);
        $this->assertDatabaseHas('coolify_projects', [
            'uuid' => 'z8ocg8k04ww8osssccc088c0',
        ]);
        $this->assertDatabaseHas('coolify_environments', [
            'uuid' => 'env-prod',
            'project_uuid' => 'z8ocg8k04ww8osssccc088c0',
        ]);
        $this->assertDatabaseHas('coolify_git_sources', [
            'uuid' => 'gh-app-1',
            'kind' => 'github_app',
        ]);

        $server = CoolifyServer::query()->where('uuid', 'edge-1')->first();
        $this->assertNotNull($server);

        $this->actingAs($this->operator())
            ->post(route('ops.coolify.servers.toggle', [$connection, $server]))
            ->assertRedirect();

        $this->assertFalse($server->fresh()->is_active);
    }

    public function test_github_apps_404_is_hybrid_and_deploy_keys_still_sync(): void
    {
        Http::fake([
            'https://coolify.example/api/v1/servers' => Http::response([], 200),
            'https://coolify.example/api/v1/projects' => Http::response([], 200),
            'https://coolify.example/api/v1/github-apps' => Http::response(['message' => 'Not found'], 404),
            'https://coolify.example/api/v1/security/keys' => Http::response([
                ['uuid' => 'pk-1', 'name' => 'deploy'],
            ], 200),
        ]);

        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => self::TOKEN,
        ]);

        $this->actingAs($this->operator())
            ->post(route('ops.coolify.sync', $connection))
            ->assertRedirect()
            ->assertSessionHas('status');

        $connection->refresh();
        $this->assertFalse($connection->github_apps_list_available);
        $this->assertSame(1, CoolifyGitSource::query()->count());
    }

    public function test_viewer_is_read_only(): void
    {
        $connection = CoolifyConnection::factory()->create([
            'api_token' => self::TOKEN,
        ]);
        $viewer = User::factory()->create();
        $viewer->assignRole(OpsRole::Viewer->value);

        $this->actingAs($viewer)->get(route('ops.coolify.index'))->assertOk();
        $this->actingAs($viewer)->get(route('ops.coolify.show', $connection))->assertOk();
        $this->actingAs($viewer)->post(route('ops.coolify.store'), [
            'name' => 'x',
            'base_url' => 'https://coolify.example',
            'api_token' => self::TOKEN,
        ])->assertForbidden();
        $this->actingAs($viewer)->post(route('ops.coolify.sync', $connection))->assertForbidden();
        $this->actingAs($viewer)->delete(route('ops.coolify.destroy', $connection))->assertForbidden();
    }

    public function test_disconnect_uses_confirm_and_does_not_call_coolify_delete(): void
    {
        Http::fake();

        $connection = CoolifyConnection::factory()->create(['name' => 'Staging']);

        $html = $this->actingAs($this->operator())
            ->get(route('ops.coolify.show', $connection))
            ->assertOk()
            ->assertSee('Bağlantıyı kes', false)
            ->assertSee('data-confirm=', false)
            ->getContent();

        $this->assertStringNotContainsString('window.confirm', $html);

        $this->actingAs($this->operator())
            ->delete(route('ops.coolify.destroy', $connection))
            ->assertRedirect(route('ops.coolify.index'));

        $this->assertDatabaseMissing('coolify_connections', ['id' => $connection->id]);
        Http::assertNothingSent();
    }

    public function test_second_default_unsets_the_first(): void
    {
        $first = CoolifyConnection::factory()->create(['name' => 'A', 'is_default' => true]);
        $second = CoolifyConnection::factory()->create(['name' => 'B', 'is_default' => false]);

        $this->actingAs($this->operator())
            ->post(route('ops.coolify.default', $second))
            ->assertRedirect();

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }
}
