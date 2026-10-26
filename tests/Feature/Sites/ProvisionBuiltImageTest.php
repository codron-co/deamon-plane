<?php

namespace Tests\Feature\Sites;

use App\Enums\Channel;
use App\Enums\DeploymentStatus;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Jobs\ProvisionSiteJob;
use App\Models\AuditLog;
use App\Models\CloudflareSetting;
use App\Models\CoolifySetting;
use App\Models\DatabaseServer;
use App\Models\RedisServer;
use App\Models\Site;
use App\Models\User;
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
 * Build once, run many: a new site's first deploy runs the newest image its
 * channel builder finished (SiteProvisioner::provisionOnCoolify).
 */
class ProvisionBuiltImageTest extends TestCase
{
    use RefreshDatabase;

    private const BUILT = '1111111111111111111111111111111111111111';

    private const NEWER_FAILED = '2222222222222222222222222222222222222222';

    /**
     * Rows of the beta builder's `GET /deployments/applications/{uuid}`.
     *
     * @var list<array<string, mixed>>
     */
    private array $builds = [];

    /**
     * The Coolify app's env as the fake holds it.
     *
     * @var array<string, string>
     */
    private array $env = ['DB_PASSWORD' => '', 'MYSQL_ROOT_PASSWORD' => ''];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        config(['ops.deamon.image_builders' => ['alpha' => 'builder-alpha', 'beta' => 'builder-beta']]);

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
    }

    public function test_the_first_deploy_runs_the_newest_built_commit_with_auto_deploy_off(): void
    {
        $this->builds = [
            ['id' => 41, 'commit' => 'HEAD', 'status' => 'in_progress'],
            ['id' => 40, 'commit' => self::NEWER_FAILED, 'status' => 'failed', 'finished_at' => $this->ago(5)],
            ['id' => 39, 'commit' => self::BUILT, 'status' => 'finished', 'finished_at' => $this->ago(20)],
        ];
        $this->fakeServerClients(calls: 1);
        $this->fakeCoolify();

        $site = $this->draftSite();

        $this->actingAs($this->user())
            ->post(route('ops.sites.provision', $site))
            ->assertRedirect(route('ops.sites.show', $site));

        $site->refresh();
        $this->assertSame(SiteStatus::Active, $site->status);
        $this->assertSame(self::BUILT, $site->coolify_pinned_sha);
        $this->assertFalse($site->coolify_auto_deploy);

        // The pin reached the app before the first deploy, with all three fields.
        $pin = $this->firstRequestIndex(fn (Request $r): bool => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/applications/coolify-app-1')
            && ($r->data()['git_commit_sha'] ?? null) === self::BUILT
            && ($r->data()['is_auto_deploy_enabled'] ?? null) === false
            && ($r->data()['include_source_commit_in_build'] ?? null) === true);
        $firstDeploy = $this->firstRequestIndex(fn (Request $r): bool => $r->method() === 'POST' && str_contains($r->url(), '/deploy?'));
        $this->assertNotNull($pin);
        $this->assertNotNull($firstDeploy);
        $this->assertLessThan($firstDeploy, $pin);

        // Nothing ever pointed the app at the branch head.
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'PATCH'
            && ($r->data()['git_commit_sha'] ?? null) === 'HEAD');

        $audit = AuditLog::query()->where('subject_id', $site->id)->where('action', 'site.built_image_pinned')->first();
        $this->assertSame(self::BUILT, $audit?->after['git_commit_sha'] ?? null);
        $this->assertSame('beta', $audit?->after['channel'] ?? null);
    }

    public function test_reprovision_repeats_the_pin_on_the_existing_app(): void
    {
        $this->builds = [['id' => 39, 'commit' => self::BUILT, 'status' => 'finished', 'finished_at' => $this->ago(20)]];
        $this->fakeServerClients(calls: 1);
        $this->fakeCoolify();

        $site = $this->draftSite(['status' => SiteStatus::Error, 'coolify_app_uuid' => 'coolify-app-1']);

        $this->actingAs($this->user())->post(route('ops.sites.provision', $site));

        $site->refresh();
        $this->assertSame(SiteStatus::Active, $site->status);
        $this->assertSame(self::BUILT, $site->coolify_pinned_sha);
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST' && str_contains($r->url(), 'coolify.test/api/v1/applications'));
        $this->assertSame(1, collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->method() === 'PATCH'
            && str_ends_with($pair[0]->url(), '/applications/coolify-app-1')
            && ($pair[0]->data()['git_commit_sha'] ?? null) === self::BUILT
            && ($pair[0]->data()['include_source_commit_in_build'] ?? null) === true)->count());
    }

    public function test_no_finished_build_fails_the_provision_before_anything_is_created(): void
    {
        $this->builds = [
            ['id' => 41, 'commit' => 'HEAD', 'status' => 'in_progress'],
            ['id' => 40, 'commit' => self::NEWER_FAILED, 'status' => 'failed', 'finished_at' => $this->ago(5)],
        ];
        $this->fakeServerClients(calls: 0);
        $this->fakeCoolify();

        $site = $this->draftSite(['status' => SiteStatus::Provisioning]);

        ProvisionSiteJob::dispatchSync((string) $site->id);

        $site->refresh();
        $this->assertSame(SiteStatus::Error, $site->status);
        $this->assertNull($site->coolify_app_uuid);

        $failed = AuditLog::query()->where('subject_id', $site->id)->where('action', 'site.provision_failed')->first();
        $this->assertNotNull($failed);
        $this->assertSame(__('site_ops.image.no_build', ['channel' => 'beta']), $failed->after['error'] ?? null);

        $deployment = $site->deployments()->latest('id')->first();
        $this->assertSame(DeploymentStatus::Failed, $deployment?->status);

        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/deploy?'));
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST' && str_contains($r->url(), 'coolify.test/api/v1/applications'));
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'PATCH' && str_contains($r->url(), 'coolify.test'));
    }

    public function test_a_channel_without_a_builder_provisions_as_before(): void
    {
        config(['ops.deamon.image_builders' => ['alpha' => '', 'beta' => '']]);
        $this->fakeServerClients(calls: 1);
        $this->fakeCoolify();

        $site = $this->draftSite();

        $this->actingAs($this->user())->post(route('ops.sites.provision', $site));

        $this->assertSame(SiteStatus::Active, $site->fresh()->status);
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/deployments/applications/'));
        Http::assertNotSent(fn (Request $r): bool => array_key_exists('include_source_commit_in_build', $r->method() === 'PATCH' ? $r->data() : []));
    }

    private function ago(int $minutes): string
    {
        return now()->utc()->subMinutes($minutes)->toIso8601ZuluString();
    }

    private function fakeServerClients(int $calls): void
    {
        $this->mock(DatabaseServerClient::class, function (MockInterface $mock) use ($calls): void {
            $mock->shouldReceive('ensureSiteDatabase')
                ->times($calls)
                ->andReturnUsing(fn (DatabaseServer $server, Site $site): array => SiteDatabasePlacement::namesFor($site));
        });

        $this->mock(RedisServerClient::class, function (MockInterface $mock) use ($calls): void {
            $mock->shouldReceive('ensureSiteUser')
                ->times($calls)
                ->andReturnUsing(function (RedisServer $server, Site $site): void {
                    if (blank($site->redis_password_encrypted)) {
                        $site->forceFill(['redis_password_encrypted' => Str::password(40, symbols: false)])->save();
                    }
                });
        });
    }

    private function fakeCoolify(string $appUuid = 'coolify-app-1'): void
    {
        Http::fake(function (Request $request) use ($appUuid) {
            if ($cf = $this->cloudflareResponse($request)) {
                return $cf;
            }

            $url = $request->url();
            $method = $request->method();
            $app = [
                'uuid' => $appUuid,
                'name' => 'deamon-izyem',
                'git_branch' => 'beta',
                'build_pack' => 'dockercompose',
                'docker_compose_location' => '/docker-compose.coolify.yml',
                'docker_compose_domains' => [['name' => 'app', 'domain' => 'https://shop.izyem.example.test']],
            ];

            if ($method === 'GET' && str_contains($url, '/deployments/applications/builder-beta')) {
                return Http::response(['count' => count($this->builds), 'deployments' => $this->builds], 200);
            }

            if ($method === 'POST' && str_contains($url, '/applications/')) {
                return Http::response($app, 201);
            }

            if ($method === 'GET' && str_contains($url, '/envs')) {
                $rows = [];
                foreach ($this->env as $key => $value) {
                    $rows[] = ['key' => $key, 'value' => $value, 'is_preview' => false, 'uuid' => 'env-'.md5($key)];
                }

                return Http::response($rows, 200);
            }

            if ($method === 'PATCH' && str_contains($url, '/envs/bulk')) {
                foreach ($request->data()['data'] ?? [] as $row) {
                    if (is_array($row) && isset($row['key'])) {
                        $this->env[(string) $row['key']] = (string) ($row['value'] ?? '');
                    }
                }

                return Http::response([], 200);
            }

            if (str_contains($url, '/applications/'.$appUuid) && in_array($method, ['GET', 'PATCH'], true)) {
                return Http::response($app, 200);
            }

            if ($method === 'POST' && str_contains($url, '/deploy')) {
                return Http::response([
                    'deployments' => [['resource_uuid' => $appUuid, 'deployment_uuid' => 'dep-1', 'message' => 'queued']],
                ], 200);
            }

            if ($method === 'GET' && str_contains($url, '/deployments/dep-1')) {
                return Http::response(['uuid' => 'dep-1', 'status' => 'finished', 'commit' => self::BUILT], 200);
            }

            return Http::response(['error' => 'unexpected '.$url], 404);
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
     */
    private function firstRequestIndex(callable $match): ?int
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
