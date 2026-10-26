<?php

namespace Tests\Feature\Mcp;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Enums\ThemeInstallationStatus;
use App\Enums\ThemeVisibility;
use App\Mcp\Tools\RouteToolCatalog;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\McpToken;
use App\Models\RedisServer;
use App\Models\Site;
use App\Models\SiteThemeInstallation;
use App\Models\Theme;
use App\Models\User;
use App\Services\Themes\ThemeRolloutService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\TestCase;

class McpEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_endpoint_requires_a_valid_active_token(): void
    {
        $this->postJson('/mcp', $this->rpc('ping'))->assertStatus(401)->assertJsonPath('code', 'token_required');
        $this->postJson('/mcp', $this->rpc('ping'), ['Authorization' => 'Bearer plane_mcp_nope'])->assertStatus(401);

        [$token, $plain] = McpToken::issue($this->user(OpsRole::Operator), 'agent', ['read']);
        $this->rpcCall('ping', [], $plain)->assertOk()->assertJsonPath('result', []);

        $token->revoke();
        $this->postJson('/mcp', $this->rpc('ping'), ['Authorization' => 'Bearer '.$plain])->assertStatus(401);
    }

    public function test_get_is_405_and_notifications_get_202(): void
    {
        [, $plain] = McpToken::issue($this->user(OpsRole::Operator), 'agent', ['read']);

        $this->get('/mcp', ['Authorization' => 'Bearer '.$plain])->assertStatus(405);
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], ['Authorization' => 'Bearer '.$plain])
            ->assertStatus(202);
    }

    public function test_initialize_names_the_user_and_the_test_site_rule(): void
    {
        $user = $this->user(OpsRole::Operator);
        [, $plain] = McpToken::issue($user, 'agent', ['read', 'write']);

        $result = $this->rpcCall('initialize', ['protocolVersion' => '2025-06-18'], $plain)->assertOk()->json('result');

        $this->assertSame('2025-06-18', $result['protocolVersion']);
        $this->assertSame('deamon-plane', $result['serverInfo']['name']);
        $this->assertStringContainsString($user->email, $result['instructions']);
        $this->assertStringContainsString('test.deamon.codron.co', $result['instructions']);
    }

    public function test_tool_list_follows_token_abilities(): void
    {
        $user = $this->user(OpsRole::SuperAdmin);

        $names = fn (array $abilities): array => collect(
            $this->rpcCall('tools/list', [], McpToken::issue($user, 'agent', $abilities)[1])->json('result.tools'),
        )->pluck('name')->all();

        $read = $names(['read']);
        $this->assertContains('list_sites', $read);
        $this->assertNotContains('create_site', $read);

        $write = $names(['read', 'write']);
        $this->assertContains('create_site', $write);
        $this->assertContains('provision_site', $write);
        $this->assertNotContains('purge_site', $write);

        $this->assertContains('purge_site', $names(McpToken::abilityValues()));
    }

    public function test_every_route_tool_points_at_a_registered_route(): void
    {
        foreach (RouteToolCatalog::tools() as $tool) {
            $this->assertTrue(app('router')->has($tool->route()), $tool->name().' -> '.$tool->route());
        }
    }

    public function test_read_token_cannot_call_a_write_tool(): void
    {
        $site = Site::factory()->create();
        [, $plain] = McpToken::issue($this->user(OpsRole::SuperAdmin), 'agent', ['read']);

        $result = $this->tool('deploy_site', ['site' => $site->slug], $plain);

        $this->assertTrue($result['isError']);
        $this->assertSame('token_ability', $result['structuredContent']['code']);
    }

    public function test_list_and_get_site_find_it_by_domain(): void
    {
        $site = Site::factory()->create(['slug' => 'nova', 'primary_domain' => 'nova.example.test']);
        [, $plain] = McpToken::issue($this->user(OpsRole::Viewer), 'agent', ['read']);

        $list = $this->tool('list_sites', ['search' => 'nova'], $plain);
        $this->assertSame(['nova'], array_column($list['structuredContent']['sites'], 'slug'));

        $detail = $this->tool('get_site', ['site' => 'https://nova.example.test/'], $plain);
        $this->assertFalse($detail['isError']);
        $this->assertSame((string) $site->id, $detail['structuredContent']['id']);
        $this->assertArrayNotHasKey('agent_secret_encrypted', $detail['structuredContent']);
        $this->assertSame(['server' => null, 'db' => null, 'prefix' => null], $detail['structuredContent']['redis']);
    }

    public function test_get_site_shows_the_shared_redis_without_the_password(): void
    {
        $server = RedisServer::factory()->create(['name' => 'Deamon Redis']);
        Site::factory()->create(['slug' => 'nova'])->forceFill([
            'redis_server_id' => $server->id,
            'redis_db' => 4,
            'redis_username' => 'site_nova',
            'redis_password_encrypted' => 'site-redis-password-never-shown-000001',
            'redis_shared' => true,
        ])->save();
        [, $plain] = McpToken::issue($this->user(OpsRole::Viewer), 'agent', ['read']);

        $detail = $this->tool('get_site', ['site' => 'nova'], $plain);

        $this->assertSame(['server' => 'Deamon Redis', 'db' => 4, 'prefix' => 'site_nova:'], $detail['structuredContent']['redis']);
        $this->assertStringNotContainsString('site-redis-password-never-shown', (string) json_encode($detail));
    }

    public function test_create_site_runs_the_panel_store_route_with_its_validation_and_audit(): void
    {
        $user = $this->user(OpsRole::Operator);
        [, $plain] = McpToken::issue($user, 'agent', ['read', 'write']);

        $created = $this->tool('create_site', [
            'slug' => 'acme-shop',
            'name' => 'Acme Shop',
            'domain' => 'acme.example.test',
            'aliases' => ['acme-store.example.test'],
        ], $plain);

        $this->assertFalse($created['isError'], json_encode($created['structuredContent']));
        $site = Site::query()->where('slug', 'acme-shop')->firstOrFail();
        $this->assertSame(SiteStatus::Draft, $site->status);
        $this->assertSame('beta', $site->channel->value);
        $this->assertContains('acme-store.example.test', $site->operatorHosts());
        $this->assertSame('acme-shop', $created['structuredContent']['site']['slug']);
        $this->assertSame('draft', $created['structuredContent']['site']['status']);
        $this->assertTrue(AuditLog::query()->where('action', 'site.created')->where('actor_user_id', $user->id)->exists());

        $duplicate = $this->tool('create_site', ['slug' => 'acme-shop', 'name' => 'Again', 'domain' => 'other.example.test'], $plain);
        $this->assertTrue($duplicate['isError']);
        $this->assertSame('validation_error', $duplicate['structuredContent']['code']);
        $this->assertArrayHasKey('slug', $duplicate['structuredContent']['errors']);
    }

    public function test_update_site_changes_only_the_fields_passed(): void
    {
        [, $plain] = McpToken::issue($this->user(OpsRole::Operator), 'agent', ['read', 'write']);
        $this->tool('create_site', [
            'slug' => 'keep-me',
            'name' => 'Old name',
            'domain' => 'keep.example.test',
            'aliases' => ['keep-alias.example.test'],
            'notes' => 'VIP customer',
        ], $plain);

        $result = $this->tool('update_site', ['site' => 'keep-me', 'name' => 'New name'], $plain);

        $this->assertFalse($result['isError'], json_encode($result['structuredContent']));
        $site = Site::query()->where('slug', 'keep-me')->firstOrFail();
        $this->assertSame('New name', $site->name);
        $this->assertSame('VIP customer', $site->notes);
        $this->assertContains('keep-alias.example.test', $site->aliasHosts());
    }

    public function test_viewer_role_is_refused_even_with_a_write_token(): void
    {
        $site = Site::factory()->create();
        [, $plain] = McpToken::issue($this->user(OpsRole::Viewer), 'agent', ['read', 'write']);

        $result = $this->tool('set_deploy_canary', ['site' => $site->slug, 'canary' => true], $plain);

        $this->assertTrue($result['isError']);
        $this->assertSame('forbidden', $result['structuredContent']['code']);
        $this->assertFalse((bool) $site->fresh()->deploy_canary);
    }

    public function test_route_tool_returns_the_panel_flash_and_fresh_site(): void
    {
        $user = $this->user(OpsRole::Operator);
        $site = Site::factory()->create();
        [, $plain] = McpToken::issue($user, 'agent', ['read', 'write']);

        $result = $this->tool('set_deploy_canary', ['site' => $site->slug, 'canary' => true], $plain);

        $this->assertFalse($result['isError'], json_encode($result['structuredContent']));
        $this->assertNotSame('', $result['structuredContent']['message']);
        $this->assertTrue((bool) $site->fresh()->deploy_canary);
        $this->assertSame($site->slug, $result['structuredContent']['site']['slug']);
        $this->assertTrue(AuditLog::query()->where('action', 'site.deploy_canary_updated')->where('actor_user_id', $user->id)->exists());
    }

    public function test_unknown_arguments_and_unknown_sites_are_tool_errors(): void
    {
        [, $plain] = McpToken::issue($this->user(OpsRole::Operator), 'agent', ['read', 'write']);
        $site = Site::factory()->create();

        $this->assertSame('validation_error', $this->tool('deploy_site', ['site' => $site->slug, 'branch' => 'x'], $plain)['structuredContent']['code']);
        $this->assertSame('not_found', $this->tool('deploy_site', ['site' => 'nope'], $plain)['structuredContent']['code']);
    }

    public function test_assign_theme_reaches_the_rollout_service_as_the_token_user(): void
    {
        $user = $this->user(OpsRole::Operator);
        $site = Site::factory()->create(['status' => SiteStatus::Active]);
        $theme = Theme::factory()->create(['theme_id' => 'lts-bags', 'visibility' => ThemeVisibility::PublicCatalog]);
        [, $plain] = McpToken::issue($user, 'agent', ['read', 'write']);

        $this->mock(ThemeRolloutService::class, function (MockInterface $mock) use ($site, $theme, $user): void {
            $mock->shouldReceive('assign')->once()->withArgs(
                fn (Site $s, Theme $t, ?User $actor, ?string $ip, array $options): bool => $s->is($site) && $t->is($theme)
                    && $actor?->is($user) && $options['activate'] === true && $options['ref'] === 'v1.2.0',
            );
        });

        $result = $this->tool('assign_theme', ['site' => $site->slug, 'theme_id' => 'lts-bags', 'ref' => 'v1.2.0', 'activate' => true], $plain);

        $this->assertFalse($result['isError'], json_encode($result['structuredContent']));
    }

    public function test_bulk_all_needs_a_danger_token(): void
    {
        Site::factory()->create();
        [, $plain] = McpToken::issue($this->user(OpsRole::SuperAdmin), 'agent', ['read', 'write']);

        $result = $this->tool('bulk_sites', ['action' => 'deploy', 'all' => true], $plain);

        $this->assertTrue($result['isError']);
        $this->assertSame('forbidden', $result['structuredContent']['code']);
    }

    public function test_update_theme_fleet_dry_run_lists_sites_behind_the_catalog(): void
    {
        [, $plain] = McpToken::issue($this->user(OpsRole::Operator), 'agent', ['read', 'write']);
        $theme = Theme::factory()->create(['theme_id' => 'premium', 'latest_sha' => str_repeat('b', 40)]);
        $behind = Site::factory()->create(['slug' => 'behind']);
        $current = Site::factory()->create(['slug' => 'current']);
        foreach ([[$behind, str_repeat('a', 40)], [$current, str_repeat('b', 40)]] as [$site, $sha]) {
            SiteThemeInstallation::factory()->create([
                'site_id' => $site->id,
                'theme_id' => $theme->id,
                'pinned_sha' => $sha,
                'is_active' => true,
                'status' => ThemeInstallationStatus::Active,
            ]);
        }

        $result = $this->tool('update_theme_fleet', ['theme_id' => 'premium'], $plain)['structuredContent'];

        $this->assertTrue($result['dry_run']);
        $this->assertSame(['behind'], array_column($result['sites'], 'site'));

        // Single-site tools find the install by the catalog code, not the theme row id.
        $this->mock(ThemeRolloutService::class, function (MockInterface $mock) use ($behind): void {
            $mock->shouldReceive('updateToLatest')->once()->withArgs(
                fn (SiteThemeInstallation $row): bool => $row->site_id === $behind->id,
            );
        });

        $update = $this->tool('update_theme', ['site' => 'behind', 'theme_id' => 'premium'], $plain);
        $this->assertFalse($update['isError'], json_encode($update['structuredContent']));

        $detail = $this->tool('get_site', ['site' => 'behind'], $plain)['structuredContent'];
        $this->assertSame('premium', $detail['themes'][0]['theme_id']);
    }

    public function test_read_tools_answer_without_errors(): void
    {
        $site = Site::factory()->create(['slug' => 'smoke']);
        Deployment::factory()->create(['site_id' => $site->id]);
        [, $plain] = McpToken::issue($this->user(OpsRole::Operator), 'agent', ['read']);

        foreach ([
            'whoami' => [],
            'list_sites' => ['unhealthy' => false, 'failed_deploy' => false],
            'get_site' => ['site' => 'smoke'],
            'get_setup_options' => [],
            'list_themes' => [],
            'list_jobs' => ['active_only' => true],
            'get_deployment' => ['site' => 'smoke'],
            'list_site_activity' => ['site' => 'smoke'],
        ] as $tool => $arguments) {
            $result = $this->tool($tool, $arguments, $plain);
            $this->assertFalse($result['isError'], $tool.': '.json_encode($result['structuredContent']));
        }
    }

    public function test_prompt_launch_site_fills_in_the_domain(): void
    {
        [, $plain] = McpToken::issue($this->user(OpsRole::Operator), 'agent', ['read']);

        $prompt = $this->rpcCall('prompts/get', ['name' => 'launch_site', 'arguments' => ['domain' => 'acme.com', 'name' => 'Acme']], $plain)->json('result');

        $this->assertStringContainsString('acme.com', $prompt['messages'][0]['content']['text']);
    }

    public function test_token_command_prints_a_working_token(): void
    {
        $user = $this->user(OpsRole::Operator);

        $this->artisan('ops:mcp-token', ['action' => 'create', 'subject' => $user->email, '--ability' => 'write'])
            ->expectsOutputToContain('Shown once')
            ->assertSuccessful();

        $token = McpToken::query()->firstOrFail();
        $this->assertSame(['read', 'write'], $token->abilities);
        $this->assertSame(64, strlen($token->token_hash));
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function rpc(string $method, array $params = []): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params === [] ? new \stdClass : $params];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function rpcCall(string $method, array $params, string $token): TestResponse
    {
        return $this->postJson('/mcp', $this->rpc($method, $params), ['Authorization' => 'Bearer '.$token]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{isError: bool, structuredContent: array<string, mixed>}
     */
    private function tool(string $name, array $arguments, string $token): array
    {
        return $this->rpcCall('tools/call', ['name' => $name, 'arguments' => $arguments], $token)->assertOk()->json('result');
    }
}
