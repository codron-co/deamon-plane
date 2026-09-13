<?php

namespace Tests\Feature\Ops;

use App\Enums\CoolifyGitSourceKind;
use App\Enums\OpsRole;
use App\Models\CoolifyConnection;
use App\Models\CoolifyEnvironment;
use App\Models\CoolifyGitSource;
use App\Models\CoolifyProjectRecord;
use App\Models\CoolifyServer;
use App\Models\User;
use App\Support\Lists\ListFragment;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coolify inventory lives on the connection show tab, not a first-class list
 * URL. Search / kind / status still have to distinguish "never synced" from
 * "these filters hid everything", the way Sites and mail servers already do.
 */
class CoolifyInventoryListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_an_empty_inventory_explains_itself_and_offers_sync(): void
    {
        $connection = CoolifyConnection::factory()->create(['name' => 'Empty Coolify']);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.coolify.show', $connection))
            ->assertOk()
            ->assertSee(__('coolify.empty_inventory'))
            ->assertSee(__('coolify.empty_inventory_hint'))
            ->assertSee(__('coolify.show.sync'))
            ->assertDontSee(__('coolify.empty_filtered_title'))
            ->assertDontSee('ops-filter-chips', false)
            ->assertSee('data-ops-list-toolbar', false)
            ->assertSee('data-ops-list-clear hidden>', false);
    }

    public function test_a_viewer_sees_why_there_is_no_sync_action(): void
    {
        $connection = CoolifyConnection::factory()->create();

        $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.coolify.show', $connection))
            ->assertOk()
            ->assertSee(__('coolify.empty_inventory'))
            ->assertSee(__('ops.viewer_readonly'))
            ->assertDontSee('>'.__('coolify.show.sync').'<', false);
    }

    public function test_search_kind_and_status_keep_only_matching_rows(): void
    {
        $connection = $this->connectionWithInventory();

        $this->actingAs($this->user(OpsRole::Operator))
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.coolify.show', [$connection, 'q' => 'edge']))
            ->assertOk()
            ->assertSee('edge-west')
            ->assertDontSee('Catalog Hub')
            ->assertDontSee('theme-source');

        $this->actingAs($this->user(OpsRole::Operator))
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.coolify.show', [$connection, 'kind' => 'projects']))
            ->assertOk()
            ->assertSee('Catalog Hub')
            ->assertDontSee('edge-west')
            ->assertDontSee('theme-source');

        $this->actingAs($this->user(OpsRole::Operator))
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.coolify.show', [$connection, 'status' => 'inactive']))
            ->assertOk()
            ->assertSee('old-node')
            ->assertDontSee('edge-west');
    }

    public function test_search_reaches_uuid_and_ip_and_treats_percent_as_literal(): void
    {
        $connection = $this->connectionWithInventory();

        $this->actingAs($this->user(OpsRole::Operator))
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.coolify.show', [$connection, 'q' => '10.0.0.8']))
            ->assertOk()
            ->assertSee('edge-west')
            ->assertDontSee('Catalog Hub');

        $this->actingAs($this->user(OpsRole::Operator))
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.coolify.show', [$connection, 'q' => 'proj-1']))
            ->assertOk()
            ->assertSee('Catalog Hub')
            ->assertDontSee('edge-west');

        $this->actingAs($this->user(OpsRole::Operator))
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.coolify.show', [$connection, 'q' => '%']))
            ->assertOk()
            ->assertSee(__('coolify.empty_filtered_title'))
            ->assertDontSee('edge-west')
            ->assertDontSee('Catalog Hub');
    }

    public function test_a_filtered_empty_result_names_the_filters_and_the_inventory_size(): void
    {
        $connection = $this->connectionWithInventory();

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.coolify.show', [
                $connection,
                'q' => 'nothing-here',
                'kind' => 'servers',
                'status' => 'inactive',
            ]))
            ->assertOk()
            ->assertSee(__('coolify.empty_filtered_title'))
            ->assertSee(__('coolify.empty_filtered_hint', ['total' => 5]))
            ->assertDontSee(__('coolify.empty_inventory'))
            ->assertSee(__('coolify.filter_search'))
            ->assertSee('nothing-here')
            ->assertSee(__('coolify.filter_kind'))
            ->assertSee(__('coolify.allowlist.servers'))
            ->assertSee(__('coolify.filter_status'))
            ->assertSee(__('ops.inactive'))
            ->assertSee('data-initial-tab="inventory"', false);
    }

    public function test_each_filter_chip_drops_only_itself_and_clearing_all_stays_prominent(): void
    {
        $connection = $this->connectionWithInventory();

        $response = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.coolify.show', [
                $connection,
                'q' => 'nope',
                'kind' => 'git',
            ]))
            ->assertOk();

        $response->assertSee(e(route('ops.coolify.show', [$connection, 'kind' => 'git'])), false);
        $response->assertSee(e(route('ops.coolify.show', [$connection, 'q' => 'nope'])), false);
        $response->assertSee(__('ops.actions.clear_filters'));
        $this->assertStringContainsString('btn-primary', $response->getContent());
    }

    public function test_the_async_region_carries_the_same_empty_states_without_the_shell(): void
    {
        $connection = $this->connectionWithInventory();

        $this->actingAs($this->user(OpsRole::Operator))
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.coolify.show', [$connection, 'q' => 'no-such-row']))
            ->assertOk()
            ->assertHeader('X-Ops-List-Region', '1')
            ->assertHeader('Vary', ListFragment::HEADER)
            ->assertSee(__('coolify.empty_filtered_title'))
            ->assertSee('ops-filter-chips', false)
            ->assertDontSee('ops-sidebar', false)
            ->assertDontSee('data-ops-list-toolbar', false)
            ->assertDontSee('coolify-overview-heading', false);
    }

    public function test_filtered_copy_reads_as_turkish_for_a_turkish_operator(): void
    {
        $connection = $this->connectionWithInventory();

        $this->actingAs($this->user(OpsRole::Operator, 'tr'))
            ->get(route('ops.coolify.show', [$connection, 'q' => 'yok']))
            ->assertOk()
            ->assertSee(trans('coolify.empty_filtered_title', [], 'tr'), false)
            ->assertSee(trans('ops.actions.clear_filters', [], 'tr'), false)
            ->assertDontSee(trans('coolify.empty_filtered_title', [], 'en'), false);
    }

    public function test_an_inventory_detail_with_no_sites_offers_sync(): void
    {
        $connection = CoolifyConnection::factory()->create(['name' => 'Lonely']);
        $server = CoolifyServer::query()->create([
            'coolify_connection_id' => $connection->id,
            'uuid' => 'edge-1',
            'name' => 'edge',
            'is_active' => true,
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.coolify.servers.show', [$connection, $server]))
            ->assertOk()
            ->assertSee(__('coolify.detail.no_sites_title'))
            ->assertSee(__('coolify.detail.no_sites'))
            ->assertSee(route('ops.coolify.sync', $connection), false)
            ->assertSee(route('ops.coolify.show', $connection), false);
    }

    public function test_unknown_filters_widen_instead_of_emptying(): void
    {
        $connection = $this->connectionWithInventory();

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.coolify.show', [$connection, 'kind' => 'kaboom', 'status' => 'maybe']))
            ->assertOk()
            ->assertSee('edge-west')
            ->assertSee('Catalog Hub')
            ->assertDontSee(__('coolify.empty_filtered_title'));
    }

    private function connectionWithInventory(): CoolifyConnection
    {
        $connection = CoolifyConnection::factory()->create(['name' => 'Prod Coolify']);
        CoolifyServer::query()->create([
            'coolify_connection_id' => $connection->id,
            'uuid' => 'edge-1',
            'name' => 'edge-west',
            'ip' => '10.0.0.8',
            'is_active' => true,
        ]);
        CoolifyServer::query()->create([
            'coolify_connection_id' => $connection->id,
            'uuid' => 'old-1',
            'name' => 'old-node',
            'ip' => '10.0.0.9',
            'is_active' => false,
        ]);
        $project = CoolifyProjectRecord::query()->create([
            'coolify_connection_id' => $connection->id,
            'uuid' => 'proj-1',
            'name' => 'Catalog Hub',
            'is_active' => true,
        ]);
        CoolifyEnvironment::query()->create([
            'coolify_connection_id' => $connection->id,
            'project_uuid' => $project->uuid,
            'uuid' => 'env-prod',
            'name' => 'production',
            'is_active' => true,
        ]);
        CoolifyGitSource::query()->create([
            'coolify_connection_id' => $connection->id,
            'kind' => CoolifyGitSourceKind::GithubApp,
            'uuid' => 'gh-1',
            'name' => 'theme-source',
            'is_active' => true,
        ]);

        return $connection;
    }

    private function user(OpsRole $role, ?string $locale = null): User
    {
        $user = User::factory()->create($locale === null ? [] : ['locale' => $locale]);
        $user->assignRole($role->value);

        return $user;
    }
}
