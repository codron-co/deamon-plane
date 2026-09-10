<?php

namespace Tests\Feature\Sites;

use App\Enums\Channel;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CloudflareSetting;
use App\Models\CoolifySetting;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteLandingFlowTest extends TestCase
{
    use RefreshDatabase;

    private const CF_TOKEN = 'cf-landing-secret-token';

    private const ACCOUNT_ID = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();

        CoolifySetting::factory()->create([
            'base_url' => 'https://coolify.test',
            'api_token' => 'test-coolify-token',
            'default_project_uuid' => 'proj_test',
            'default_server_uuid' => 'srv_test',
        ]);
    }

    public function test_create_persists_www_and_extra_alias_on_the_same_apex(): void
    {
        $this->seedCloudflare();

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.store'), [
                'slug' => 'izyem',
                'name' => 'Izyem',
                'domain' => 'izyem.test',
                'aliases' => ['shop.izyem.test'],
                'channel' => 'beta',
            ])
            ->assertRedirect();

        $site = Site::query()->where('slug', 'izyem')->first();
        $this->assertNotNull($site);
        $this->assertSame('izyem.test', $site->primary_domain);
        $this->assertTrue($site->domains()->where('domain', 'izyem.test')->where('is_primary', true)->exists());
        $this->assertTrue($site->domains()->where('domain', 'www.izyem.test')->where('is_www', true)->exists());
        $this->assertTrue($site->domains()->where('domain', 'shop.izyem.test')->exists());
        $this->assertTrue($site->domains()->where('domain', 'www.shop.izyem.test')->where('is_www', true)->exists());
    }

    public function test_create_rejects_alias_on_a_different_apex(): void
    {
        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.store'), [
                'slug' => 'izyem',
                'name' => 'Izyem',
                'domain' => 'izyem.test',
                'aliases' => ['other.example'],
                'channel' => 'beta',
            ])
            ->assertSessionHasErrors('aliases.0');

        $this->assertDatabaseCount('sites', 0);
    }

    public function test_create_and_detail_offer_multiple_domains_and_dns_confirm(): void
    {
        $this->seedCloudflare();
        $site = $this->draftSite([
            'primary_domain' => 'izyem.test',
            'temporary_domain' => 'amber-harbor.codron.co',
            'cloudflare_zone_status' => 'pending',
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.create'))
            ->assertOk()
            ->assertSee('name="aliases[]"', false)
            ->assertSee(__('sites.form.aliases'), false);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee(route('ops.sites.domains.store', $site), false)
            ->assertSee(route('ops.sites.cloudflare.dns', $site), false)
            ->assertSee(__('sites.landing.confirm_dns'), false)
            ->assertSee('amber-harbor.codron.co', false);
    }

    public function test_pending_zone_provisions_app_on_temp_host_and_keeps_customer_primary(): void
    {
        $this->seedCloudflare();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'api.cloudflare.com')) {
                return $this->cloudflareResponse($request, zoneCreateStatus: 200, zoneStatus: 'pending', zonesByName: [
                    'codron.co' => $this->zonePayload('zone-codron', 'codron.co', 'active'),
                ]);
            }

            return $this->coolifyHappyPath($request);
        });

        $site = $this->draftSite(['primary_domain' => 'izyem.test']);

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.provision', $site))
            ->assertRedirect(route('ops.sites.show', $site));

        $site->refresh();
        $this->assertSame(SiteStatus::Active, $site->status);
        $this->assertSame('izyem.test', $site->primary_domain);
        $this->assertSame('pending', $site->cloudflare_zone_status);
        $this->assertNotNull($site->temporary_domain);
        $this->assertMatchesRegularExpression('/^[a-z]+-[a-z]+\.codron\.co$/', (string) $site->temporary_domain);
        $this->assertTrue($site->domains()->where('domain', $site->temporary_domain)->where('is_temporary', true)->exists());
        $this->assertSame('coolify-app-1', $site->coolify_app_uuid);

        Http::assertSent(function (Request $request) use ($site): bool {
            $body = $request->data();
            $domain = data_get($body, 'docker_compose_domains.0.domain');

            return $request->method() === 'POST'
                && str_contains($request->url(), '/applications/public')
                && is_string($domain)
                && str_contains($domain, (string) $site->temporary_domain)
                && ! str_contains($domain, 'izyem.test');
        });
    }

    public function test_dns_confirm_stays_paused_while_zone_is_pending(): void
    {
        $this->seedCloudflare();
        $site = $this->draftSite([
            'primary_domain' => 'izyem.test',
            'cloudflare_zone_id' => 'zone-new',
            'cloudflare_zone_status' => 'pending',
            'temporary_domain' => 'amber-harbor.codron.co',
            'coolify_app_uuid' => 'coolify-app-1',
            'status' => SiteStatus::Active,
        ]);
        $site->domains()->create([
            'domain' => 'amber-harbor.codron.co',
            'is_primary' => false,
            'is_temporary' => true,
        ]);

        Http::fake(function (Request $request) {
            return $this->cloudflareResponse($request, zoneStatus: 'pending', zonesByName: [
                'izyem.test' => $this->zonePayload('zone-new', 'izyem.test', 'pending'),
            ]);
        });

        $this->actingAs($this->user(OpsRole::Operator, ['locale' => 'tr']))
            ->postJson(route('ops.sites.cloudflare.dns', $site))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('ready', false);

        $site->refresh();
        $this->assertSame('amber-harbor.codron.co', $site->temporary_domain);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'coolify.test'));
    }

    public function test_dns_confirm_removes_temp_and_binds_apex_plus_www_on_coolify(): void
    {
        $this->seedCloudflare();
        $site = $this->draftSite([
            'primary_domain' => 'izyem.test',
            'cloudflare_zone_id' => 'zone-new',
            'cloudflare_zone_status' => 'pending',
            'temporary_domain' => 'amber-harbor.codron.co',
            'coolify_app_uuid' => 'coolify-app-1',
            'coolify_server_uuid' => 'srv_test',
            'status' => SiteStatus::Active,
        ]);
        $site->domains()->createMany([
            ['domain' => 'izyem.test', 'is_primary' => true, 'is_www' => false],
            ['domain' => 'www.izyem.test', 'is_primary' => false, 'is_www' => true],
            ['domain' => 'amber-harbor.codron.co', 'is_primary' => false, 'is_temporary' => true],
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'api.cloudflare.com')) {
                return $this->cloudflareResponse($request, zoneStatus: 'active', zonesByName: [
                    'izyem.test' => $this->zonePayload('zone-new', 'izyem.test', 'active'),
                    'codron.co' => $this->zonePayload('zone-codron', 'codron.co', 'active'),
                ]);
            }

            return $this->coolifyHappyPath($request);
        });

        $this->actingAs($this->user(OpsRole::Operator))
            ->postJson(route('ops.sites.cloudflare.dns', $site))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('ready', true);

        $site->refresh();
        $this->assertNull($site->temporary_domain);
        $this->assertSame('active', $site->cloudflare_zone_status);
        $this->assertFalse($site->domains()->where('is_temporary', true)->exists());
        $this->assertSame('izyem.test', $site->primary_domain);

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();
            $domain = data_get($body, 'docker_compose_domains.0.domain');

            return $request->method() === 'PATCH'
                && $request->url() === 'https://coolify.test/api/v1/applications/coolify-app-1'
                && is_string($domain)
                && str_contains($domain, 'https://izyem.test')
                && str_contains($domain, 'https://www.izyem.test')
                && ! str_contains($domain, 'amber-harbor.codron.co');
        });
    }

    public function test_detail_add_domain_writes_www_to_cloudflare_and_coolify(): void
    {
        $this->seedCloudflare();
        $site = $this->draftSite([
            'primary_domain' => 'izyem.test',
            'cloudflare_zone_id' => 'zone-existing',
            'cloudflare_zone_status' => 'active',
            'coolify_app_uuid' => 'coolify-app-1',
            'coolify_server_uuid' => 'srv_test',
            'status' => SiteStatus::Active,
        ]);
        $site->domains()->createMany([
            ['domain' => 'izyem.test', 'is_primary' => true],
            ['domain' => 'www.izyem.test', 'is_www' => true],
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'api.cloudflare.com')) {
                return $this->cloudflareResponse($request, zonesByName: [
                    'izyem.test' => $this->zonePayload('zone-existing', 'izyem.test', 'active'),
                ]);
            }

            return $this->coolifyHappyPath($request);
        });

        $this->actingAs($this->user(OpsRole::Operator))
            ->postJson(route('ops.sites.domains.store', $site), [
                'domain' => 'shop.izyem.test',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertTrue($site->domains()->where('domain', 'shop.izyem.test')->exists());
        $this->assertTrue($site->domains()->where('domain', 'www.shop.izyem.test')->exists());

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), '/dns_records')) {
                return false;
            }

            $name = (string) ($request->data()['name'] ?? '');

            return in_array($name, ['shop', 'www.shop'], true);
        });

        Http::assertSent(function (Request $request): bool {
            $domain = data_get($request->data(), 'docker_compose_domains.0.domain');

            return $request->method() === 'PATCH'
                && str_contains((string) $request->url(), '/applications/coolify-app-1')
                && is_string($domain)
                && str_contains($domain, 'shop.izyem.test')
                && str_contains($domain, 'www.shop.izyem.test');
        });
    }

    public function test_viewer_cannot_confirm_dns_or_add_domain(): void
    {
        $site = $this->draftSite();

        $this->actingAs($this->user(OpsRole::Viewer))
            ->postJson(route('ops.sites.cloudflare.dns', $site))
            ->assertForbidden();

        $this->actingAs($this->user(OpsRole::Viewer))
            ->postJson(route('ops.sites.domains.store', $site), ['domain' => 'shop.izyem.test'])
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function draftSite(array $overrides = []): Site
    {
        return Site::factory()->create(array_merge([
            'slug' => 'izyem',
            'name' => 'Izyem',
            'primary_domain' => 'shop.izyem.example.test',
            'channel' => Channel::Beta,
            'status' => SiteStatus::Draft,
            'coolify_server_uuid' => 'srv_test',
        ], $overrides));
    }

    private function user(OpsRole $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role->value);

        return $user;
    }

    private function seedCloudflare(): void
    {
        CloudflareSetting::factory()->create([
            'account_id' => self::ACCOUNT_ID,
            'api_token' => self::CF_TOKEN,
            'origin_ipv4' => '72.62.117.147',
            'wildcard_domain' => 'codron.co',
            'is_enabled' => true,
            'is_default' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function zonePayload(string $id, string $name, string $status = 'active'): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'status' => $status,
            'name_servers' => ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $zonesByName
     */
    private function cloudflareResponse(
        Request $request,
        int $zoneCreateStatus = 200,
        string $zoneStatus = 'active',
        array $zonesByName = [],
    ): PromiseInterface {
        $url = $request->url();
        $method = $request->method();

        if ($method === 'GET' && preg_match('#/client/v4/zones/([a-z0-9-]+)$#', $url, $matches) === 1) {
            foreach ($zonesByName as $zone) {
                if (($zone['id'] ?? '') === $matches[1]) {
                    return Http::response(['success' => true, 'result' => $zone], 200);
                }
            }

            return Http::response(['success' => true, 'result' => $this->zonePayload($matches[1], 'izyem.test', $zoneStatus)], 200);
        }

        if ($method === 'GET' && preg_match('#/client/v4/zones(\?|$)#', $url) === 1) {
            $name = strtolower((string) ($request->data()['name'] ?? ''));
            $zone = $zonesByName[$name] ?? null;

            return Http::response([
                'success' => true,
                'result' => $zone !== null ? [$zone] : [],
            ], 200);
        }

        if ($method === 'POST' && $url === 'https://api.cloudflare.com/client/v4/zones') {
            $name = strtolower((string) ($request->data()['name'] ?? 'created.test'));

            return Http::response([
                'success' => true,
                'result' => $this->zonePayload('zone-new', $name, $zoneStatus),
            ], $zoneCreateStatus === 403 ? 403 : 200);
        }

        if ($method === 'GET' && str_contains($url, '/dns_records')) {
            return Http::response(['success' => true, 'result' => []], 200);
        }

        if (in_array($method, ['POST', 'PUT', 'DELETE'], true) && str_contains($url, '/dns_records')) {
            return Http::response(['success' => true, 'result' => ['id' => 'rec-1']], 200);
        }

        return Http::response(['success' => false, 'errors' => [['message' => 'unexpected '.$url]]], 404);
    }

    private function coolifyHappyPath(Request $request): PromiseInterface
    {
        $url = $request->url();
        $method = $request->method();

        if ($method === 'POST' && str_contains($url, '/applications/')) {
            return Http::response([
                'uuid' => 'coolify-app-1',
                'name' => 'deamon-izyem',
                'git_branch' => 'beta',
                'build_pack' => 'dockercompose',
            ], 201);
        }

        if ($method === 'GET' && str_contains($url, '/envs')) {
            return Http::response([], 200);
        }

        if ($method === 'PATCH' && str_contains($url, '/envs/bulk')) {
            return Http::response([], 200);
        }

        if ($method === 'PATCH' && preg_match('#/applications/[^/]+$#', $url) === 1) {
            return Http::response([
                'uuid' => 'coolify-app-1',
                'docker_compose_domains' => data_get($request->data(), 'docker_compose_domains', []),
            ], 200);
        }

        if ($method === 'POST' && str_contains($url, '/deploy')) {
            return Http::response([
                'deployments' => [['deployment_uuid' => 'dep-1']],
            ], 200);
        }

        if ($method === 'GET' && str_contains($url, '/deployments/dep-1')) {
            return Http::response(['uuid' => 'dep-1', 'status' => 'finished'], 200);
        }

        return Http::response(['error' => 'unexpected '.$url], 404);
    }
}
