<?php

namespace Tests\Feature\Coolify;

use App\Enums\Channel;
use App\Enums\SiteStatus;
use App\Models\CoolifySetting;
use App\Models\Site;
use App\Services\Coolify\CoolifyApplicationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ComposeDomainBindOnDeployTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        CoolifySetting::factory()->create(['base_url' => 'https://coolify.test', 'api_token' => 't']);
    }

    public function test_compose_app_with_hosts_only_on_fqdn_is_bound_before_deploy(): void
    {
        $site = $this->site();
        $this->fakeCoolify(['build_pack' => 'dockercompose', 'fqdn' => 'https://shop.example.test,https://www.shop.example.test', 'docker_compose_domains' => null]);

        CoolifyApplicationService::forSite($site)->deploy('bindapp1');

        $patch = $this->recorded('PATCH', '/applications/bindapp1');
        $this->assertCount(1, $patch);
        $domains = $patch[0]->data()['docker_compose_domains'];
        $this->assertSame('app', $domains[0]['name']);
        $this->assertStringContainsString('https://shop.example.test', $domains[0]['domain']);
        $this->assertStringContainsString('https://www.shop.example.test', $domains[0]['domain']);
        $this->assertCount(1, $this->recorded('POST', '/deploy'));
    }

    public function test_bound_compose_app_is_left_alone(): void
    {
        $site = $this->site();
        $this->fakeCoolify(['build_pack' => 'dockercompose', 'fqdn' => null, 'docker_compose_domains' => '{"app":{"domain":"https://shop.example.test"}}']);

        CoolifyApplicationService::forSite($site)->deploy('bindapp1');

        $this->assertCount(0, $this->recorded('PATCH', '/applications/bindapp1'));
        $this->assertCount(1, $this->recorded('POST', '/deploy'));
    }

    public function test_a_failed_bind_does_not_block_the_deploy(): void
    {
        $site = $this->site();
        $this->fakeCoolify(['build_pack' => 'dockercompose', 'fqdn' => 'https://shop.example.test', 'docker_compose_domains' => null], patchStatus: 409);

        CoolifyApplicationService::forSite($site)->deploy('bindapp1');

        $this->assertCount(1, $this->recorded('POST', '/deploy'));
    }

    private function site(): Site
    {
        return Site::factory()->withSecrets()->create([
            'status' => SiteStatus::Active,
            'channel' => Channel::Main,
            'coolify_app_uuid' => 'bindapp1',
            'primary_domain' => 'shop.example.test',
        ]);
    }

    /**
     * @param  array<string, mixed>  $app
     */
    private function fakeCoolify(array $app, int $patchStatus = 200): void
    {
        Http::fake(function (Request $request) use ($app, $patchStatus) {
            $url = $request->url();
            if ($request->method() === 'GET' && str_contains($url, '/envs')) {
                return Http::response([], 200);
            }
            if ($request->method() === 'GET' && str_ends_with(parse_url($url, PHP_URL_PATH), '/applications/bindapp1')) {
                return Http::response(array_merge(['uuid' => 'bindapp1', 'name' => 'shop'], $app), 200);
            }
            if ($request->method() === 'PATCH' && str_contains($url, '/envs')) {
                return Http::response([], 200);
            }
            if ($request->method() === 'PATCH' && str_ends_with(parse_url($url, PHP_URL_PATH), '/applications/bindapp1')) {
                return Http::response($patchStatus === 200 ? ['uuid' => 'bindapp1'] : ['message' => 'conflict'], $patchStatus);
            }
            if ($request->method() === 'POST' && str_contains($url, '/deploy')) {
                return Http::response(['deployments' => [['deployment_uuid' => 'dep1', 'resource_uuid' => 'bindapp1']]], 200);
            }

            return Http::response(['error' => 'unexpected '.$request->method().' '.$url], 404);
        });
    }

    /**
     * @return list<Request>
     */
    private function recorded(string $method, string $path): array
    {
        return Http::recorded(fn (Request $request): bool => $request->method() === $method
            && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), $path))
            ->map(fn (array $pair): Request => $pair[0])
            ->values()
            ->all();
    }
}
