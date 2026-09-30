<?php

namespace Tests\Feature\Sites;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CoolifyConnection;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use App\Services\Cloudflare\CloudflareApiException;
use App\Services\Cloudflare\CloudflareZoneService;
use App\Services\Sites\CoolifyDeploySettings;
use App\Services\Sites\DeployPreflight;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeployPreflightTest extends TestCase
{
    use RefreshDatabase;

    private const APP = 'preflight-app-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        Queue::fake();
        config(['ops.deploy_preflight.enabled' => true]);
    }

    public function test_redeploy_binds_plane_hosts_on_coolify_before_the_deploy(): void
    {
        $site = $this->site();
        $calls = $this->fakeCoolify(domains: '', agentSecret: 'plane-agent-secret');

        app(CoolifyDeploySettings::class)->redeploy($site, $this->operator());

        $patchAt = $this->firstIndex($calls, fn (Request $r) => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/applications/'.self::APP)
            && array_key_exists('docker_compose_domains', $r->data()));
        $deployAt = $this->firstIndex($calls, fn (Request $r) => str_contains($r->url(), '/deploy'));

        $this->assertNotNull($patchAt, 'Plane hosts were not bound on Coolify.');
        $this->assertNotNull($deployAt, 'The deploy did not start.');
        $this->assertLessThan($deployAt, $patchAt, 'Domains must be bound before the deploy starts.');

        $audit = $site->auditLogs()->where('action', 'site.deploy_preflight')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertContains('bind_domains', $audit->after['fixed']);
    }

    public function test_redeploy_writes_planes_agent_secret_when_coolify_drifted_without_rotating(): void
    {
        $site = $this->site();
        $calls = $this->fakeCoolify(domains: 'https://shop.example.test,https://www.shop.example.test', agentSecret: 'stale-secret-from-an-old-app');

        app(CoolifyDeploySettings::class)->redeploy($site, $this->operator());

        $this->assertSame('plane-agent-secret', (string) $site->fresh()->agent_secret_encrypted, 'The secret must not rotate.');

        $secretWrite = $this->firstIndex($calls, function (Request $r): bool {
            if (! in_array($r->method(), ['PATCH', 'POST'], true) || ! str_contains($r->url(), '/envs')) {
                return false;
            }

            return str_contains(json_encode($r->data()) ?: '', 'plane-agent-secret');
        });
        $deployAt = $this->firstIndex($calls, fn (Request $r) => str_contains($r->url(), '/deploy'));

        $this->assertNotNull($secretWrite, "Plane's secret was not written to Coolify.");
        $this->assertLessThan($deployAt, $secretWrite);

        $audit = $site->auditLogs()->where('action', 'site.deploy_preflight')->latest('id')->first();
        $this->assertContains('agent_secret_mismatch', $audit->after['issues']);
        $this->assertFalse($site->auditLogs()->where('action', 'site.agent_secret_rotated')->exists());
    }

    public function test_a_failed_preflight_inspect_never_blocks_the_deploy(): void
    {
        $site = $this->site();
        // The app read fails (inspect cannot run); env and deploy still answer.
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/deploy')) {
                return Http::response(['deployments' => [['deployment_uuid' => 'dep-preflight-down']]], 200);
            }
            if (str_contains($request->url(), '/envs')) {
                return Http::response([], 200);
            }

            return Http::response(['message' => 'Coolify is down'], 500);
        });

        app(CoolifyDeploySettings::class)->redeploy($site, $this->operator());

        $this->assertDatabaseHas('deployments', [
            'site_id' => $site->id,
            'coolify_deployment_uuid' => 'dep-preflight-down',
        ]);
    }

    public function test_preflight_is_off_when_disabled(): void
    {
        config(['ops.deploy_preflight.enabled' => false]);
        $site = $this->site();
        Http::fake(fn () => Http::response(['error' => 'no call expected'], 500));

        $this->assertSame(
            ['checked' => false, 'issues' => [], 'fixed' => [], 'failed' => []],
            app(DeployPreflight::class)->run($site),
        );
        Http::assertNothingSent();
    }

    public function test_cloudflare_tld_error_is_shown_in_turkish_and_a_suffix_is_refused_before_the_api(): void
    {
        app()->setLocale('tr');

        $response = new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(400, [], json_encode([
            'success' => false,
            'errors' => [
                ['code' => 1099, 'message' => 'Please ensure you are providing the root domain and not a TLD (e.g., example.com, not .com)'],
                ['code' => 1099, 'message' => 'Please ensure you are providing the root domain and not a TLD (e.g., example.com, not .com)'],
            ],
        ]) ?: '{}'));
        $exception = CloudflareApiException::fromResponse($response);

        $this->assertStringContainsString('alan adı uzantısı', $exception->getMessage());
        $this->assertStringNotContainsString('Please ensure', $exception->getMessage());
        $this->assertStringContainsString('root domain', $exception->cloudflareMessage());
        $this->assertTrue($exception->isSubdomainRejection());

        try {
            app(CloudflareZoneService::class)->zoneName('com.tr');
            $this->fail('A bare public suffix must be refused.');
        } catch (CloudflareApiException $refused) {
            $this->assertStringContainsString('com.tr', $refused->getMessage());
        }

        $this->assertSame('firma.com.tr', app(CloudflareZoneService::class)->zoneName('www.firma.com.tr'));
    }

    private function site(): Site
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => 'preflight-token',
        ]);
        $site = Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'primary_domain' => 'shop.example.test',
            'coolify_app_uuid' => self::APP,
            'coolify_connection_id' => $connection->id,
            'temporary_domain' => null,
            'cloudflare_zone_status' => 'active',
            'agent_secret_encrypted' => 'plane-agent-secret',
        ]);
        SiteDomain::factory()->primary()->create(['site_id' => $site->id, 'domain' => 'shop.example.test']);
        SiteDomain::factory()->create([
            'site_id' => $site->id,
            'domain' => 'www.shop.example.test',
            'is_www' => true,
            'is_primary' => false,
        ]);

        return $site->fresh();
    }

    /**
     * @return \ArrayObject<int, Request> every request, in order
     */
    private function fakeCoolify(string $domains, string $agentSecret): \ArrayObject
    {
        $calls = new \ArrayObject;

        Http::fake(function (Request $request) use ($calls, $domains, $agentSecret) {
            $calls[] = $request;

            if ($request->method() === 'GET' && str_ends_with($request->url(), '/applications/'.self::APP)) {
                return Http::response([
                    'uuid' => self::APP,
                    'build_pack' => 'dockercompose',
                    'status' => 'running:healthy',
                    'docker_compose_location' => '/docker-compose.coolify.yml',
                    'docker_compose_domains' => [['name' => 'app', 'domain' => $domains]],
                ], 200);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/envs')) {
                return Http::response([
                    ['key' => 'DB_HOST', 'value' => 'mysql'],
                    ['key' => 'DB_CONNECTION', 'value' => 'mysql'],
                    ['key' => 'DB_PASSWORD', 'value' => 'already-set-password-value-32chars'],
                    ['key' => 'MYSQL_ROOT_PASSWORD', 'value' => 'already-set-root-password-32charsxx'],
                    ['key' => 'APP_KEY', 'value' => 'base64:keep'],
                    ['key' => 'CONTROL_PLANE_AGENT_SECRET', 'value' => $agentSecret],
                ], 200);
            }
            if (in_array($request->method(), ['PATCH', 'POST'], true) && str_contains($request->url(), '/envs')) {
                return Http::response(['uuid' => 'env-1'], 201);
            }
            if ($request->method() === 'PATCH' && str_ends_with($request->url(), '/applications/'.self::APP)) {
                return Http::response(['uuid' => self::APP], 200);
            }
            if (str_contains($request->url(), '/deploy')) {
                return Http::response(['deployments' => [['deployment_uuid' => 'dep-preflight-1']]], 200);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/deployments')) {
                return Http::response([], 200);
            }

            return Http::response(['error' => 'unexpected '.$request->method().' '.$request->url()], 404);
        });

        return $calls;
    }

    /**
     * @param  \ArrayObject<int, Request>  $calls
     */
    private function firstIndex(\ArrayObject $calls, callable $match): ?int
    {
        foreach ($calls as $index => $request) {
            if ($match($request)) {
                return $index;
            }
        }

        return null;
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(OpsRole::Operator->value);

        return $user;
    }
}
