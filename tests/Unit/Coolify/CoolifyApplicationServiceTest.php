<?php

namespace Tests\Unit\Coolify;

use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Coolify\CoolifyClient;
use App\Services\Coolify\CoolifyCredentials;
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
                && $request->data()['docker_compose_location'] === 'docker-compose.coolify.yml';
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

    private function service(): CoolifyApplicationService
    {
        return new CoolifyApplicationService(
            new CoolifyClient(new CoolifyCredentials('https://coolify.test', 'test-coolify-token')),
        );
    }
}
