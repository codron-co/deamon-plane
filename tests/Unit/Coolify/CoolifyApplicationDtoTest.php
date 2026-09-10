<?php

namespace Tests\Unit\Coolify;

use App\Enums\CoolifyGitSourceKind;
use App\Services\Coolify\Dto\CoolifyApplication;
use Tests\TestCase;

class CoolifyApplicationDtoTest extends TestCase
{
    public function test_nested_payload_exposes_project_env_server_and_github_app(): void
    {
        $app = CoolifyApplication::fromArray([
            'uuid' => 'app-1',
            'name' => 'Susa',
            'git_branch' => 'beta',
            'git_repository' => 'https://github.com/codron-co/deamon.git',
            'github_app_id' => 12,
            'github_app_uuid' => 'r08cws800oow8w008880c8og',
            'destination' => [
                'server' => ['uuid' => 'no48ksggg0k8sk4o4w08gks8', 'id' => 1],
            ],
            'environment' => [
                'uuid' => 'sns276euzsz2fprqg3xgfz17',
                'project' => ['uuid' => 'z8ocg8k04ww8osssccc088c0', 'id' => 3],
            ],
        ]);

        $this->assertSame('z8ocg8k04ww8osssccc088c0', $app->projectUuid());
        $this->assertSame('sns276euzsz2fprqg3xgfz17', $app->environmentUuid());
        $this->assertSame('no48ksggg0k8sk4o4w08gks8', $app->serverUuid());
        $this->assertSame('r08cws800oow8w008880c8og', $app->gitSourceUuid());
        $this->assertSame(CoolifyGitSourceKind::GithubApp, $app->gitSourceKind());
    }

    public function test_integer_and_numeric_fks_are_not_treated_as_uuids(): void
    {
        $app = CoolifyApplication::fromArray([
            'uuid' => 'app-2',
            'name' => 'Key',
            'github_app_uuid' => '12',
            'private_key_uuid' => 'pk-deploy-1',
            'server_uuid' => 4,
            'project_uuid' => '9',
        ]);

        $this->assertNull($app->projectUuid());
        $this->assertNull($app->serverUuid());
        $this->assertSame('pk-deploy-1', $app->gitSourceUuid());
        $this->assertSame(CoolifyGitSourceKind::DeployKey, $app->gitSourceKind());
    }

    public function test_numeric_github_app_id_is_used_when_uuid_is_absent(): void
    {
        $app = CoolifyApplication::fromArray([
            'uuid' => 'app-4',
            'name' => 'Numeric',
            'github_app_id' => 3,
        ]);

        $this->assertSame('3', $app->gitSourceUuid());
        $this->assertSame(CoolifyGitSourceKind::GithubApp, $app->gitSourceKind());
    }

    public function test_git_helpers_are_null_when_no_source(): void
    {
        $app = CoolifyApplication::fromArray([
            'uuid' => 'app-3',
            'name' => 'Public',
        ]);

        $this->assertNull($app->gitSource());
        $this->assertNull($app->gitSourceUuid());
        $this->assertNull($app->gitSourceKind());
    }

    public function test_auto_deploy_reads_nested_settings_field(): void
    {
        $nested = CoolifyApplication::fromArray([
            'uuid' => 'app-5',
            'settings' => ['is_auto_deploy_enabled' => true],
        ]);
        $legacyNested = CoolifyApplication::fromArray([
            'uuid' => 'app-5b',
            'settings' => ['is_auto_deploy' => 1],
        ]);
        $legacy = CoolifyApplication::fromArray([
            'uuid' => 'app-6',
            'is_auto_deploy' => true,
        ]);
        $off = CoolifyApplication::fromArray([
            'uuid' => 'app-6b',
            'settings' => ['is_auto_deploy' => false],
        ]);
        $unknown = CoolifyApplication::fromArray(['uuid' => 'app-7']);

        $this->assertTrue($nested->isAutoDeploy());
        $this->assertTrue($nested->autoDeployState());
        $this->assertTrue($legacyNested->isAutoDeploy());
        $this->assertTrue($legacy->isAutoDeploy());
        $this->assertFalse($off->isAutoDeploy());
        $this->assertFalse($off->autoDeployState());
        $this->assertFalse($unknown->isAutoDeploy());
        $this->assertNull($unknown->autoDeployState());
    }
}
