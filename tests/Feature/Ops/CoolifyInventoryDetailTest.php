<?php

namespace Tests\Feature\Ops;

use App\Enums\CoolifyGitSourceKind;
use App\Enums\OpsRole;
use App\Models\CoolifyConnection;
use App\Models\CoolifyEnvironment;
use App\Models\CoolifyGitSource;
use App\Models\CoolifyProjectRecord;
use App\Models\CoolifyServer;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoolifyInventoryDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_operator_opens_server_project_environment_and_git_source_details(): void
    {
        $connection = CoolifyConnection::factory()->create(['name' => 'Prod Coolify']);
        $server = CoolifyServer::query()->create([
            'coolify_connection_id' => $connection->id,
            'uuid' => 'edge-1',
            'name' => 'edge',
            'ip' => '1.2.3.4',
            'is_active' => true,
        ]);
        $project = CoolifyProjectRecord::query()->create([
            'coolify_connection_id' => $connection->id,
            'uuid' => 'proj-1',
            'name' => 'Deamon',
            'is_active' => true,
        ]);
        $environment = CoolifyEnvironment::query()->create([
            'coolify_connection_id' => $connection->id,
            'project_uuid' => $project->uuid,
            'uuid' => 'env-prod',
            'name' => 'production',
            'is_active' => true,
        ]);
        $source = CoolifyGitSource::query()->create([
            'coolify_connection_id' => $connection->id,
            'kind' => CoolifyGitSourceKind::GithubApp,
            'uuid' => 'gh-1',
            'name' => 'codron',
            'is_active' => true,
        ]);
        $site = Site::factory()->create([
            'name' => 'Linked Site',
            'coolify_connection_id' => $connection->id,
            'coolify_server_uuid' => $server->uuid,
            'coolify_project_uuid' => $project->uuid,
            'coolify_environment_uuid' => $environment->uuid,
            'coolify_git_source_uuid' => $source->uuid,
        ]);

        $operator = $this->operator();

        $this->actingAs($operator)
            ->get(route('ops.coolify.servers.show', [$connection, $server]))
            ->assertOk()
            ->assertSee('edge', false)
            ->assertSee('1.2.3.4', false)
            ->assertSee('Linked Site', false)
            ->assertSee(route('ops.sites.show', $site), false);

        $this->actingAs($operator)
            ->get(route('ops.coolify.projects.show', [$connection, $project]))
            ->assertOk()
            ->assertSee('Deamon', false)
            ->assertSee('production', false)
            ->assertSee(route('ops.coolify.environments.show', [$connection, $environment]), false);

        $this->actingAs($operator)
            ->get(route('ops.coolify.environments.show', [$connection, $environment]))
            ->assertOk()
            ->assertSee('production', false)
            ->assertSee('Deamon', false)
            ->assertSee(route('ops.coolify.projects.show', [$connection, $project]), false);

        $this->actingAs($operator)
            ->get(route('ops.coolify.git-sources.show', [$connection, $source]))
            ->assertOk()
            ->assertSee('codron', false)
            ->assertSee(__('coolify.kinds.github_app'), false);
    }

    public function test_inventory_from_another_connection_is_not_found(): void
    {
        $connection = CoolifyConnection::factory()->create();
        $other = CoolifyConnection::factory()->create();
        $server = CoolifyServer::query()->create([
            'coolify_connection_id' => $connection->id,
            'uuid' => 'edge-1',
            'name' => 'edge',
            'is_active' => true,
        ]);

        $this->actingAs($this->operator())
            ->get(route('ops.coolify.servers.show', [$other, $server]))
            ->assertNotFound();
    }

    public function test_viewer_can_open_detail_but_cannot_toggle(): void
    {
        $connection = CoolifyConnection::factory()->create();
        $server = CoolifyServer::query()->create([
            'coolify_connection_id' => $connection->id,
            'uuid' => 'edge-1',
            'name' => 'edge',
            'is_active' => true,
        ]);

        $viewer = User::factory()->create();
        $viewer->assignRole(OpsRole::Viewer->value);

        $this->actingAs($viewer)
            ->get(route('ops.coolify.servers.show', [$connection, $server]))
            ->assertOk()
            ->assertDontSee(__('coolify.allowlist.deactivate'), false);

        $this->actingAs($viewer)
            ->post(route('ops.coolify.servers.toggle', [$connection, $server]))
            ->assertForbidden();
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }
}
