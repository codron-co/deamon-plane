<?php

namespace Tests\Feature\Databases;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\DatabaseServer;
use App\Models\Site;
use App\Models\User;
use App\Services\Databases\DatabaseQueryResult;
use App\Services\Databases\DatabaseServerClient;
use App\Services\Databases\DatabaseServerException;
use App\Services\Databases\SqlStatementRejected;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DatabaseOpsPagesTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_PASSWORD = 'admin-secret-never-show';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_index_lists_servers_without_the_admin_password(): void
    {
        DatabaseServer::factory()->default()->create(['name' => 'Deamon MySQL', 'admin_password' => self::ADMIN_PASSWORD]);

        $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.databases.index'))
            ->assertOk()
            ->assertSee('Deamon MySQL')
            ->assertDontSee(self::ADMIN_PASSWORD);
    }

    public function test_only_super_admin_adds_a_server(): void
    {
        $payload = [
            'name' => 'Deamon MySQL', 'host' => 'deamon-db-mysql', 'port' => 3306,
            'admin_username' => 'plane_admin', 'admin_password' => self::ADMIN_PASSWORD, 'is_default' => '1',
        ];

        $this->actingAs($this->user(OpsRole::Operator))->post(route('ops.databases.store'), $payload)->assertForbidden();

        $this->mock(DatabaseServerClient::class)->shouldReceive('refresh')->once()->andReturnUsing(fn (DatabaseServer $s) => $s);
        $this->actingAs($this->user(OpsRole::SuperAdmin))->post(route('ops.databases.store'), $payload)->assertRedirect();

        $server = DatabaseServer::query()->sole();
        $this->assertTrue($server->is_default);
        $this->assertSame(self::ADMIN_PASSWORD, $server->admin_password);
        $this->assertNotSame(self::ADMIN_PASSWORD, $server->getRawOriginal('admin_password'));
    }

    public function test_server_with_site_databases_cannot_be_deleted(): void
    {
        $server = DatabaseServer::factory()->create();
        $this->sharedSite($server);

        $this->actingAs($this->user(OpsRole::SuperAdmin))
            ->delete(route('ops.databases.destroy', $server))
            ->assertSessionHas('error');

        $this->assertModelExists($server);
    }

    public function test_local_site_page_explains_how_to_move(): void
    {
        $site = Site::factory()->create(['status' => SiteStatus::Active]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.database', $site))
            ->assertOk()
            ->assertSee(__('databases.site.local'));
    }

    public function test_viewer_cannot_open_site_data(): void
    {
        $site = $this->sharedSite(DatabaseServer::factory()->create());

        $this->actingAs($this->user(OpsRole::Viewer))->get(route('ops.sites.database', $site))->assertForbidden();
    }

    public function test_read_query_renders_rows_and_is_audited(): void
    {
        $site = $this->sharedSite(DatabaseServer::factory()->create());
        $client = $this->mock(DatabaseServerClient::class);
        $client->shouldReceive('tables')->andReturn([['name' => 'users', 'rows' => 3, 'size_mb' => 0.02, 'engine' => 'InnoDB']]);
        $client->shouldReceive('run')->once()->withArgs(fn (Site $s, string $sql, bool $write) => ! $write)
            ->andReturn(new DatabaseQueryResult('read', ['id', 'email'], [['1', 'a@example.com']], false, null, 4));

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.database.query', $site), ['sql' => 'SELECT id, email FROM users'])
            ->assertOk()
            ->assertSee('a@example.com');

        $audit = $site->auditLogs()->where('action', 'site.database_query')->sole();
        $this->assertSame('SELECT id, email FROM users', $audit->after['sql']);
        $this->assertSame(1, $audit->after['rows']);
    }

    public function test_write_needs_super_admin_and_the_database_name(): void
    {
        $site = $this->sharedSite(DatabaseServer::factory()->create());
        $client = $this->mock(DatabaseServerClient::class);
        $client->shouldReceive('tables')->andReturn([]);
        $client->shouldNotReceive('run');

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.database.query', $site), ['sql' => 'DELETE FROM sessions', 'allow_write' => '1', 'confirm' => 'site_izyem'])
            ->assertForbidden();

        $this->actingAs($this->user(OpsRole::SuperAdmin))
            ->post(route('ops.sites.database.query', $site), ['sql' => 'DELETE FROM sessions', 'allow_write' => '1', 'confirm' => 'wrong'])
            ->assertOk()
            ->assertSee(__('databases.errors.confirm_mismatch'));
    }

    public function test_write_without_permission_flag_is_explained(): void
    {
        $site = $this->sharedSite(DatabaseServer::factory()->create());
        $client = $this->mock(DatabaseServerClient::class);
        $client->shouldReceive('tables')->andReturn([]);
        $client->shouldReceive('run')->andThrow(new SqlStatementRejected('write_not_allowed'));

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.database.query', $site), ['sql' => 'DELETE FROM sessions'])
            ->assertOk()
            ->assertSee(__('databases.errors.rejected.write_not_allowed'));
    }

    public function test_operator_sees_slow_queries_linked_to_sites_and_can_filter_by_schema(): void
    {
        $server = $this->probedServer();
        $site = $this->sharedSite($server);
        $client = $this->mock(DatabaseServerClient::class);
        $client->shouldReceive('slowQueries')->once()
            ->withArgs(fn (DatabaseServer $s, ?string $schema): bool => $s->is($server) && $schema === 'site_izyem')
            ->andReturn($this->slowLog([$this->slowRow('site_izyem', 'SELECT * FROM products WHERE slug LIKE \'%x%\'')]));

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.databases.show', ['databaseServer' => $server, 'slow_db' => 'site_izyem']))
            ->assertOk()
            ->assertSee(__('databases.slow.title'))
            ->assertSee('SELECT * FROM products WHERE slug LIKE', false)
            ->assertSee(route('ops.sites.database', $site), false)
            ->assertSee('3.21');
    }

    public function test_unknown_schema_filter_is_ignored(): void
    {
        $server = $this->probedServer();
        $this->mock(DatabaseServerClient::class)->shouldReceive('slowQueries')->once()
            ->withArgs(fn (DatabaseServer $s, ?string $schema): bool => $schema === null)
            ->andReturn($this->slowLog([]));

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.databases.show', ['databaseServer' => $server, 'slow_db' => 'mysql']))
            ->assertOk()
            ->assertSee(__('databases.slow.empty'));
    }

    public function test_viewer_does_not_see_slow_query_text(): void
    {
        $server = $this->probedServer();
        $this->mock(DatabaseServerClient::class)->shouldNotReceive('slowQueries');

        $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.databases.show', $server))
            ->assertOk()
            ->assertDontSee(__('databases.slow.title'));
    }

    public function test_slow_query_errors_do_not_break_the_page(): void
    {
        $server = $this->probedServer();
        $this->mock(DatabaseServerClient::class)->shouldReceive('slowQueries')
            ->andThrow(new DatabaseServerException('Access denied for user plane_admin', [self::ADMIN_PASSWORD]));

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.databases.show', $server))
            ->assertOk()
            ->assertSee('Access denied for user plane_admin')
            ->assertDontSee(self::ADMIN_PASSWORD);
    }

    public function test_watch_list_needs_the_token_and_lists_active_domains(): void
    {
        Site::factory()->create(['status' => SiteStatus::Active, 'primary_domain' => 'izyem.com']);
        Site::factory()->create(['status' => SiteStatus::Draft, 'primary_domain' => 'draft.example.com']);

        $this->get(route('internal.watch-list'))->assertNotFound();

        config(['ops.watch.token' => 'watch-token']);
        $this->get(route('internal.watch-list'))->assertUnauthorized();
        $this->withToken('nope')->get(route('internal.watch-list'))->assertUnauthorized();

        $body = $this->withToken('watch-token')->get(route('internal.watch-list'))->assertOk()->getContent();
        $this->assertStringContainsString("izyem.com\n", (string) $body);
        $this->assertStringNotContainsString('draft.example.com', (string) $body);
    }

    private function probedServer(): DatabaseServer
    {
        return DatabaseServer::factory()->create([
            'admin_password' => self::ADMIN_PASSWORD,
            'last_probe_at' => now(),
            'last_probe_payload' => ['version' => '8.0.46', 'uptime_seconds' => 60, 'connections' => 3, 'databases' => [
                'site_izyem' => ['size_mb' => 40.0, 'tables' => 205],
                'site_test' => ['size_mb' => 12.0, 'tables' => 205],
            ]],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function slowLog(array $rows): array
    {
        return ['enabled' => true, 'threshold_seconds' => 2.0, 'to_table' => true, 'rows' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    private function slowRow(string $schema, string $sql): array
    {
        return [
            'at' => '2026-10-26 07:30:00.123456', 'database' => $schema, 'user' => $schema,
            'seconds' => 3.21, 'lock_seconds' => 0.0, 'rows_sent' => 10, 'rows_examined' => 48211, 'sql' => $sql,
        ];
    }

    private function sharedSite(DatabaseServer $server): Site
    {
        $site = Site::factory()->create(['slug' => 'izyem', 'status' => SiteStatus::Active]);
        $site->forceFill(['database_server_id' => $server->id, 'db_database' => 'site_izyem', 'db_username' => 'site_izyem'])->save();

        return $site->refresh();
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
