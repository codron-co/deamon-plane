<?php

namespace Tests\Feature\Sites;

use App\Enums\Channel;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\AuditLog;
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

class ProvisionSiteCloudflareTest extends TestCase
{
    use RefreshDatabase;

    private const CF_TOKEN = 'cf-provision-secret-token';

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

    public function test_missing_cloudflare_settings_does_not_start_or_call_coolify(): void
    {
        $site = $this->draftSite();

        $this->actingAs($this->user(OpsRole::Operator, ['locale' => 'tr']))
            ->post(route('ops.sites.provision', $site))
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('error');

        $error = session('error');
        $this->assertIsString($error);
        $this->assertStringContainsString('Cloudflare bağlı değil', $error);
        $this->assertSame(SiteStatus::Draft, $site->fresh()->status);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'site.provision_started',
            'subject_id' => $site->id,
        ]);
    }

    public function test_cloudflare_dns_edit_403_does_not_create_coolify_app(): void
    {
        $this->seedCloudflare();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'api.cloudflare.com')) {
                return $this->cloudflareResponse($request, dnsCreateStatus: 403, zonesByName: [
                    'shop.izyem.example.test' => $this->zonePayload('zone-existing', 'shop.izyem.example.test'),
                ]);
            }

            return Http::response(['error' => 'coolify must not be called'], 500);
        });

        $site = $this->draftSite();

        $this->actingAs($this->user(OpsRole::Operator, ['locale' => 'tr']))
            ->post(route('ops.sites.provision', $site))
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('error');

        $error = session('error');
        $this->assertIsString($error);
        $this->assertStringContainsString('DNS → Edit', $error);
        $this->assertStringNotContainsString(self::CF_TOKEN, $error);

        $site->refresh();
        $this->assertSame(SiteStatus::Error, $site->status);
        $this->assertNull($site->coolify_app_uuid);

        Http::assertNotSent(function (Request $request): bool {
            return str_contains($request->url(), 'coolify.test')
                && $request->method() === 'POST'
                && str_contains($request->url(), '/applications/');
        });

        Http::assertNotSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.cloudflare.com/client/v4/zones';
        });
    }

    public function test_existing_zone_upserts_dns_then_creates_coolify_app(): void
    {
        $this->seedCloudflare();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'api.cloudflare.com')) {
                return $this->cloudflareResponse($request, zonesByName: [
                    'shop.izyem.example.test' => $this->zonePayload('zone-existing', 'shop.izyem.example.test'),
                ]);
            }

            return $this->coolifyHappyPath($request);
        });

        $site = $this->draftSite();

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.provision', $site))
            ->assertRedirect(route('ops.sites.show', $site));

        $site->refresh();
        $this->assertSame(SiteStatus::Active, $site->status);
        $this->assertSame('coolify-app-1', $site->coolify_app_uuid);
        $this->assertSame('zone-existing', $site->cloudflare_zone_id);
        $this->assertSame(['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'], $site->cloudflare_nameservers);
        $this->assertNotNull($site->dns_applied_at);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'site.cloudflare_ready',
            'subject_id' => $site->id,
        ]);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://coolify.test/api/v1/applications/public';
        });

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), '/dns_records')) {
                return false;
            }

            $body = $request->data();

            return ($body['type'] ?? null) === 'A'
                && ($body['proxied'] ?? null) === false
                && ($body['content'] ?? null) === '72.62.117.147';
        });

        Http::assertNotSent(function (Request $request): bool {
            return $request->method() === 'DELETE' && str_contains($request->url(), 'api.cloudflare.com');
        });

        $ready = AuditLog::query()
            ->where('subject_id', $site->id)
            ->where('action', 'site.cloudflare_ready')
            ->first();
        $this->assertNotNull($ready);
        $encoded = json_encode([$ready->before, $ready->after], JSON_UNESCAPED_SLASHES);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString(self::CF_TOKEN, $encoded);
    }

    public function test_nested_hostname_on_codron_zone_writes_star_and_relative_a(): void
    {
        $this->seedCloudflare();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'api.cloudflare.com')) {
                return $this->cloudflareResponse($request, zonesByName: [
                    'codron.co' => $this->zonePayload('zone-codron', 'codron.co'),
                ]);
            }

            return $this->coolifyHappyPath($request);
        });

        $site = $this->draftSite(['primary_domain' => 'test.deamon.codron.co']);

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.provision', $site))
            ->assertRedirect(route('ops.sites.show', $site));

        $site->refresh();
        $this->assertSame(SiteStatus::Active, $site->status);
        $this->assertSame('test.deamon.codron.co', $site->primary_domain);
        $this->assertSame('zone-codron', $site->cloudflare_zone_id);

        $this->assertNoZoneCreate();
        $this->assertSame(['*', 'test.deamon'], $this->postedANames());
    }

    public function test_existing_star_is_left_in_place_and_site_a_is_added(): void
    {
        $this->seedCloudflare();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'api.cloudflare.com')) {
                return $this->cloudflareResponse($request, zonesByName: [
                    'codron.co' => $this->zonePayload('zone-codron', 'codron.co'),
                ], dnsRecords: [
                    [
                        'id' => 'star-1',
                        'type' => 'A',
                        'name' => '*.codron.co',
                        'content' => '72.62.117.147',
                        'proxied' => false,
                    ],
                ]);
            }

            return $this->coolifyHappyPath($request);
        });

        $site = $this->draftSite(['primary_domain' => 'amber-harbor.codron.co']);

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.provision', $site))
            ->assertRedirect(route('ops.sites.show', $site));

        $this->assertSame('amber-harbor.codron.co', $site->fresh()->primary_domain);
        $this->assertSame(['amber-harbor'], $this->postedANames());
        $this->assertNoZoneCreate();
    }

    public function test_deep_nested_hostname_uses_relative_record_on_parent_zone(): void
    {
        $this->seedCloudflare();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'api.cloudflare.com')) {
                return $this->cloudflareResponse($request, zonesByName: [
                    'codron.co' => $this->zonePayload('zone-codron', 'codron.co'),
                ]);
            }

            return $this->coolifyHappyPath($request);
        });

        $site = $this->draftSite(['primary_domain' => 'a.b.c.deamon.codron.co']);

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.provision', $site))
            ->assertRedirect(route('ops.sites.show', $site));

        $this->assertSame('a.b.c.deamon.codron.co', $site->fresh()->primary_domain);
        $this->assertSame(['*', 'a.b.c.deamon'], $this->postedANames());
        $this->assertNoZoneCreate();
    }

    public function test_unbound_custom_domain_falls_back_to_wildcard_preview_then_creates_coolify_app(): void
    {
        $this->seedCloudflare();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'api.cloudflare.com')) {
                return $this->cloudflareResponse($request, zonesByName: [
                    'codron.co' => $this->zonePayload('zone-codron', 'codron.co'),
                ]);
            }

            return $this->coolifyHappyPath($request);
        });

        $site = $this->draftSite(['primary_domain' => 'shop.customer.example']);

        $this->actingAs($this->user(OpsRole::Operator))
            ->post(route('ops.sites.provision', $site))
            ->assertRedirect(route('ops.sites.show', $site));

        $site->refresh();
        $this->assertSame(SiteStatus::Active, $site->status);
        $this->assertNotSame('shop.customer.example', $site->primary_domain);
        $this->assertMatchesRegularExpression('/^[a-z]+-[a-z]+\.codron\.co$/', (string) $site->primary_domain);
        $this->assertSame('zone-codron', $site->cloudflare_zone_id);

        $this->assertDatabaseHas('site_domains', [
            'site_id' => $site->id,
            'domain' => 'shop.customer.example',
            'is_primary' => false,
        ]);
        $this->assertDatabaseHas('site_domains', [
            'site_id' => $site->id,
            'domain' => $site->primary_domain,
            'is_primary' => true,
        ]);

        $relative = explode('.', (string) $site->primary_domain)[0];
        $this->assertSame(['*', $relative], $this->postedANames());
        $this->assertNoZoneCreate();
    }

    public function test_unbound_custom_domain_without_wildcard_zone_does_not_create_coolify_app(): void
    {
        $this->seedCloudflare();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'api.cloudflare.com')) {
                return $this->cloudflareResponse($request);
            }

            return Http::response(['error' => 'coolify must not be called'], 500);
        });

        $site = $this->draftSite(['primary_domain' => 'shop.customer.example']);

        $this->actingAs($this->user(OpsRole::Operator, ['locale' => 'tr']))
            ->post(route('ops.sites.provision', $site))
            ->assertRedirect(route('ops.sites.show', $site))
            ->assertSessionHas('error');

        $error = session('error');
        $this->assertIsString($error);
        $this->assertStringContainsString('codron.co', $error);

        $this->assertNull($site->fresh()->coolify_app_uuid);
        Http::assertNotSent(function (Request $request): bool {
            return str_contains($request->url(), 'coolify.test')
                && $request->method() === 'POST'
                && str_contains($request->url(), '/applications/');
        });
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function user(OpsRole $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role->value);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function seedCloudflare(array $attributes = []): void
    {
        CloudflareSetting::factory()->create(array_merge([
            'account_id' => self::ACCOUNT_ID,
            'api_token' => self::CF_TOKEN,
            'origin_ipv4' => '72.62.117.147',
            'mail_template_enabled' => true,
            'proxied' => false,
            'is_enabled' => true,
            'is_default' => true,
        ], $attributes));
    }

    /**
     * @return array<string, mixed>
     */
    private function zonePayload(string $id, string $name): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'name_servers' => ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $zonesByName
     * @param  list<array<string, mixed>>  $dnsRecords
     */
    private function cloudflareResponse(
        Request $request,
        int $zoneCreateStatus = 200,
        array $zonesByName = [],
        array $dnsRecords = [],
        int $dnsCreateStatus = 200,
    ): PromiseInterface {
        $url = $request->url();
        $method = $request->method();

        if ($method === 'GET' && preg_match('#/client/v4/zones(\?|$)#', $url) === 1) {
            $name = strtolower((string) ($request->data()['name'] ?? ''));
            $zone = $zonesByName[$name] ?? null;

            return Http::response([
                'success' => true,
                'result' => $zone !== null ? [$zone] : [],
            ], 200);
        }

        if ($method === 'POST' && $url === 'https://api.cloudflare.com/client/v4/zones') {
            if ($zoneCreateStatus === 403) {
                return Http::response([
                    'success' => false,
                    'errors' => [['code' => 9109, 'message' => 'Unauthorized to create zone']],
                ], 403);
            }

            $name = strtolower((string) ($request->data()['name'] ?? 'created.test'));

            return Http::response([
                'success' => true,
                'result' => $this->zonePayload('zone-new', $name),
            ], 200);
        }

        if ($method === 'GET' && str_contains($url, '/dns_records')) {
            $type = strtoupper((string) ($request->data()['type'] ?? ''));
            $name = strtolower((string) ($request->data()['name'] ?? ''));
            $matches = array_values(array_filter(
                $dnsRecords,
                static function (array $row) use ($type, $name): bool {
                    if ($type !== '' && strtoupper((string) ($row['type'] ?? '')) !== $type) {
                        return false;
                    }

                    return $name === '' || strtolower((string) ($row['name'] ?? '')) === $name;
                },
            ));

            return Http::response(['success' => true, 'result' => $matches], 200);
        }

        if ($method === 'POST' && str_contains($url, '/dns_records')) {
            if ($dnsCreateStatus === 403) {
                return Http::response([
                    'success' => false,
                    'errors' => [['code' => 9109, 'message' => 'Unauthorized to edit DNS']],
                ], 403);
            }

            return Http::response([
                'success' => true,
                'result' => ['id' => 'rec-1', 'type' => $request->data()['type'] ?? 'A'],
            ], 200);
        }

        if ($method === 'PUT' && str_contains($url, '/dns_records')) {
            return Http::response([
                'success' => true,
                'result' => ['id' => 'rec-1', 'type' => $request->data()['type'] ?? 'A'],
            ], 200);
        }

        return Http::response(['success' => false, 'errors' => [['message' => 'unexpected '.$url]]], 404);
    }

    /**
     * @return list<string>
     */
    private function postedANames(): array
    {
        $names = [];
        foreach (Http::recorded() as $pair) {
            $request = $pair[0];
            if ($request->method() !== 'POST' || ! str_contains($request->url(), '/dns_records')) {
                continue;
            }

            $body = $request->data();
            if (($body['type'] ?? null) === 'A' && isset($body['name']) && is_string($body['name'])) {
                $names[] = $body['name'];
            }
        }

        return $names;
    }

    private function assertNoZoneCreate(): void
    {
        Http::assertNotSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.cloudflare.com/client/v4/zones';
        });
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
                'docker_compose_location' => '/docker-compose.coolify.yml',
            ], 201);
        }

        if ($method === 'GET' && str_contains($url, '/envs')) {
            return Http::response([
                ['key' => 'APP_KEY', 'value' => 'base64:keep', 'is_preview' => false],
                ['key' => 'DEAMON_SITE_NAME', 'value' => 'Izyem', 'is_preview' => false],
                ['key' => 'DB_PASSWORD', 'value' => '', 'is_preview' => false],
                ['key' => 'MYSQL_ROOT_PASSWORD', 'value' => '', 'is_preview' => false],
                ['key' => 'DEAMON_DEFAULT_ADMIN_PASSWORD', 'value' => '', 'is_preview' => false],
            ], 200);
        }

        if ($method === 'PATCH' && str_contains($url, '/envs/bulk')) {
            return Http::response([], 200);
        }

        if ($method === 'PATCH' && preg_match('#/applications/[^/]+$#', $url) === 1) {
            return Http::response([
                'uuid' => 'coolify-app-1',
                'name' => 'deamon-izyem',
                'docker_compose_domains' => [
                    ['name' => 'app', 'domain' => 'https://shop.izyem.example.test'],
                ],
            ], 200);
        }

        if ($method === 'POST' && str_contains($url, '/deploy')) {
            return Http::response([
                'deployments' => [[
                    'resource_uuid' => 'coolify-app-1',
                    'deployment_uuid' => 'dep-1',
                    'message' => 'queued',
                ]],
            ], 200);
        }

        if ($method === 'GET' && str_contains($url, '/deployments/dep-1')) {
            return Http::response([
                'uuid' => 'dep-1',
                'status' => 'finished',
                'commit' => 'abc123def',
            ], 200);
        }

        return Http::response(['error' => 'unexpected '.$url], 404);
    }
}
