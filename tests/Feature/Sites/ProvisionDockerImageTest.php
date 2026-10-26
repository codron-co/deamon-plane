<?php

namespace Tests\Feature\Sites;

use App\Enums\Channel;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CloudflareSetting;
use App\Models\CoolifyEnvDefault;
use App\Models\CoolifySetting;
use App\Models\DatabaseServer;
use App\Models\RedisServer;
use App\Models\Site;
use App\Models\User;
use App\Services\Coolify\DockerImageLabels;
use App\Services\Databases\DatabaseServerClient;
use App\Services\Databases\RedisServerClient;
use App\Services\Databases\SiteDatabasePlacement;
use Database\Seeders\RoleSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * A new site is a Coolify "Docker Image" app (ops.deamon.provision_pack), set up like
 * tools/ops/deamon-image/to-dockerimage.sh: image on the channel's newest built commit,
 * compose constants as env, three volumes, domains + Deamon labels, then the usual flow.
 */
class ProvisionDockerImageTest extends TestCase
{
    use RefreshDatabase;

    private const BUILT = '1111111111111111111111111111111111111111';

    private const APP = 'imgapp0000000000000000001';

    /** @var array<string, string> */
    private array $env = [];

    /** @var list<array{name: string, mount_path: string}> */
    private array $storages = [];

    private string $fqdn = '';

    private string $buildPack = 'dockerimage';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        config([
            'ops.deamon.image_builders' => ['alpha' => 'builder-alpha', 'beta' => 'builder-beta'],
            'ops.deamon.provision_pack' => 'dockerimage',
        ]);

        CoolifySetting::factory()->create([
            'base_url' => 'https://coolify.test',
            'api_token' => 'test-coolify-token',
            'default_project_uuid' => 'proj_test',
            'default_server_uuid' => 'srv_test',
        ]);

        CloudflareSetting::factory()->create([
            'account_id' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            'api_token' => 'cf-provision-test-token',
            'is_enabled' => true,
            'is_default' => true,
        ]);

