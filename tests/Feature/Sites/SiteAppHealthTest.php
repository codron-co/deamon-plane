<?php

namespace Tests\Feature\Sites;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CoolifyConnection;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteAppHealthTest extends TestCase
{
    use RefreshDatabase;

    private const APP = 'app-health-1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_sites_index_shows_local_app_issue_count(): void
    {
        $site = Site::factory()->dockerfilePack()->create([
            'name' => 'Legacy Pack Site',
            'status' => SiteStatus::Active,
        ]);

        $this->actingAs($this->operator())
            ->get(route('ops.sites'))
            ->assertOk()
            ->assertSee(__('sites.columns.app'), false)
            ->assertSee(trans_choice('sites.app_health.issues_count', 3, ['count' => 3]), false)
            ->assertSee(__('sites.app_health.issues.dockerfile_pack'), false)
            ->assertSee('data-app-health-copy', false)
            ->assertSee('data-copy-text', false);

        $this->assertSame(3, $site->appHealth()->count());
    }

    public function test_live_inspect_flags_missing_mysql_root_and_json_refresh(): void
    {
        $site = $this->composeSite();

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/applications/'.self::APP)) {
                return Http::response([
                    'uuid' => self::APP,
                    'build_pack' => 'dockercompose',
                    'docker_compose_location' => '/docker-compose.coolify.yml',
                ], 200);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/envs')) {
                return Http::response([
                    ['key' => 'DB_HOST', 'value' => '10.0.0.9'],
                    ['key' => 'DB_CONNECTION', 'value' => 'mysql'],
                    ['key' => 'DB_PASSWORD', 'value' => 'already-set'],
                    ['key' => 'MYSQL_ROOT_PASSWORD', 'value' => ''],
                    ['key' => 'APP_KEY', 'value' => 'base64:keep'],
                    ['key' => 'CONTROL_PLANE_AGENT_SECRET', 'value' => 'plane-agent-secret'],
                ], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $this->actingAs($this->operator())
            ->postJson(route('ops.sites.app-health', $site))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('health.ok', false);

        $codes = collect($site->fresh()->appHealth()->issues)->map(fn ($issue) => $issue->code.'|'.($issue->key ?? ''))->all();
        $this->assertContains('missing_env|MYSQL_ROOT_PASSWORD', $codes);
        $this->assertContains('wrong_env|DB_HOST', $codes);
    }

    public function test_sync_env_fix_writes_compose_catalog_without_page_redirect(): void
    {
        $site = $this->composeSite();

        Http::fake(function (Request $request) use ($site) {
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/applications/'.self::APP)) {
                return Http::response([
                    'uuid' => self::APP,
                    'build_pack' => 'dockercompose',
                    'docker_compose_location' => '/docker-compose.coolify.yml',
                ], 200);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/envs')) {
                return Http::response([
                    ['key' => 'DB_HOST', 'value' => '10.0.0.9'],
                    ['key' => 'DB_PASSWORD', 'value' => ''],
                    ['key' => 'MYSQL_ROOT_PASSWORD', 'value' => ''],
                    ['key' => 'APP_KEY', 'value' => (string) $site->app_key_encrypted],
                    ['key' => 'CONTROL_PLANE_AGENT_SECRET', 'value' => (string) $site->agent_secret_encrypted],
                ], 200);
            }
            if ($request->method() === 'PATCH' && str_contains($request->url(), '/envs/bulk')) {
                return Http::response([], 200);
            }

            return Http::response(['error' => 'unexpected '.$request->url()], 404);
        });

        $this->actingAs($this->operator())
            ->postJson(route('ops.sites.app-health.fix', $site), ['fix' => 'sync_env'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PATCH' || ! str_contains($request->url(), '/envs/bulk')) {
                return false;
            }

            $map = [];
            foreach ($request->data()['data'] ?? [] as $row) {
                if (is_array($row) && isset($row['key'])) {
                    $map[(string) $row['key']] = (string) ($row['value'] ?? '');
                }
            }

            return ($map['DB_HOST'] ?? null) === 'mysql'
                && strlen((string) ($map['MYSQL_ROOT_PASSWORD'] ?? '')) >= 32
                && strlen((string) ($map['DB_PASSWORD'] ?? '')) >= 32;
        });
    }

    public function test_jobs_index_includes_active_coolify_deployments(): void
    {
        $site = Site::factory()->create(['name' => 'Widget Site']);
        Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::InProgress,
            'trigger' => DeploymentTrigger::Manual,
            'error_message' => null,
        ]);

        $this->actingAs($this->operator())
            ->getJson(route('ops.jobs'))
            ->assertOk()
            ->assertJsonPath('deployments.0.title', 'Widget Site')
            ->assertJsonPath('deployments.0.status', 'running');
    }

    public function test_viewer_cannot_fix_or_list_jobs(): void
    {
        $site = $this->composeSite();
        Http::fake();

        $this->actingAs($this->viewer())
            ->postJson(route('ops.sites.app-health.fix', $site), ['fix' => 'sync_env'])
            ->assertForbidden();

        $this->actingAs($this->viewer())
            ->getJson(route('ops.jobs'))
            ->assertForbidden();
    }

    public function test_detail_renders_app_health_card(): void
    {
        $site = Site::factory()->create(['status' => SiteStatus::Draft]);

        $this->actingAs($this->operator())
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee(__('sites.app_health.title'), false)
            ->assertSee(route('ops.sites.app-health', $site), false);
    }

    private function composeSite(): Site
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => 'app-health-token',
        ]);

        return Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => self::APP,
            'coolify_connection_id' => $connection->id,
        ]);
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Operator->value);

        return $user;
    }

    private function viewer(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Viewer->value);

        return $user;
    }
}
