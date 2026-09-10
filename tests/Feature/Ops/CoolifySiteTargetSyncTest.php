<?php

namespace Tests\Feature\Ops;

use App\Enums\Channel;
use App\Enums\CoolifyGitSourceKind;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CoolifyConnection;
use App\Models\CoolifyServer;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CoolifySiteTargetSyncTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'plane-coolify-connection-token';

    private const APP = 'app-fill-1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_sync_fills_site_targets_from_get_application_without_touching_secrets_or_status(): void
    {
        $connection = $this->connection();
        CoolifyServer::query()->create([
            'coolify_connection_id' => $connection->id,
            'uuid' => 'no48ksggg0k8sk4o4w08gks8',
            'name' => 'localhost',
            'ip' => '127.0.0.1',
            'is_active' => false,
        ]);

        $site = Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'channel' => Channel::Main,
            'channel_needs_review' => true,
            'coolify_app_uuid' => self::APP,
            'coolify_connection_id' => $connection->id,
            'coolify_project_uuid' => null,
            'coolify_environment_uuid' => null,
            'coolify_server_uuid' => null,
            'coolify_git_source_uuid' => null,
            'coolify_git_source_kind' => null,
            'git_repository' => 'https://github.com/codron-co/deamon.git',
        ]);
        $appKey = $site->app_key_encrypted;
        $agentSecret = $site->agent_secret_encrypted;

        Http::fake($this->inventoryFake([
            'https://coolify.example/api/v1/applications/'.self::APP => Http::response($this->applicationPayload('beta'), 200),
        ]));

        $this->actingAs($this->operator())
            ->from(route('ops.coolify.show', $connection))
            ->post(route('ops.coolify.sync', $connection))
            ->assertRedirect(route('ops.coolify.show', $connection))
            ->assertSessionHas('status', $this->syncFlash(sites: 1));

        $site->refresh();
        $this->assertSame('z8ocg8k04ww8osssccc088c0', $site->coolify_project_uuid);
        $this->assertSame('sns276euzsz2fprqg3xgfz17', $site->coolify_environment_uuid);
        $this->assertSame('no48ksggg0k8sk4o4w08gks8', $site->coolify_server_uuid);
        $this->assertSame('r08cws800oow8w008880c8og', $site->coolify_git_source_uuid);
        $this->assertSame(CoolifyGitSourceKind::GithubApp, $site->coolify_git_source_kind);
        $this->assertSame('https://github.com/codron-co/deamon.git', $site->git_repository);
        $this->assertSame(Channel::Beta, $site->channel);
        $this->assertFalse($site->channel_needs_review);
        $this->assertSame(SiteStatus::Active, $site->status);
        $this->assertSame($appKey, $site->app_key_encrypted);
        $this->assertSame($agentSecret, $site->agent_secret_encrypted);

        $this->assertFalse(
            CoolifyServer::query()->where('uuid', 'no48ksggg0k8sk4o4w08gks8')->value('is_active'),
        );

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_ends_with($request->url(), '/applications/'.self::APP));
    }

    public function test_non_allowlisted_branch_sets_review_and_keeps_channel(): void
    {
        $connection = $this->connection();
        $site = Site::factory()->create([
            'status' => SiteStatus::Active,
            'channel' => Channel::Alpha,
            'channel_needs_review' => false,
            'coolify_app_uuid' => self::APP,
            'coolify_connection_id' => $connection->id,
        ]);

        Http::fake($this->inventoryFake([
            'https://coolify.example/api/v1/applications/'.self::APP => Http::response($this->applicationPayload('develop'), 200),
        ]));

        $this->actingAs($this->operator())
            ->post(route('ops.coolify.sync', $connection))
            ->assertRedirect();

        $site->refresh();
        $this->assertSame(Channel::Alpha, $site->channel);
        $this->assertTrue($site->channel_needs_review);
        $this->assertSame('z8ocg8k04ww8osssccc088c0', $site->coolify_project_uuid);
    }

    public function test_application_404_skips_site_and_does_not_delete_it(): void
    {
        $connection = $this->connection();
        $site = Site::factory()->create([
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => 'gone-app',
            'coolify_connection_id' => $connection->id,
            'coolify_project_uuid' => null,
        ]);

        Http::fake($this->inventoryFake([
            'https://coolify.example/api/v1/applications/gone-app' => Http::response(['message' => 'Not found'], 404),
        ]));

        $this->actingAs($this->operator())
            ->post(route('ops.coolify.sync', $connection))
            ->assertRedirect()
            ->assertSessionHas('status', $this->syncFlash(sites: 0));

        $this->assertDatabaseHas('sites', [
            'id' => $site->id,
            'coolify_app_uuid' => 'gone-app',
            'coolify_project_uuid' => null,
            'status' => SiteStatus::Active->value,
        ]);
    }

    public function test_other_connection_sites_are_not_fetched(): void
    {
        $ours = $this->connection();
        $theirs = CoolifyConnection::factory()->create([
            'name' => 'Other',
            'base_url' => 'https://coolify-other.example',
            'api_token' => 'other-token',
            'is_default' => false,
        ]);
        Site::factory()->create([
            'coolify_app_uuid' => 'their-app',
            'coolify_connection_id' => $theirs->id,
            'coolify_project_uuid' => null,
        ]);

        Http::fake($this->inventoryFake());

        $this->actingAs($this->operator())
            ->post(route('ops.coolify.sync', $ours))
            ->assertRedirect();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'their-app'));
        $this->assertDatabaseHas('sites', [
            'coolify_app_uuid' => 'their-app',
            'coolify_project_uuid' => null,
        ]);
    }

    public function test_default_connection_fills_sites_with_null_connection_id(): void
    {
        $connection = $this->connection();
        $site = Site::factory()->create([
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => self::APP,
            'coolify_connection_id' => null,
        ]);

        Http::fake($this->inventoryFake([
            'https://coolify.example/api/v1/applications/'.self::APP => Http::response($this->applicationPayload('alpha'), 200),
        ]));

        $this->actingAs($this->operator())
            ->post(route('ops.coolify.sync', $connection))
            ->assertRedirect();

        $site->refresh();
        $this->assertSame($connection->id, $site->coolify_connection_id);
        $this->assertSame(Channel::Alpha, $site->channel);
        $this->assertSame('z8ocg8k04ww8osssccc088c0', $site->coolify_project_uuid);
    }

    public function test_provisioning_site_does_not_change_channel(): void
    {
        $connection = $this->connection();
        $site = Site::factory()->create([
            'status' => SiteStatus::Provisioning,
            'channel' => Channel::Main,
            'channel_needs_review' => false,
            'coolify_app_uuid' => self::APP,
            'coolify_connection_id' => $connection->id,
        ]);

        Http::fake($this->inventoryFake([
            'https://coolify.example/api/v1/applications/'.self::APP => Http::response($this->applicationPayload('beta'), 200),
        ]));

        $this->actingAs($this->operator())
            ->post(route('ops.coolify.sync', $connection))
            ->assertRedirect();

        $site->refresh();
        $this->assertSame(SiteStatus::Provisioning, $site->status);
        $this->assertSame(Channel::Main, $site->channel);
        $this->assertFalse($site->channel_needs_review);
        $this->assertSame('z8ocg8k04ww8osssccc088c0', $site->coolify_project_uuid);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function inventoryFake(array $extra = []): array
    {
        return $extra + [
            'https://coolify.example/api/v1/servers' => Http::response([
                ['uuid' => 'no48ksggg0k8sk4o4w08gks8', 'name' => 'localhost', 'ip' => 'host.docker.internal'],
            ], 200),
            'https://coolify.example/api/v1/projects' => Http::response([
                ['uuid' => 'z8ocg8k04ww8osssccc088c0', 'name' => 'Deamon'],
            ], 200),
            'https://coolify.example/api/v1/projects/z8ocg8k04ww8osssccc088c0/environments' => Http::response([
                ['uuid' => 'sns276euzsz2fprqg3xgfz17', 'name' => 'alpha'],
            ], 200),
            'https://coolify.example/api/v1/github-apps' => Http::response([
                ['uuid' => 'r08cws800oow8w008880c8og', 'name' => 'codron'],
            ], 200),
            'https://coolify.example/api/v1/security/keys' => Http::response([
                ['uuid' => 'pk-1', 'name' => 'deploy'],
            ], 200),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function applicationPayload(string $branch): array
    {
        return [
            'uuid' => self::APP,
            'git_branch' => $branch,
            'git_repository' => 'https://github.com/codron-co/deamon.git',
            'github_app_uuid' => 'r08cws800oow8w008880c8og',
            'destination' => [
                'server' => ['uuid' => 'no48ksggg0k8sk4o4w08gks8'],
            ],
            'environment' => [
                'uuid' => 'sns276euzsz2fprqg3xgfz17',
                'project' => ['uuid' => 'z8ocg8k04ww8osssccc088c0'],
            ],
        ];
    }

    private function syncFlash(int $sites): string
    {
        return __('coolify.flash.sync', [
            'servers' => 1,
            'projects' => 1,
            'environments' => 1,
            'git' => 2,
            'sites' => $sites,
        ]);
    }

    private function connection(): CoolifyConnection
    {
        return CoolifyConnection::factory()->create([
            'name' => 'Prod',
            'base_url' => 'https://coolify.example',
            'api_token' => self::TOKEN,
            'is_default' => true,
        ]);
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }
}
