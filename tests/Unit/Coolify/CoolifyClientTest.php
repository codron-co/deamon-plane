<?php

namespace Tests\Unit\Coolify;

use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyClient;
use App\Services\Coolify\CoolifyCredentials;
use App\Services\Coolify\Dto\CreateComposeAppRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class CoolifyClientTest extends TestCase
{
    private const TOKEN = 'test-coolify-token';

    private const BASE = 'https://coolify.test';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_list_apps_sends_bearer_and_optional_tag(): void
    {
        Http::fake([
            'https://coolify.test/api/v1/applications*' => Http::response([
                [
                    'uuid' => 'app-1',
                    'name' => 'Susa',
                    'git_branch' => 'beta',
                    'build_pack' => 'dockercompose',
                    'fqdn' => null,
                    'git_repository' => 'https://github.com/codron-co/deamon.git',
                    'docker_compose_domains' => '{"app":{"domain":"https://susa.demo.codron.co,https://www.susa.demo.codron.co/"}}',
                ],
            ], 200),
        ]);

        $apps = $this->client()->listApps('deamon');

        $this->assertCount(1, $apps);
        $this->assertSame('app-1', $apps[0]->uuid);
        $this->assertArrayHasKey('fqdn', $apps[0]->raw);
        $this->assertNull($apps[0]->raw['fqdn']);
        $this->assertSame('https://susa.demo.codron.co', $apps[0]->fqdn);
        $this->assertSame('app', $apps[0]->composeDomains[0]['name']);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://coolify.test/api/v1/applications?tag=deamon'
                && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN);
        });
    }

    public function test_get_app_parses_live_compose_domains_string(): void
    {
        Http::fake([
            'https://coolify.test/api/v1/applications/crxguq6nodorlzy88wf9x305' => Http::response([
                'uuid' => 'crxguq6nodorlzy88wf9x305',
                'name' => 'Susa',
                'git_branch' => 'beta',
                'build_pack' => 'dockercompose',
                'docker_compose_location' => '/docker-compose.coolify.yml',
                'fqdn' => null,
                'docker_compose_domains' => '{"app":{"domain":"https://susa.demo.codron.co"}}',
            ], 200),
        ]);

        $app = $this->client()->getApp('crxguq6nodorlzy88wf9x305');

        $this->assertSame('crxguq6nodorlzy88wf9x305', $app->uuid);
        $this->assertSame('https://susa.demo.codron.co', $app->primaryDomain());
        $this->assertSame('dockercompose', $app->buildPack);
    }

    public function test_list_servers_and_projects(): void
    {
        Http::fake([
            'https://coolify.test/api/v1/servers' => Http::response([
                ['uuid' => 'srv-1', 'name' => 'localhost'],
            ], 200),
            'https://coolify.test/api/v1/projects' => Http::response([
                ['uuid' => 'proj-1', 'name' => 'Deamon'],
            ], 200),
        ]);

        $servers = $this->client()->listServers();
        $projects = $this->client()->listProjects();

        $this->assertSame('srv-1', $servers[0]->uuid);
        $this->assertSame('Deamon', $projects[0]->name);
    }

    public function test_list_environments_github_apps_and_private_keys(): void
    {
        Http::fake([
            'https://coolify.test/api/v1/projects/proj-1/environments' => Http::response([
                ['uuid' => 'env-1', 'name' => 'production'],
            ], 200),
            'https://coolify.test/api/v1/github-apps' => Http::response([
                ['uuid' => 'gh-1', 'name' => 'coolify-github-codronco', 'organization' => 'codron-co'],
                ['uuid' => 'gh-2', 'name' => 'coolify-github-eminwhocodes'],
            ], 200),
            'https://coolify.test/api/v1/security/keys' => Http::response([
                ['uuid' => 'pk-1', 'name' => 'deploy'],
            ], 200),
        ]);

        $envs = $this->client()->listEnvironments('proj-1');
        $apps = $this->client()->listGithubApps();
        $keys = $this->client()->listPrivateKeys();

        $this->assertSame('env-1', $envs[0]->uuid);
        $this->assertNotNull($apps);
        $this->assertCount(2, $apps);
        $this->assertSame('gh-1', $apps[0]->uuid);
        $this->assertSame('coolify-github-codronco / codron-co', $apps[0]->name);
        $this->assertSame('coolify-github-eminwhocodes', $apps[1]->name);
        $this->assertSame('pk-1', $keys[0]->uuid);
    }

    public function test_list_github_apps_404_returns_null(): void
    {
        Http::fake([
            'https://coolify.test/api/v1/github-apps' => Http::response(['message' => 'Not found'], 404),
        ]);

        $this->assertNull($this->client()->listGithubApps());
    }

    public function test_create_compose_app_uses_git_github_app_path_not_deprecated_raw_compose(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/applications/dockercompose')) {
                return Http::response(['error' => 'deprecated path must not be used'], 500);
            }

            if ($request->method() === 'POST' && str_ends_with($request->url(), '/applications/private-github-app')) {
                return Http::response([
                    'uuid' => 'new-app',
                    'name' => 'deamon-izyem',
                    'git_branch' => 'main',
                    'build_pack' => 'dockercompose',
                ], 201);
            }

            return Http::response(['error' => 'unexpected '.$request->url()], 404);
        });

        $app = $this->client()->createComposeApp(new CreateComposeAppRequest(
            projectUuid: 'proj-1',
            serverUuid: 'srv-1',
            gitRepository: 'https://github.com/codron-co/deamon.git',
            gitBranch: 'main',
            githubAppUuid: 'gh-app-1',
            name: 'deamon-izyem',
            instantDeploy: true,
            dockerComposeDomains: 'www.example.com',
        ));

        $this->assertSame('new-app', $app->uuid);

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://coolify.test/api/v1/applications/private-github-app'
                && $body['build_pack'] === 'dockercompose'
                && $body['docker_compose_location'] === '/docker-compose.coolify.yml'
                && $body['github_app_uuid'] === 'gh-app-1'
                && $body['instant_deploy'] === true
                && $body['docker_compose_domains'] === [
                    ['name' => 'app', 'domain' => 'https://www.example.com'],
                ]
                && ($body['fqdn'] ?? null) === 'https://www.example.com'
                && ! array_key_exists('docker_compose_raw', $body);
        });

        Http::assertNotSent(function (Request $request): bool {
            return str_contains($request->url(), '/applications/dockercompose');
        });
    }

    public function test_create_compose_app_prefixes_compose_location_without_leading_slash(): void
    {
        Http::fake([
            'https://coolify.test/api/v1/applications/public' => Http::response([
                'uuid' => 'slash-app',
                'name' => 'deamon-izyem',
                'build_pack' => 'dockercompose',
            ], 201),
        ]);

        $this->client()->createComposeApp(new CreateComposeAppRequest(
            projectUuid: 'proj-1',
            serverUuid: 'srv-1',
            gitRepository: 'https://github.com/codron-co/deamon.git',
            gitBranch: 'main',
            dockerComposeLocation: 'docker-compose.coolify.yml',
        ));

        Http::assertSent(function (Request $request): bool {
            return $request->data()['docker_compose_location'] === '/docker-compose.coolify.yml';
        });
    }

    public function test_validation_failed_includes_field_errors_without_token(): void
    {
        Http::fake([
            'https://coolify.test/api/v1/applications/public' => Http::response([
                'message' => 'Validation failed. Authorization: Bearer '.self::TOKEN,
                'errors' => [
                    'docker_compose_location' => ['The docker compose location field format is invalid.'],
                ],
            ], 422),
        ]);

        try {
            $this->client()->createComposeApp(new CreateComposeAppRequest(
                projectUuid: 'proj-1',
                serverUuid: 'srv-1',
                gitRepository: 'https://github.com/codron-co/deamon.git',
                gitBranch: 'main',
            ));
            $this->fail('Expected CoolifyApiException.');
        } catch (CoolifyApiException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertStringContainsString('Validation failed.', $exception->getMessage());
            $this->assertStringContainsString('docker_compose_location', $exception->getMessage());
            $this->assertStringContainsString('format is invalid', $exception->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $exception->getMessage());
            $this->assertStringContainsString('[redacted]', $exception->getMessage());
        }
    }

    public function test_create_compose_app_uses_deploy_key_and_public_endpoints(): void
    {
        Http::fake([
            'https://coolify.test/api/v1/applications/private-deploy-key' => Http::response([
                'uuid' => 'key-app',
                'name' => 'via-key',
            ], 201),
            'https://coolify.test/api/v1/applications/public' => Http::response([
                'uuid' => 'public-app',
                'name' => 'via-public',
            ], 201),
        ]);

        $viaKey = $this->client()->createComposeApp(new CreateComposeAppRequest(
            projectUuid: 'proj-1',
            serverUuid: 'srv-1',
            gitRepository: 'https://github.com/codron-co/deamon.git',
            gitBranch: 'main',
            privateKeyUuid: 'pk-1',
        ));
        $viaPublic = $this->client()->createComposeApp(new CreateComposeAppRequest(
            projectUuid: 'proj-1',
            serverUuid: 'srv-1',
            gitRepository: 'https://github.com/codron-co/deamon.git',
            gitBranch: 'beta',
        ));

        $this->assertSame('key-app', $viaKey->uuid);
        $this->assertSame('public-app', $viaPublic->uuid);
    }

    public function test_update_envs_sends_bulk_payload_and_list_envs_hides_values_in_debug(): void
    {
        Http::fake([
            'https://coolify.test/api/v1/applications/app-1/envs/bulk' => Http::response([
                ['key' => 'APP_KEY', 'value' => 'secret-app-key', 'uuid' => 'env-1'],
            ], 200),
            'https://coolify.test/api/v1/applications/app-1/envs' => Http::response([
                ['key' => 'DEAMON_SITE_NAME', 'value' => 'Susa', 'uuid' => 'env-2'],
            ], 200),
        ]);

        $updated = $this->client()->updateEnvs('app-1', [
            'APP_KEY' => 'secret-app-key',
            'DEAMON_SITE_NAME' => 'Susa',
        ]);
        $listed = $this->client()->listEnvs('app-1');

        $this->assertSame('APP_KEY', $updated[0]->key);
        $this->assertSame('secret-app-key', $updated[0]->value());
        $this->assertSame('[redacted]', $updated[0]->__debugInfo()['value']);
        $this->assertSame('DEAMON_SITE_NAME', $listed[0]->key);
        $this->assertSame('Susa', $listed[0]->value());

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'PATCH'
                && str_ends_with($request->url(), '/applications/app-1/envs/bulk')
                && $request->data() === [
                    'data' => [
                        ['key' => 'APP_KEY', 'value' => 'secret-app-key'],
                        ['key' => 'DEAMON_SITE_NAME', 'value' => 'Susa'],
                    ],
                ];
        });
    }

    public function test_set_domains_patches_array_shape_and_defaults_force_false(): void
    {
        Http::fake([
            'https://coolify.test/api/v1/applications/app-1' => Http::response([
                'uuid' => 'app-1',
                'name' => 'Susa',
                'fqdn' => null,
                'docker_compose_domains' => [
                    ['name' => 'app', 'domain' => 'https://www.example.com'],
                ],
            ], 200),
        ]);

        $app = $this->client()->setDomains('app-1', 'www.example.com');

        $this->assertSame('https://www.example.com', $app->primaryDomain());

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->method() === 'PATCH'
                && $request->url() === 'https://coolify.test/api/v1/applications/app-1'
                && $body['force_domain_override'] === false
                && $body['docker_compose_domains'] === [
                    ['name' => 'app', 'domain' => 'https://www.example.com'],
                ]
                && ! is_string($body['docker_compose_domains']);
        });
    }

    public function test_set_domains_accepts_live_object_array_for_patch(): void
    {
        Http::fake([
            'https://coolify.test/api/v1/applications/app-1' => Http::response([
                'uuid' => 'app-1',
                'name' => 'Susa',
                'docker_compose_domains' => '{"app":{"domain":"https://susa.demo.codron.co"}}',
            ], 200),
        ]);

        $this->client()->setDomains('app-1', [
            'app' => ['domain' => 'https://susa.demo.codron.co'],
        ]);

        Http::assertSent(function (Request $request): bool {
            return $request->data()['docker_compose_domains'] === [
                ['name' => 'app', 'domain' => 'https://susa.demo.codron.co'],
            ];
        });
    }

    public function test_update_branch_and_deploy_and_get_deployment(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if ($request->method() === 'PATCH' && $url === 'https://coolify.test/api/v1/applications/app-1') {
                return Http::response([
                    'uuid' => 'app-1',
                    'name' => 'Susa',
                    'git_branch' => 'beta',
                ], 200);
            }

            if ($request->method() === 'POST' && str_starts_with($url, 'https://coolify.test/api/v1/deploy?')) {
                return Http::response([
                    'deployments' => [
                        [
                            'resource_uuid' => 'app-1',
                            'deployment_uuid' => 'dep-1',
                            'message' => 'queued',
                        ],
                    ],
                ], 200);
            }

            if ($request->method() === 'GET' && $url === 'https://coolify.test/api/v1/deployments/dep-1') {
                return Http::response([
                    'uuid' => 'dep-1',
                    'status' => 'finished',
                    'commit' => '791af01e32708ac6bdd2162a298b20f59476f4da',
                    'logs' => 'THIS_MUST_NOT_BE_PERSISTED',
                ], 200);
            }

            return Http::response(['error' => 'unexpected '.$url], 404);
        });

        $app = $this->client()->updateBranch('app-1', 'beta');
        $deploy = $this->client()->deploy('app-1', true);
        $deployment = $this->client()->getDeployment('dep-1');

        $this->assertSame('beta', $app->gitBranch);
        $this->assertSame('dep-1', $deploy->firstDeploymentUuid());
        $this->assertSame('finished', $deployment->status);
        $this->assertArrayNotHasKey('logs', $deployment->raw);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'PATCH'
                && $request->data() === ['git_branch' => 'beta'];
        });
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && str_starts_with($request->url(), 'https://coolify.test/api/v1/deploy')
                && str_contains($request->url(), 'uuid=app-1')
                && str_contains($request->url(), 'force=true');
        });
    }

    public function test_list_app_deployments_and_storages(): void
    {
        Http::fake([
            'https://coolify.test/api/v1/deployments/applications/app-1*' => Http::response([
                [
                    'uuid' => 'dep-1',
                    'status' => 'finished',
                    'logs' => 'drop-me',
                ],
            ], 200),
            'https://coolify.test/api/v1/applications/app-1/storages' => Http::response([
                'persistent_storages' => [
                    ['name' => 'app-1_deamon-mysql'],
                    ['name' => 'app-1_deamon-redis'],
                ],
                'file_storages' => [],
            ], 200),
        ]);

        $deployments = $this->client()->listAppDeployments('app-1', 0, 5);
        $storages = $this->client()->listStorages('app-1');

        $this->assertSame('dep-1', $deployments[0]->uuid);
        $this->assertArrayNotHasKey('logs', $deployments[0]->raw);
        $this->assertSame(['app-1_deamon-mysql', 'app-1_deamon-redis'], $storages->persistentNames());

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && str_contains($request->url(), '/deployments/applications/app-1')
                && str_contains($request->url(), 'skip=0')
                && str_contains($request->url(), 'take=5');
        });
    }

    public function test_conflict_maps_to_exception_without_leaking_token(): void
    {
        Http::fake([
            'https://coolify.test/api/v1/applications/app-1' => Http::response([
                'message' => 'Domain already used. Authorization: Bearer '.self::TOKEN,
                'conflicts' => [
                    [
                        'domain' => 'https://www.example.com',
                        'resource_name' => 'other',
                        'resource_uuid' => 'other-app',
                    ],
                ],
            ], 409),
        ]);

        try {
            $this->client()->setDomains('app-1', 'www.example.com');
            $this->fail('Expected CoolifyApiException.');
        } catch (CoolifyApiException $exception) {
            $this->assertTrue($exception->isConflict());
            $this->assertSame(409, $exception->status);
            $this->assertSame('other-app', $exception->conflicts[0]['resource_uuid']);
            $this->assertStringNotContainsString(self::TOKEN, $exception->getMessage());
            $this->assertStringContainsString('[redacted]', $exception->getMessage());
        }
    }

    public function test_missing_token_does_not_call_http(): void
    {
        Http::fake();

        $this->expectException(CoolifyApiException::class);
        $this->expectExceptionMessage('Coolify API token is not configured.');

        (new CoolifyClient(new CoolifyCredentials(self::BASE, '')))->listServers();
    }

    public function test_rejects_traversal_uuid(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->client()->getApp('../secret');
    }

    public function test_credentials_debug_info_redacts_token(): void
    {
        $credentials = new CoolifyCredentials(self::BASE.'/api/v1', self::TOKEN);

        $this->assertSame(self::BASE, $credentials->baseUrl);
        $this->assertSame(self::BASE.'/api/v1', $credentials->apiRoot());
        $this->assertSame('[redacted]', $credentials->__debugInfo()['token']);
        $this->assertSame(self::TOKEN, $credentials->token());
    }

    private function client(): CoolifyClient
    {
        return new CoolifyClient(new CoolifyCredentials(self::BASE, self::TOKEN));
    }
}
