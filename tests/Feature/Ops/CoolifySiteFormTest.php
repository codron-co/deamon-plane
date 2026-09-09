<?php

namespace Tests\Feature\Ops;

use App\Enums\Channel;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CoolifyConnection;
use App\Models\CoolifyEnvironment;
use App\Models\CoolifyGitSource;
use App\Models\CoolifyProjectRecord;
use App\Models\CoolifyServer;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CoolifySiteFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_create_form_uses_selects_not_free_text_server_uuid(): void
    {
        $connection = $this->seedConnection();

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.create'))
            ->assertOk()
            ->assertSee('name="coolify_server_uuid"', false)
            ->assertSee('no48ksggg0k8sk4o4w08gks8', false)
            ->assertDontSee('id="site_server" class="field-input" type="text"', false)
            ->getContent();

        $this->assertStringContainsString('<select', $html);
        $this->assertStringContainsString($connection->name, $html);
        $this->assertStringContainsString('value="env-prod"', $html);
        $this->assertStringNotContainsString('value="rw8flmmfzxkp0qnklzzi8lny"', $html);
    }

    public function test_operator_cannot_pick_inactive_server(): void
    {
        $connection = $this->seedConnection();
        CoolifyServer::query()->where('uuid', 'edge-1')->update(['is_active' => false]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.store'), [
                'slug' => 'izyem',
                'name' => 'Izyem',
                'domain' => 'shop.izyem.example.test',
                'channel' => 'beta',
                'coolify_connection_id' => $connection->id,
                'coolify_server_uuid' => 'edge-1',
            ])
            ->assertSessionHasErrors('coolify_server_uuid');

        $this->assertDatabaseCount('sites', 0);
    }

    public function test_operator_saves_allowlisted_targets(): void
    {
        $connection = $this->seedConnection();

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.store'), [
                'slug' => 'izyem',
                'name' => 'Izyem',
                'domain' => 'shop.izyem.example.test',
                'channel' => 'beta',
                'coolify_connection_id' => $connection->id,
                'coolify_server_uuid' => 'no48ksggg0k8sk4o4w08gks8',
                'coolify_project_uuid' => 'proj_test',
                'coolify_environment_uuid' => 'env-prod',
                'coolify_git_source' => 'github_app:gh-app-1',
            ])
            ->assertRedirect();

        $site = Site::query()->where('slug', 'izyem')->first();
        $this->assertNotNull($site);
        $this->assertSame($connection->id, $site->coolify_connection_id);
        $this->assertSame('no48ksggg0k8sk4o4w08gks8', $site->coolify_server_uuid);
        $this->assertSame('proj_test', $site->coolify_project_uuid);
        $this->assertSame('env-prod', $site->coolify_environment_uuid);
        $this->assertSame('gh-app-1', $site->coolify_git_source_uuid);
    }

    public function test_attach_existing_app_sets_uuid_domain_channel_without_create(): void
    {
        $connection = $this->seedConnection();

        Http::fake([
            'https://coolify.test/api/v1/applications/crxguq6nodorlzy88wf9x305' => Http::response([
                'uuid' => 'crxguq6nodorlzy88wf9x305',
                'name' => 'Susa',
                'git_branch' => 'beta',
                'build_pack' => 'dockercompose',
                'git_repository' => 'https://github.com/codron-co/deamon.git',
                'status' => 'running:healthy',
                'docker_compose_domains' => '{"app":{"domain":"https://susa.demo.codron.co"}}',
                'destination' => ['server' => ['uuid' => 'no48ksggg0k8sk4o4w08gks8']],
            ], 200),
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.store'), [
                'slug' => 'susa',
                'name' => 'Susa',
                'domain' => 'placeholder.example.test',
                'channel' => 'main',
                'coolify_connection_id' => $connection->id,
                'coolify_server_uuid' => 'no48ksggg0k8sk4o4w08gks8',
                'placement' => 'attach',
                'attach_app_uuid' => 'crxguq6nodorlzy88wf9x305',
            ])
            ->assertRedirect();

        $site = Site::query()->where('slug', 'susa')->first();
        $this->assertNotNull($site);
        $this->assertSame('crxguq6nodorlzy88wf9x305', $site->coolify_app_uuid);
        $this->assertSame('susa.demo.codron.co', $site->primary_domain);
        $this->assertSame(Channel::Beta, $site->channel);
        $this->assertFalse($site->channel_needs_review);
        $this->assertSame(SiteStatus::Active, $site->status);

        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), '/applications/crxguq6nodorlzy88wf9x305'));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/applications/'));
    }

    public function test_attach_unknown_branch_flags_needs_review(): void
    {
        $connection = $this->seedConnection();

        Http::fake([
            'https://coolify.test/api/v1/applications/app-nightly' => Http::response([
                'uuid' => 'app-nightly',
                'name' => 'Nightly',
                'git_branch' => 'nightly',
                'build_pack' => 'dockercompose',
                'git_repository' => 'https://github.com/codron-co/deamon.git',
                'status' => 'running',
                'docker_compose_domains' => '{"app":{"domain":"https://nightly.example.test"}}',
            ], 200),
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.store'), [
                'slug' => 'nightly',
                'name' => 'Nightly',
                'domain' => 'nightly.example.test',
                'channel' => 'main',
                'coolify_connection_id' => $connection->id,
                'placement' => 'attach',
                'attach_app_uuid' => 'app-nightly',
            ])
            ->assertRedirect();

        $site = Site::query()->where('slug', 'nightly')->first();
        $this->assertNotNull($site);
        $this->assertTrue($site->channel_needs_review);
        $this->assertSame(Channel::Main, $site->channel);
    }

    public function test_super_admin_sees_advanced_collapse(): void
    {
        $this->seedConnection();

        $this->actingAs($this->user(OpsRole::SuperAdmin))
            ->get(route('ops.sites.create'))
            ->assertOk()
            ->assertSee('Gelişmiş — UUID yapıştır', false);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.create'))
            ->assertOk()
            ->assertDontSee('Gelişmiş — UUID yapıştır', false);
    }

    private function seedConnection(): CoolifyConnection
    {
        $connection = CoolifyConnection::factory()->create([
            'name' => 'Prod Coolify',
            'is_default' => true,
        ]);

        CoolifyServer::query()->create([
            'coolify_connection_id' => $connection->id,
            'uuid' => 'no48ksggg0k8sk4o4w08gks8',
            'name' => 'localhost',
            'is_active' => true,
        ]);
        CoolifyServer::query()->create([
            'coolify_connection_id' => $connection->id,
            'uuid' => 'edge-1',
            'name' => 'edge',
            'is_active' => true,
        ]);
        CoolifyProjectRecord::query()->create([
            'coolify_connection_id' => $connection->id,
            'uuid' => 'proj_test',
            'name' => 'Deamon',
            'is_active' => true,
        ]);
        CoolifyEnvironment::query()->create([
            'coolify_connection_id' => $connection->id,
            'project_uuid' => 'proj_test',
            'uuid' => 'env-prod',
            'name' => 'production',
            'is_active' => true,
        ]);
        CoolifyEnvironment::query()->create([
            'coolify_connection_id' => $connection->id,
            'project_uuid' => 'other-proj',
            'uuid' => 'rw8flmmfzxkp0qnklzzi8lny',
            'name' => 'plane',
            'is_active' => true,
        ]);
        CoolifyGitSource::query()->create([
            'coolify_connection_id' => $connection->id,
            'kind' => 'github_app',
            'uuid' => 'gh-app-1',
            'name' => 'codron',
            'is_active' => true,
        ]);

        return $connection;
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