        DatabaseServer::factory()->default()->create();
        RedisServer::factory()->default()->create();
        $this->fakeServerClients();
    }

    public function test_a_new_site_is_created_as_a_docker_image_app_on_the_built_commit(): void
    {
        $this->fakeCoolify();
        $site = $this->draftSite();

        $this->actingAs($this->user())
            ->post(route('ops.sites.provision', $site))
            ->assertRedirect(route('ops.sites.show', $site));

        $site->refresh();
        $this->assertSame(SiteStatus::Active, $site->status);
        $this->assertSame(self::APP, $site->coolify_app_uuid);
        $this->assertSame(self::BUILT, $site->coolify_pinned_sha);

        $hosts = DockerImageLabels::hosts($site->coolifyDomainBinding());
        $this->assertNotSame([], $hosts);
        $domains = implode(',', array_map(static fn (string $h): string => 'https://'.$h, $hosts));

        // The create body is the one to-dockerimage.sh sends.
        $create = $this->requests(fn (Request $r): bool => $r->method() === 'POST' && str_ends_with($r->url(), '/api/v1/applications/dockerimage'));
        $this->assertCount(1, $create);
        $body = $create[0]->data();
        $this->assertSame([
            'project_uuid' => 'proj_test',
            'server_uuid' => 'srv_test',
            'environment_name' => 'main',
            'destination_uuid' => '31gww8sy8nazbukhozgzlbzw',
            'name' => 'deamon-izyem',
            'description' => 'Deamon site (docker image, zero-downtime)',
            'docker_registry_image_name' => '127.0.0.1:5000/deamon-cms',
            'docker_registry_image_tag' => self::BUILT,
            'ports_exposes' => '8080',
            'domains' => $domains,
            'force_domain_override' => true,
            'limits_cpus' => '1.5',
            'limits_memory' => '768m',
            'custom_docker_run_options' => '--pids-limit 256',
            'health_check_enabled' => true,
            'health_check_path' => '/up',
            'health_check_port' => '8080',
            'health_check_interval' => 5,
            'health_check_timeout' => 5,
            'health_check_retries' => 36,
            'health_check_start_period' => 5,
            'is_auto_deploy_enabled' => false,
            'autogenerate_domain' => false,
            'instant_deploy' => false,
        ], $body);

        // No compose app, no compose domains.
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST'
            && preg_match('#/applications/(public|private-github-app|private-deploy-key)$#', $r->url()) === 1);
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'PATCH'
            && array_key_exists('docker_compose_domains', $r->data()));

        // Compose constants + the URL env are Coolify env rows now. A key the channel
        // catalog also lists is the catalog's (the test fixture still has APP_ENV).
        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/applications/'.self::APP.'/envs/bulk')
            && collect($r->data()['data'] ?? [])->pluck('value', 'key')->all() === array_map('strval', config('ops.deamon.docker_image.env')));
        $catalog = CoolifyEnvDefault::query()->forChannel(Channel::Beta)->pluck('key')->all();
        $this->assertSame('Deamon', $this->env['APP_NAME'] ?? null);
        $this->assertSame('redis', $this->env['QUEUE_CONNECTION'] ?? null);
        $this->assertSame('', $this->env['CREATIVE_RENDER_URL'] ?? null);
        foreach (array_diff_key(config('ops.deamon.docker_image.env'), array_flip($catalog)) as $key => $value) {
            $this->assertSame((string) $value, $this->env[$key] ?? null, $key);
        }
        $this->assertSame('https://'.$hosts[0], $this->env['APP_URL'] ?? null);
        $this->assertSame($hosts[0], $this->env['DEAMON_SITE_HOST'] ?? null);
        // The usual provision env still lands (shared MySQL / Redis placement, agent secret).
        $this->assertSame('deamon-db-mysql', $this->env['DB_HOST'] ?? null);
        $this->assertArrayHasKey('REDIS_HOST', $this->env);
        $this->assertArrayHasKey('CONTROL_PLANE_AGENT_SECRET', $this->env);

        // Three named persistent volumes.
        $this->assertSame([
            ['name' => 'deamon-storage', 'mount_path' => '/var/www/html/storage/app/public'],
            ['name' => 'deamon-private', 'mount_path' => '/var/www/html/storage/app/private'],
            ['name' => 'deamon-themes', 'mount_path' => '/var/www/html/themes'],
        ], $this->storages);
        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/storages') && ($r->data()['type'] ?? null) === 'persistent');

        // Domains + labels PATCH before the first deploy.
        $labelPatch = $this->firstIndex(fn (Request $r): bool => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/applications/'.self::APP)
            && isset($r->data()['custom_labels']));
        $firstDeploy = $this->firstIndex(fn (Request $r): bool => $r->method() === 'POST' && str_contains($r->url(), '/deploy?'));
        $this->assertNotNull($labelPatch);
        $this->assertNotNull($firstDeploy);
        $this->assertLessThan($firstDeploy, $labelPatch);

        $patch = Http::recorded()[$labelPatch][0]->data();
        $this->assertSame($domains, $patch['domains']);
        $this->assertTrue($patch['force_domain_override']);
        $this->assertSame(DockerImageLabels::for(self::APP, $hosts), base64_decode((string) $patch['custom_labels'], true));

        // Pinned by the image tag, never by git_commit_sha.
        Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH'
            && ($r->data()['docker_registry_image_tag'] ?? null) === self::BUILT
            && ($r->data()['is_auto_deploy_enabled'] ?? null) === false);
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'PATCH' && array_key_exists('git_commit_sha', $r->data()));
    }

    public function test_reprovision_does_not_add_volumes_twice(): void
    {
        $this->storages = [
            ['name' => 'deamon-storage', 'mount_path' => '/var/www/html/storage/app/public'],
            ['name' => 'deamon-private', 'mount_path' => '/var/www/html/storage/app/private'],
            ['name' => 'deamon-themes', 'mount_path' => '/var/www/html/themes'],
        ];
        $this->fakeCoolify();
        $site = $this->draftSite(['status' => SiteStatus::Error, 'coolify_app_uuid' => self::APP]);

        $this->actingAs($this->user())->post(route('ops.sites.provision', $site));

        $this->assertSame(SiteStatus::Active, $site->fresh()->status);
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST' && str_contains($r->url(), '/api/v1/applications/dockerimage'));
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST' && str_ends_with($r->url(), '/storages'));
        Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH' && isset($r->data()['custom_labels']));
    }

    public function test_compose_pack_keeps_the_compose_app(): void
    {
        config(['ops.deamon.provision_pack' => 'dockercompose']);
        $this->buildPack = 'dockercompose';
        $this->fakeCoolify();
        $site = $this->draftSite();

        $this->actingAs($this->user())->post(route('ops.sites.provision', $site));

        $this->assertSame(SiteStatus::Active, $site->fresh()->status);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && ($r->data()['build_pack'] ?? null) === 'dockercompose');
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/applications/dockerimage'));
        Http::assertNotSent(fn (Request $r): bool => str_ends_with($r->url(), '/storages'));
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'PATCH' && isset($r->data()['custom_labels']));
        Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH'
            && ($r->data()['git_commit_sha'] ?? null) === self::BUILT);
    }

    public function test_a_channel_without_a_builder_gets_a_compose_app(): void
    {
        config(['ops.deamon.image_builders' => ['alpha' => '', 'beta' => '']]);
        $this->buildPack = 'dockercompose';
        $this->fakeCoolify();
        $site = $this->draftSite();

        $this->actingAs($this->user())->post(route('ops.sites.provision', $site));

        $this->assertSame(SiteStatus::Active, $site->fresh()->status);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && ($r->data()['build_pack'] ?? null) === 'dockercompose');
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/applications/dockerimage'));
    }

    private function fakeServerClients(): void
    {
        $this->mock(DatabaseServerClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('ensureSiteDatabase')
                ->andReturnUsing(fn (DatabaseServer $server, Site $site): array => SiteDatabasePlacement::namesFor($site));
        });

        $this->mock(RedisServerClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('ensureSiteUser')
                ->andReturnUsing(function (RedisServer $server, Site $site): void {
                    if (blank($site->redis_password_encrypted)) {
                        $site->forceFill(['redis_password_encrypted' => Str::password(40, symbols: false)])->save();
                    }
                });
        });
    }

    private function fakeCoolify(): void
    {
        Http::fake(function (Request $request) {
            if ($cf = $this->cloudflareResponse($request)) {
                return $cf;
            }

            $url = $request->url();
            $method = $request->method();
            $app = fn (): array => [
                'uuid' => self::APP,
                'name' => 'deamon-izyem',
                'build_pack' => $this->buildPack,
                'git_branch' => 'beta',
                'docker_registry_image_tag' => self::BUILT,
                'fqdn' => $this->fqdn !== '' ? $this->fqdn : null,
            ];

            if ($method === 'GET' && str_contains($url, '/deployments/applications/builder-beta')) {
                return Http::response(['count' => 1, 'deployments' => [
                    ['id' => 39, 'commit' => self::BUILT, 'status' => 'finished', 'finished_at' => now()->utc()->subMinutes(20)->toIso8601ZuluString()],
                ]], 200);
            }

            if ($method === 'POST' && preg_match('#/api/v1/applications/(dockerimage|public|private-github-app|private-deploy-key)$#', $url) === 1) {
                $this->fqdn = (string) ($request->data()['domains'] ?? '');

                return Http::response(['uuid' => self::APP, 'domains' => $this->fqdn], 201);
            }

            if (str_ends_with($url, '/storages')) {
                if ($method === 'POST') {
                    $this->storages[] = ['name' => (string) $request->data()['name'], 'mount_path' => (string) $request->data()['mount_path']];

                    return Http::response(['uuid' => 'vol-'.count($this->storages)], 201);
                }

                return Http::response(['persistent_storages' => $this->storages, 'file_storages' => []], 200);
            }

            if (str_contains($url, '/envs')) {
                if ($method === 'GET') {
                    $rows = [];
                    foreach ($this->env as $key => $value) {
                        $rows[] = ['key' => $key, 'value' => $value, 'is_preview' => false, 'uuid' => 'env-'.md5($key)];
                    }

                    return Http::response($rows, 200);
                }

                $rows = str_ends_with($url, '/envs/bulk') ? ($request->data()['data'] ?? []) : [$request->data()];
                foreach ($rows as $row) {
                    if (is_array($row) && isset($row['key'])) {
                        $this->env[(string) $row['key']] = (string) ($row['value'] ?? '');
                    }
                }

                return Http::response([], 200);
            }

            if (str_ends_with($url, '/applications/'.self::APP)) {
                if ($method === 'PATCH' && isset($request->data()['domains'])) {
                    $this->fqdn = (string) $request->data()['domains'];
                }

                return Http::response($app(), 200);
            }

            if ($method === 'POST' && str_contains($url, '/deploy')) {
                return Http::response([
                    'deployments' => [['resource_uuid' => self::APP, 'deployment_uuid' => 'dep-1', 'message' => 'queued']],
                ], 200);
            }

            if ($method === 'GET' && str_contains($url, '/deployments/dep-1')) {
                return Http::response(['uuid' => 'dep-1', 'status' => 'finished', 'commit' => self::BUILT], 200);
            }

            return Http::response(['error' => 'unexpected '.$method.' '.$url], 404);
        });
    }

    private function cloudflareResponse(Request $request): ?PromiseInterface
    {
        $url = $request->url();
        if (! str_contains($url, 'api.cloudflare.com')) {
            return null;
        }

        $zone = [
            'id' => 'zone-shop-izyem-example-test',
            'name' => 'shop.izyem.example.test',
            'name_servers' => ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'],
        ];

        if ($request->method() === 'GET' && preg_match('#/client/v4/zones/[a-z0-9-]+$#', $url) === 1) {
            return Http::response(['success' => true, 'result' => $zone], 200);
        }

        if ($request->method() === 'GET' && preg_match('#/client/v4/zones(\?|$)#', $url) === 1) {
            return Http::response(['success' => true, 'result' => [$zone]], 200);
        }

        if ($request->method() === 'GET' && str_contains($url, '/dns_records')) {
            return Http::response(['success' => true, 'result' => []], 200);
        }

        if (in_array($request->method(), ['POST', 'PUT'], true) && str_contains($url, '/dns_records')) {
            return Http::response(['success' => true, 'result' => ['id' => 'rec-1', 'type' => $request->data()['type'] ?? 'A']], 200);
        }

        return Http::response(['success' => false, 'errors' => [['message' => 'unexpected '.$url]]], 404);
    }

    /**
     * @param  callable(Request): bool  $match
     * @return list<Request>
     */
    private function requests(callable $match): array
    {
        return collect(Http::recorded())->map(fn (array $pair): Request => $pair[0])->filter($match)->values()->all();
    }

    /**
     * @param  callable(Request): bool  $match
     */
    private function firstIndex(callable $match): ?int
    {
        foreach (Http::recorded() as $index => [$request]) {
            if ($match($request)) {
                return $index;
            }
        }

        return null;
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

    private function user(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Operator->value);

        return $user;
    }
}
