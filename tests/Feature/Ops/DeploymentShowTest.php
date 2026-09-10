<?php

namespace Tests\Feature\Ops;

use App\Enums\Channel;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CoolifyConnection;
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
            ->assertSee('id="deployment-error"', false)
            ->assertSee('id="deployment-log"', false)
            ->assertSee('class="ops-pre"', false)
            ->assertSee('class="site-metric-grid"', false)
            ->assertSee('data-copy-target="#deployment-paste"', false)
            ->assertSee(__('sites.deployments.next_inspect'), false)
            ->assertSee('f2a368dabc', false)
            ->assertSee('create', false)
            ->assertDontSee('data-site-tabs', false)
            ->assertDontSee((string) $site->app_key_encrypted, false);
    }

    public function test_open_in_coolify_uses_environment_uuid_not_name(): void
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://dev.codron.cloud',
            'is_default' => true,
            'default_project_uuid' => 'z8ocg8k04ww8osssccc088c0',
            'default_environment_uuid' => 'sns276euzsz2fprqg3xgfz17',
            'default_environment_name' => 'alpha',
        ]);
        $site = Site::factory()->create([
            'status' => SiteStatus::Active,
            'channel' => Channel::Alpha,
            'coolify_app_uuid' => 'a3p6sgysfwjqhv4yntth1n85',
            'coolify_connection_id' => $connection->id,
            'coolify_project_uuid' => 'z8ocg8k04ww8osssccc088c0',
            'coolify_environment_uuid' => 'i0sw4kk0cogg4o08oscwcssk',
        ]);
        $deployment = Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::Finished,
        ]);
        $href = 'https://dev.codron.cloud/project/z8ocg8k04ww8osssccc088c0/environment/i0sw4kk0cogg4o08oscwcssk/application/a3p6sgysfwjqhv4yntth1n85';

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee($href, false)
            ->assertDontSee('/environment/alpha/', false);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.deployments.show', [$site, $deployment]))
            ->assertOk()
            ->assertSee($href, false)
            ->assertDontSee('/environment/alpha/', false);
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
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee($href, false)
            ->assertSee(route('ops.sites.deployments.show', [$site, $deployment]), false)
            ->assertSee('Coolify deployment failed.', false)
            ->assertSee('Coolify deploy status. Failures show the stored error in the row.', false);

        $this->actingAs($operator)
            ->get(route('ops.sites.edit', $site))
            ->assertOk()
            ->assertDontSee($href, false);
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

    public function test_site_deployments_table_keeps_headers_on_one_line(): void
    {
        $site = Site::factory()->create([
            'name' => 'Header Site',
            'status' => SiteStatus::Active,
        ]);
        Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::Finished,
        ]);

        $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.sites.show', $site))
            ->assertOk()
            ->assertSee('class="ops-th-label"', false)
            ->assertSee(__('sites.deployments.columns.branch'), false)
            ->assertSee(__('sites.deployments.columns.trigger'), false)
            ->assertSee(__('sites.deployments.columns.commit'), false)
            ->assertSee(__('sites.deployments.columns.duration'), false)
            ->assertSee(__('sites.deployments.columns.started'), false);
    }

    private function user(OpsRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
