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
            ->assertSee(__('coolify.add'), false)
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
            ->assertSee(__('coolify.fields.token_saved'), false)
            ->assertSee('>'.__('coolify.show.sync').'<', false)
            ->assertDontSee(self::TOKEN, false)
            ->getContent();

        $this->assertStringContainsString('method="POST"', $html);
        $this->assertStringContainsString(route('ops.coolify.sync', $connection), $html);
        $this->assertDoesNotMatchRegularExpression(
            '/<a\b[^>]*\bhref="[^"]*\/coolify\/\d+\/sync"/i',
            $html,
        );
    }

    public function test_connection_show_renders_allowlist_without_ending_parent_section(): void
    {
        $connection = CoolifyConnection::factory()->create([
            'api_token' => self::TOKEN,
            'name' => 'Prod Coolify',
        ]);
        $server = CoolifyServer::query()->create([
            'coolify_connection_id' => $connection->id,
            'uuid' => 'edge-1',
            'name' => 'edge',
            'ip' => '1.2.3.4',
            'is_active' => true,
        ]);

        $this->actingAs($this->operator())
            ->get(route('ops.coolify.show', $connection))
            ->assertOk()
            ->assertSee('edge', false)
            ->assertSee('1.2.3.4', false)
            ->assertSee(__('coolify.allowlist.servers'), false)
            ->assertSee('data-href="'.route('ops.coolify.servers.show', [$connection, $server]).'"', false);
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

        $this->actingAs($this->operator())
            ->get(route('ops.coolify.sync', $connection))
            ->assertStatus(302)
            ->assertRedirect(route('ops.coolify.show', $connection));

        Http::assertNothingSent();
    }

    public function test_test_connection_and_sync_persist_servers_projects_envs_git(): void
    {
        Http::fake([
            'https://coolify.example/api/v1/servers' => Http::response([
                ['uuid' => 'no48ksggg0k8sk4o4w08gks8', 'name' => 'localhost', 'ip' => 'host.docker.internal'],
                ['uuid' => 'edge-1', 'name' => 'edge', 'ip' => '10.0.0.8', 'public_ip' => '198.51.100.12'],
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
            'ip' => 'host.docker.internal',
            'is_active' => 1,
        ]);
        $this->assertDatabaseHas('coolify_servers', [
            'coolify_connection_id' => $connection->id,
            'uuid' => 'edge-1',
            'ip' => '198.51.100.12',
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
        $this->assertSame('198.51.100.12', $server->ip);

        $this->actingAs($this->operator())
            ->get(route('ops.coolify.show', $connection))
            ->assertOk()
            ->assertSee('>IP<', false)
            ->assertSee('host.docker.internal', false)
            ->assertSee('198.51.100.12', false);

        $this->actingAs($this->operator())
            ->post(route('ops.coolify.servers.toggle', [$connection, $server]))
            ->assertRedirect();

        $this->assertFalse($server->fresh()->is_active);
    }

    public function test_sync_two_github_apps_auto_defaults_localhost_and_scopes_envs_to_project(): void
    {
        Http::fake([
            'https://coolify.example/api/v1/servers' => Http::response([
                ['uuid' => 'no48ksggg0k8sk4o4w08gks8', 'name' => 'localhost'],
            ], 200),
            'https://coolify.example/api/v1/projects' => Http::response([
                ['uuid' => 'z8ocg8k04ww8osssccc088c0', 'name' => 'Deamon'],
                ['uuid' => 'other-proj', 'name' => 'Web Apps'],
            ], 200),
            'https://coolify.example/api/v1/projects/z8ocg8k04ww8osssccc088c0/environments' => Http::response([
                ['uuid' => 'i0sw4kk0cogg4o08oscwcssk', 'name' => 'production'],
                ['uuid' => 'sns276euzsz2fprqg3xgfz17', 'name' => 'alpha'],
            ], 200),
            'https://coolify.example/api/v1/projects/other-proj/environments' => Http::response([
                ['uuid' => 'rw8flmmfzxkp0qnklzzi8lny', 'name' => 'plane'],
                ['uuid' => 'is8kkgcg0g8ss40gswg484cg', 'name' => 'production'],
            ], 200),
            'https://coolify.example/api/v1/github-apps' => Http::response([
                [
                    'uuid' => 'r08cws800oow8w008880c8og',
                    'name' => 'coolify-github-codronco',
                    'organization' => 'codron-co',
                ],
                [
                    'uuid' => 'x1gny2ozb9pzngjsm9a0z7jn',
                    'name' => 'coolify-github-eminwhocodes',
                    'organization' => '',
                ],
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
            'default_server_uuid' => null,
            'default_project_uuid' => 'z8ocg8k04ww8osssccc088c0',
            'default_environment_uuid' => null,
        ]);

        $this->actingAs($this->operator())
            ->from(route('ops.coolify.show', $connection))
            ->post(route('ops.coolify.sync', $connection))
            ->assertRedirect(route('ops.coolify.show', $connection));

        $connection->refresh();
        $this->assertSame('no48ksggg0k8sk4o4w08gks8', $connection->default_server_uuid);
        $this->assertSame('z8ocg8k04ww8osssccc088c0', $connection->default_project_uuid);
        $this->assertSame(2, CoolifyGitSource::query()->where('kind', 'github_app')->count());
        $this->assertDatabaseHas('coolify_environments', [
            'uuid' => 'i0sw4kk0cogg4o08oscwcssk',
            'project_uuid' => 'z8ocg8k04ww8osssccc088c0',
            'name' => 'production',
        ]);
        $this->assertDatabaseHas('coolify_environments', [
            'uuid' => 'rw8flmmfzxkp0qnklzzi8lny',
            'project_uuid' => 'other-proj',
            'name' => 'plane',
        ]);

        $html = $this->actingAs($this->operator())
            ->get(route('ops.coolify.show', $connection))
            ->assertOk()
            ->assertSee('localhost', false)
            ->assertSee('Deamon', false)
            ->assertSee('coolify-github-codronco / codron-co', false)
            ->assertSee('coolify-github-eminwhocodes', false)
            ->assertDontSee('GitHub App: GitHub App', false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/id="default-env"[^>]*>.*value="i0sw4kk0cogg4o08oscwcssk"[^>]*>\s*production/s',
            $html,
        );
        $this->assertStringNotContainsString('value="rw8flmmfzxkp0qnklzzi8lny"', $html);
        $this->assertStringNotContainsString('value="is8kkgcg0g8ss40gswg484cg"', $html);
        $this->assertStringNotContainsString('z8ocg8k04ww8osssccc088c0</option>', $html);
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
        $this->actingAs($viewer)
            ->get(route('ops.coolify.show', $connection))
            ->assertOk()
            ->assertDontSee(__('coolify.danger.button'), false)
            ->assertDontSee('aria-controls="danger"', false);
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
            ->assertSee(__('coolify.danger.button'), false)
            ->assertSee('data-confirm=', false)
            ->getContent();

        $this->assertStringNotContainsString('window.confirm', $html);

        $this->actingAs($this->operator())
            ->delete(route('ops.coolify.destroy', $connection))
            ->assertRedirect(route('ops.coolify.index'));

        $this->assertDatabaseMissing('coolify_connections', ['id' => $connection->id]);
        Http::assertNothingSent();
    }

    public function test_index_rows_open_show_not_edit(): void
    {
        $connection = CoolifyConnection::factory()->create(['name' => 'Prod Coolify']);

        $html = $this->actingAs($this->operator())
            ->get(route('ops.coolify.index'))
            ->assertOk()
            ->assertSee('data-href="'.route('ops.coolify.show', $connection).'"', false)
            ->getContent();

        $this->assertStringNotContainsString('/coolify/'.$connection->id.'/edit', $html);
    }

    public function test_show_uses_resource_tabs_and_keeps_coolify_select_names(): void
    {
        $connection = CoolifyConnection::factory()->create([
            'api_token' => self::TOKEN,
            'name' => 'Prod Coolify',
        ]);

        $html = $this->actingAs($this->operator())
            ->get(route('ops.coolify.show', $connection))
            ->assertOk()
            ->assertSee('role="tablist"', false)
            ->assertSee('aria-controls="overview"', false)
            ->assertSee('aria-controls="inventory"', false)
            ->assertSee('aria-controls="configuration"', false)
            ->assertSee('name="default_server_uuid"', false)
            ->assertSee('name="default_project_uuid"', false)
            ->assertSee('name="default_environment_uuid"', false)
            ->assertSee('name="default_git_source"', false)
            ->assertSee('data-coolify-servers', false)
            ->assertSee('data-coolify-projects', false)
            ->assertSee('data-coolify-environments', false)
            ->assertSee('type="password"', false)
            ->assertSee('class="site-hint"', false)
            ->assertSee('class="site-technical-card"', false)
            ->assertSee('data-confirm="'.__('coolify.show.sync_confirm', ['name' => $connection->name]).'"', false)
            ->assertDontSee(self::TOKEN, false)
            ->getContent();

        $this->assertMatchesRegularExpression('/name="api_token"[^>]*value=""/', $html);
        $this->assertStringContainsString('ops-coolify-form.js', $html);
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
