<?php

namespace Tests\Unit\Import;

use App\Console\Commands\ImportCoolifyApps\CoolifyFleetClassifier;
use App\Enums\Channel;
use App\Enums\SiteStatus;
use App\Services\Coolify\Dto\CoolifyApplication;
use Tests\TestCase;

class CoolifyFleetClassifierTest extends TestCase
{
    private CoolifyFleetClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = new CoolifyFleetClassifier;
    }

    public function test_configured_deamon_repo_is_a_customer_site(): void
    {
        $this->assertTrue($this->classifier->isDeamonCustomerRepository(
            'https://github.com/codron-co/deamon.git'
        ));
        $this->assertTrue($this->classifier->isDeamonCustomerRepository(
            'git@github.com:codron-co/deamon.git'
        ));
        $this->assertTrue($this->classifier->isDeamonCustomerRepository(
            'https://github.com/codron-co/deamon'
        ));
    }

    public function test_excludes_plane_entron_transfer_and_theme_repos(): void
    {
        $this->assertFalse($this->classifier->isDeamonCustomerRepository(
            'https://github.com/codron-co/deamon-plane.git'
        ));
        $this->assertFalse($this->classifier->isDeamonCustomerRepository(
            'https://github.com/codron-co/entron.git'
        ));
        $this->assertFalse($this->classifier->isDeamonCustomerRepository(
            'https://github.com/codron-co/webapp-transfer.git'
        ));
        $this->assertFalse($this->classifier->isDeamonCustomerRepository(
            'https://github.com/deamon-themes/deamon-theme-izyem.git'
        ));
    }

    public function test_unknown_repo_is_not_a_customer_site(): void
    {
        $this->assertFalse($this->classifier->isDeamonCustomerRepository(
            'https://github.com/acme/other.git'
        ));
        $this->assertFalse($this->classifier->isDeamonCustomerRepository(null));
        $this->assertFalse($this->classifier->isDeamonCustomerRepository(''));
    }

    public function test_channel_allowlist_else_needs_review_with_main_placeholder(): void
    {
        $this->assertSame(Channel::Beta, $this->classifier->resolveChannel('beta'));
        $this->assertFalse($this->classifier->needsReview('main'));

        $this->assertSame(Channel::Main, $this->classifier->resolveChannel('develop'));
        $this->assertTrue($this->classifier->needsReview('develop'));
        $this->assertTrue($this->classifier->needsReview(null));
    }

    public function test_status_is_active_only_when_coolify_looks_running(): void
    {
        $this->assertSame(SiteStatus::Active, $this->classifier->resolveStatus('running:healthy'));
        $this->assertSame(SiteStatus::Active, $this->classifier->resolveStatus('running'));
        $this->assertSame(SiteStatus::Error, $this->classifier->resolveStatus('running:unhealthy'));
        $this->assertSame(SiteStatus::Error, $this->classifier->resolveStatus('exited:unhealthy'));
        $this->assertSame(SiteStatus::Draft, $this->classifier->resolveStatus('starting'));
        $this->assertSame(SiteStatus::Draft, $this->classifier->resolveStatus(null));
    }

    public function test_domain_prefers_app_service_from_live_json_string(): void
    {
        $app = CoolifyApplication::fromArray([
            'uuid' => 'app-1',
            'name' => 'Susa',
            'fqdn' => null,
            'git_repository' => 'https://github.com/codron-co/deamon.git',
            'docker_compose_domains' => '{"mysql":{"domain":"https://db.example.test"},"app":{"domain":"https://susa.demo.codron.co,https://www.susa.demo.codron.co/"}}',
        ]);

        $this->assertSame('susa.demo.codron.co', $this->classifier->primaryHost($app));
    }

    public function test_domain_falls_back_to_fqdn(): void
    {
        $app = CoolifyApplication::fromArray([
            'uuid' => 'app-2',
            'name' => 'Old',
            'fqdn' => 'https://old.example.test/',
            'git_repository' => 'https://github.com/codron-co/deamon.git',
        ]);

        $this->assertSame('old.example.test', $this->classifier->primaryHost($app));
    }

    public function test_slug_uses_domain_host_then_app_name(): void
    {
        $this->assertSame('susa', $this->classifier->slugFrom('susa.demo.codron.co', 'Susa DEMO'));
        $this->assertSame('izyem', $this->classifier->slugFrom('', 'deamon-izyem'));
        $this->assertSame('example', $this->classifier->slugFromHost('www.example.com'));
    }

    public function test_dockerfile_is_importable_with_warning_nixpacks_is_not(): void
    {
        $dockerfile = CoolifyApplication::fromArray([
            'uuid' => 'df',
            'name' => 'Legacy',
            'build_pack' => 'dockerfile',
            'git_repository' => 'https://github.com/codron-co/deamon.git',
            'fqdn' => 'legacy.example.test',
        ]);
        $nixpacks = CoolifyApplication::fromArray([
            'uuid' => 'nx',
            'name' => 'Nix',
            'build_pack' => 'nixpacks',
            'git_repository' => 'https://github.com/codron-co/deamon.git',
            'fqdn' => 'nix.example.test',
        ]);

        $this->assertTrue($this->classifier->isDockerfile($dockerfile));
        $this->assertSame([], $this->classifier->skipReasons($dockerfile));
        $this->assertNotSame([], $this->classifier->skipReasons($nixpacks));
    }
}
