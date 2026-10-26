<?php

namespace Tests\Unit\Coolify;

use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Coolify\CoolifyClient;
use App\Services\Coolify\CoolifyCredentials;
use App\Services\Coolify\DockerImageLabels;
use App\Services\Coolify\Dto\CreateComposeAppRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class CoolifyApplicationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_create_compose_app_rejects_branch_outside_allowlist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Channel [develop] is not in the allowlist.');

        $this->service()->createComposeApp(new CreateComposeAppRequest(
            projectUuid: 'proj-1',
            serverUuid: 'srv-1',
            gitRepository: 'https://github.com/codron-co/deamon.git',
            gitBranch: 'develop',
            githubAppUuid: 'gh-1',
        ));
    }

    public function test_update_branch_rejects_channel_outside_ops_config(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->updateBranch('app-1', 'nightly');
    }

    public function test_create_compose_app_keeps_dockercompose_build_pack(): void
    {
        Http::fake([
            'https://coolify.test/api/v1/applications/private-github-app' => Http::response([
                'uuid' => 'created',
                'name' => 'deamon-izyem',
                'git_branch' => 'main',
                'build_pack' => 'dockercompose',
            ], 201),
        ]);

        $app = $this->service()->createComposeApp(new CreateComposeAppRequest(
            projectUuid: 'proj-1',
            serverUuid: 'srv-1',
            gitRepository: 'https://github.com/codron-co/deamon.git',
            gitBranch: 'main',
            githubAppUuid: 'gh-1',
            name: 'deamon-izyem',
        ));

        $this->assertSame('created', $app->uuid);

        Http::assertSent(function (Request $request): bool {
            return $request->data()['build_pack'] === 'dockercompose'
                && $request->data()['docker_compose_location'] === '/docker-compose.coolify.yml'
                && ($request->data()['environment_name'] ?? null) === 'main';
        });
    }

    public function test_update_branch_allowlist_passes_for_beta(): void
    {
        Http::fake([
            'https://coolify.test/api/v1/applications/app-1' => Http::response([
                'uuid' => 'app-1',
                'name' => 'Susa',
                'git_branch' => 'beta',
            ], 200),
        ]);

        $app = $this->service()->updateBranch('app-1', 'beta');

        $this->assertSame('beta', $app->gitBranch);
    }

    public function test_set_domains_on_a_docker_image_app_writes_domains_labels_and_url_env(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response(['uuid' => 'img-app-1', 'build_pack' => 'dockerimage', 'fqdn' => 'https://old.example.test'], 200);
            }

            if (str_ends_with($request->url(), '/envs/bulk')) {
                return Http::response([], 200);
            }

            return Http::response(['uuid' => 'img-app-1', 'build_pack' => 'dockerimage'], 200);
        });

        $this->service()->setDomains('img-app-1', 'izyem.test,www.izyem.test');

        $patches = collect(Http::recorded())
            ->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $r): bool => $r->method() === 'PATCH' && str_ends_with($r->url(), '/applications/img-app-1'))
            ->values();
        $this->assertCount(1, $patches);

        $body = $patches[0]->data();
        $this->assertSame('https://izyem.test,https://www.izyem.test', $body['domains']);
        $this->assertTrue($body['force_domain_override']);
        $this->assertArrayNotHasKey('docker_compose_domains', $body);
        $this->assertSame(
            DockerImageLabels::for('img-app-1', ['izyem.test', 'www.izyem.test']),
            base64_decode((string) $body['custom_labels'], true),
        );

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PATCH' || ! str_ends_with($request->url(), '/applications/img-app-1/envs/bulk')) {
                return false;
            }
            $env = collect($request->data()['data'] ?? [])->pluck('value', 'key')->all();

            return $env === ['APP_URL' => 'https://izyem.test', 'DEAMON_SITE_HOST' => 'izyem.test'];
        });
    }

    public function test_set_domains_on_a_compose_app_keeps_the_compose_patch(): void
    {
        Http::fake(function (Request $request) {
            return Http::response([
                'uuid' => 'app-1',
                'build_pack' => 'dockercompose',
                'docker_compose_domains' => [['name' => 'app', 'domain' => 'https://izyem.test']],
            ], 200);
        });

        $this->service()->setDomains('app-1', 'izyem.test,www.izyem.test');

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->method() === 'PATCH'
                && ($body['docker_compose_domains'] ?? null) === [['name' => 'app', 'domain' => 'https://izyem.test,https://www.izyem.test']]
                && ($body['force_domain_override'] ?? null) === false
                && ! array_key_exists('custom_labels', $body)
                && ! array_key_exists('domains', $body);
        });
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/envs'));
    }

    public function test_set_domains_falls_back_to_the_compose_patch_when_the_app_cannot_be_read(): void
    {
        Http::fake(function (Request $request) {
            return $request->method() === 'GET'
                ? Http::response(['message' => 'Not found.'], 404)
                : Http::response(['uuid' => 'app-1'], 200);
        });

        $this->service()->setDomains('app-1', 'izyem.test');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && array_key_exists('docker_compose_domains', $request->data()));
    }

    private function service(): CoolifyApplicationService
    {
        return new CoolifyApplicationService(
            new CoolifyClient(new CoolifyCredentials('https://coolify.test', 'test-coolify-token')),
        );
    }
}
