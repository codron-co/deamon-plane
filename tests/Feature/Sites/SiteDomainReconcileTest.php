<?php

namespace Tests\Feature\Sites;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CoolifyConnection;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use App\Services\Coolify\Dto\CoolifyApplication;
use App\Services\Sites\SiteDomainReconciler;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteDomainReconcileTest extends TestCase
{
    use RefreshDatabase;

    private const APP = 'domain-app-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_reconciler_imports_coolify_host_and_reports_missing_plane_host(): void
    {
        $site = Site::factory()->create([
            'status' => SiteStatus::Active,
            'primary_domain' => 'plane-only.test',
            'coolify_app_uuid' => self::APP,
        ]);
        SiteDomain::factory()->primary()->create([
            'site_id' => $site->id,
            'domain' => 'plane-only.test',
            'verified_at' => null,
        ]);

        $app = CoolifyApplication::fromArray([
            'uuid' => self::APP,
            'build_pack' => 'dockercompose',
            'docker_compose_domains' => [
                ['name' => 'app', 'domain' => 'https://coolify-only.test,https://www.coolify-only.test'],
            ],
        ]);

        config(['ops.coolify.auto_rebind_domains' => false]);

        $result = app(SiteDomainReconciler::class)->reconcile($site, $app, autoRebind: false);

        $this->assertGreaterThanOrEqual(1, $result['imported']);
        $this->assertContains('plane-only.test', $result['missing']);
        $this->assertTrue(SiteDomain::query()->where('domain', 'coolify-only.test')->where('site_id', $site->id)->exists());
    }

    public function test_live_inspect_flags_domain_unbound_and_bind_fix_patches_coolify(): void
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => 'domain-token',
        ]);
        $site = Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'primary_domain' => 'shop.example.test',
            'coolify_app_uuid' => self::APP,
            'coolify_connection_id' => $connection->id,
        ]);
        SiteDomain::factory()->primary()->create([
            'site_id' => $site->id,
            'domain' => 'shop.example.test',
        ]);
        SiteDomain::factory()->create([
            'site_id' => $site->id,
            'domain' => 'www.shop.example.test',
            'is_www' => true,
            'is_primary' => false,
        ]);

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/applications/'.self::APP)) {
                return Http::response([
                    'uuid' => self::APP,
                    'build_pack' => 'dockercompose',
                    'docker_compose_location' => '/docker-compose.coolify.yml',
                    'docker_compose_domains' => [
                        ['name' => 'app', 'domain' => ''],
                    ],
                ], 200);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/envs')) {
                return Http::response([
                    ['key' => 'DB_HOST', 'value' => 'mysql'],
                    ['key' => 'DB_CONNECTION', 'value' => 'mysql'],
                    ['key' => 'DB_PASSWORD', 'value' => 'already-set-password-value-32chars'],
                    ['key' => 'MYSQL_ROOT_PASSWORD', 'value' => 'already-set-root-password-32charsxx'],
                    ['key' => 'APP_KEY', 'value' => 'base64:keep'],
                    ['key' => 'CONTROL_PLANE_AGENT_SECRET', 'value' => 'plane-agent-secret'],
                ], 200);
            }
            if ($request->method() === 'PATCH' && str_ends_with($request->url(), '/applications/'.self::APP)) {
                return Http::response(['uuid' => self::APP], 200);
            }

            return Http::response(['error' => 'unexpected '.$request->url()], 404);
        });

        $this->actingAs($this->operator())
            ->postJson(route('ops.sites.app-health', $site))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $codes = collect($site->fresh()->appHealth()->issues)->map(fn ($i) => $i->code)->all();
        $this->assertContains('domain_unbound', $codes);

        $this->actingAs($this->operator())
            ->postJson(route('ops.sites.app-health.fix', $site), ['fix' => 'bind_domains'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PATCH' || ! str_ends_with($request->url(), '/applications/'.self::APP)) {
                return false;
            }
            $body = $request->data();

            return array_key_exists('docker_compose_domains', $body);
        });
    }

    public function test_domains_index_lists_rows(): void
    {
        $site = Site::factory()->create(['name' => 'Domain List Site']);
        SiteDomain::factory()->primary()->create([
            'site_id' => $site->id,
            'domain' => 'list.example.test',
        ]);

        $this->actingAs($this->operator())
            ->get(route('ops.domains'))
            ->assertOk()
            ->assertSee('list.example.test', false)
            ->assertSee(__('domains.title'), false);
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Operator->value);

        return $user;
    }
}
