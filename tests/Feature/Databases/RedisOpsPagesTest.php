<?php

namespace Tests\Feature\Databases;

use App\Enums\OpsRole;
use App\Models\RedisServer;
use App\Models\Site;
use App\Models\User;
use App\Services\Databases\RedisServerClient;
use App\Services\Databases\RedisServerException;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RedisOpsPagesTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_PASSWORD = 'redis-admin-secret-never-show';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_index_lists_redis_servers_with_memory_and_evictions(): void
    {
        $server = RedisServer::factory()->default()->create([
            'name' => 'Deamon Redis',
            'admin_password' => self::ADMIN_PASSWORD,
            'last_probe_at' => now(),
            'last_probe_payload' => [
                'version' => '7.4.1', 'uptime_seconds' => 60, 'connected_clients' => 37,
                'used_memory' => 64 * 1048576, 'maxmemory' => 1024 * 1048576, 'evicted_keys' => 3, 'keyspace' => [],
            ],
        ]);
        $this->site($server, 'izyem', 2, shared: true);
        $this->site($server, 'reserved', 4, shared: false);

        $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.databases.index'))
            ->assertOk()
            ->assertSee(__('databases.redis.title'))
            ->assertSee('Deamon Redis')
            ->assertSee('64.0 / 1,024 MB')
            ->assertSeeInOrder(['37', '3', '1'])
            ->assertDontSee(self::ADMIN_PASSWORD)
            ->assertDontSee(route('ops.databases.redis.probe', $server));
    }

    public function test_refresh_needs_ops_write(): void
    {
        $server = RedisServer::factory()->create();

        $this->actingAs($this->user(OpsRole::Viewer))
            ->post(route('ops.databases.redis.probe', $server))
            ->assertForbidden();

        $this->mock(RedisServerClient::class)->shouldReceive('refresh')->once()->andReturnUsing(fn (RedisServer $s) => $s);
        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.databases.redis.probe', $server))
            ->assertRedirect()
            ->assertSessionHas('status');
    }

    public function test_refresh_failure_is_shown_without_the_password(): void
    {
        $server = RedisServer::factory()->create(['admin_password' => self::ADMIN_PASSWORD]);
        $this->mock(RedisServerClient::class)->shouldReceive('refresh')
            ->andThrow(new RedisServerException('WRONGPASS '.self::ADMIN_PASSWORD, [self::ADMIN_PASSWORD]));

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.databases.redis.probe', $server))
            ->assertSessionHas('error', fn (string $message): bool => ! str_contains($message, self::ADMIN_PASSWORD) && str_contains($message, '[redacted]'));
    }

    public function test_site_card_shows_redis_placement(): void
    {
        $server = RedisServer::factory()->create(['name' => 'Deamon Redis']);

        $local = Site::factory()->create();
        $this->view('ops.sites._database', ['site' => $local])
            ->assertSee(__('databases.redis.site.local'));

        $shared = $this->site($server, 'izyem', 6, shared: true);
        $this->view('ops.sites._database', ['site' => $shared])
            ->assertSee(__('databases.redis.site.shared', ['server' => 'Deamon Redis', 'db' => 6, 'cache_db' => 7]));
    }

    private function site(RedisServer $server, string $slug, int $db, bool $shared): Site
    {
        $site = Site::factory()->create(['slug' => $slug]);
        $site->forceFill([
            'redis_server_id' => $server->id,
            'redis_db' => $db,
            'redis_username' => 'site_'.$slug,
            'redis_password_encrypted' => 'pw-'.$slug,
            'redis_shared' => $shared,
        ])->save();

        return $site->refresh();
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
