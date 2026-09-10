<?php

namespace Tests\Feature\Ops;

use App\Enums\Channel;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeploymentShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_operator_opens_deployment_show_with_copyable_coolify_error(): void
    {
        $site = Site::factory()->withSecrets()->create([
            'name' => 'Deamon Test',
            'slug' => 'deamon-test',
            'primary_domain' => 'test.deamon.codron.co',
            'status' => SiteStatus::Error,
            'coolify_app_uuid' => '6cmmgh5ty9lzv6uiz5kfavue',
        ]);

        $deployment = Deployment::factory()->create([
            'site_id' => $site->id,
            'channel' => Channel::Alpha,
            'trigger' => DeploymentTrigger::Create,
            'coolify_deployment_uuid' => 'dep-fail-1',
            'status' => DeploymentStatus::Failed,
            'commit_sha' => 'f2a368dabc',
            'started_at' => now()->subMinutes(32),
            'finished_at' => now(),
            'error_message' => "Validation failed. fqdn: This field is not allowed.\n\nCoolify errors:\n{\n    \"fqdn\": [\n        \"This field is not allowed.\"\n    ]\n}",
            'log_excerpt' => "APP_KEY=[redacted]\nBuild failed on service app",
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.deployments.show', [$site, $deployment]))
            ->assertOk()
            ->assertSee('Validation failed.', false)
            ->assertSee('This field is not allowed.', false)
            ->assertSee('fqdn', false)
            ->assertSee('Build failed on service app', false)
            ->assertSee('data-copy-target="#deployment-paste"', false)
            ->assertSee('f2a368dabc', false)
            ->assertSee('create', false)
            ->assertDontSee((string) $site->app_key_encrypted, false);
    }

    public function test_site_edit_and_show_rows_target_deployment_detail(): void
    {
        $deployment = Deployment::factory()->create([
            'channel' => Channel::Beta,
            'status' => DeploymentStatus::Failed,
            'error_message' => 'Coolify deployment failed.',
        ]);
        $site = $deployment->site;
        $href = 'data-href="'.route('ops.sites.deployments.show', [$site, $deployment]).'"';

        $operator = $this->user(OpsRole::Operator);

        $this->actingAs($operator)
            ->get(route('ops.sites.edit', $site))
            ->assertOk()
            ->assertSee($href, false)
            ->assertSee(route('ops.sites.deployments.show', [$site, $deployment]), false);

        $this->actingAs($operator)
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee($href, false);
    }

    public function test_deployment_from_another_site_is_not_found(): void
    {
        $site = Site::factory()->create();
        $other = Deployment::factory()->create();

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.deployments.show', [$site, $other]))
            ->assertNotFound();
    }

    public function test_viewer_can_open_deployment_show(): void
    {
        $deployment = Deployment::factory()->create([
            'status' => DeploymentStatus::Failed,
            'error_message' => 'Coolify deployment failed.',
        ]);

        $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.sites.deployments.show', [$deployment->site, $deployment]))
            ->assertOk()
            ->assertSee('Coolify deployment failed.', false);
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
