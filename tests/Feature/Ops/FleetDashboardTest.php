<?php

namespace Tests\Feature\Ops;

use App\Enums\Channel;
use App\Enums\DeploymentStatus;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_fleet_dashboard_renders_kpi_counts(): void
    {
        Site::factory()->create(['channel' => Channel::Main, 'status' => SiteStatus::Active]);
        $error = Site::factory()->create(['channel' => Channel::Beta, 'status' => SiteStatus::Error]);
        Site::factory()->create(['channel' => Channel::Alpha, 'status' => SiteStatus::Provisioning]);
        Deployment::factory()->create([
            'site_id' => $error->id,
            'status' => DeploymentStatus::Failed,
        ]);

        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        $html = $this->actingAs($operator)
            ->get(route('ops.fleet'))
            ->assertOk()
            ->assertSee('ops-content-wide', false)
            ->assertSee('Fleet snapshot', false)
            ->assertSee('Unhealthy', false)
            ->assertSee('Failed deploys', false)
            ->assertSee('Status error or agent health fail', false)
            ->assertSee('main 1', false)
            ->assertSee('beta 1', false)
            ->assertSee('alpha 1', false)
            ->assertSee(route('ops.sites.show', $error), false)
            ->assertDontSee(route('ops.sites.edit', $error), false)
            ->getContent();

        $this->assertLessThan(
            strpos($html, 'id="fleet-failed-heading"'),
            strpos($html, 'id="fleet-unhealthy-heading"')
        );
    }

    public function test_unhealthy_kpi_includes_agent_failures_without_double_counting_error(): void
    {
        Site::factory()->withSecrets()->create([
            'channel' => Channel::Main,
            'status' => SiteStatus::Error,
            'last_health_at' => now(),
            'last_health_payload' => [
                'ok' => false,
                'status' => 'unhealthy',
                'reason' => 'timeout',
            ],
        ]);
        Site::factory()->withSecrets()->create([
            'channel' => Channel::Beta,
            'status' => SiteStatus::Active,
            'last_health_at' => now(),
            'last_health_payload' => [
                'ok' => false,
                'status' => 'unhealthy',
                'reason' => 'timeout',
            ],
        ]);
        Site::factory()->create([
            'channel' => Channel::Alpha,
            'status' => SiteStatus::Active,
            'last_health_payload' => [
                'ok' => false,
                'status' => 'needs_secret',
                'reason' => 'needs_secret',
            ],
        ]);

        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        $html = $this->actingAs($operator)
            ->get(route('ops.fleet'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'kpi-label">Unhealthy'));
        $this->assertMatchesRegularExpression('/kpi-label">Unhealthy[\s\S]{0,400}?kpi-value[^>]*>2<\/p>/', $html);
        $this->assertSame(5, preg_match_all('/class="kpi-card(?:\s|")/', $html));
        $this->assertStringNotContainsString(__('fleet.attention.dockerfile_title'), $html);
        $this->assertStringNotContainsString(__('fleet.attention.dockerfile_lede'), $html);
    }

    public function test_fleet_dashboard_lists_dockerfile_pack_sites_in_attention_row(): void
    {
        $legacy = Site::factory()->dockerfilePack()->create([
            'name' => 'Legacy Dockerfile Site',
            'primary_domain' => 'legacy.example.test',
        ]);
        Site::factory()->create([
            'name' => 'Compose Site',
            'primary_domain' => 'compose.example.test',
            'notes' => null,
        ]);

        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        $html = $this->actingAs($operator)
            ->get(route('ops.fleet'))
            ->assertOk()
            ->assertSee(__('fleet.attention.dockerfile_title'), false)
            ->assertSee(__('fleet.attention.dockerfile_lede'), false)
            ->assertSee('Legacy Dockerfile Site', false)
            ->assertSee('legacy.example.test', false)
            ->assertSee(route('ops.sites.show', $legacy), false)
            ->assertDontSee(route('ops.sites.edit', $legacy), false)
            ->assertDontSee('Compose Site', false)
            ->getContent();

        $this->assertSame(5, preg_match_all('/class="kpi-card(?:\s|")/', $html));
        $this->assertSame(1, substr_count($html, 'id="fleet-attention-heading"'));
        $this->assertStringNotContainsString('eski sürüm', $html);
        $this->assertStringContainsString('class="site-hint"', $html);
    }

    public function test_fleet_attention_lists_unhealthy_and_failed_before_dockerfile(): void
    {
        $unhealthy = Site::factory()->create([
            'name' => 'Unhealthy Attention Site',
            'primary_domain' => 'unhealthy.example.test',
            'status' => SiteStatus::Error,
        ]);
        $failed = Deployment::factory()->create([
            'status' => DeploymentStatus::Failed,
            'error_message' => 'Compose pull failed.',
        ]);
        $legacy = Site::factory()->dockerfilePack()->create([
            'name' => 'Legacy Pack Site',
            'primary_domain' => 'legacy-pack.example.test',
        ]);

        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        $html = $this->actingAs($operator)
            ->get(route('ops.fleet'))
            ->assertOk()
            ->assertSee('Unhealthy Attention Site', false)
            ->assertSee('Compose pull failed.', false)
            ->assertSee('Legacy Pack Site', false)
            ->assertSee(route('ops.sites.show', $unhealthy), false)
            ->assertSee(route('ops.sites.show', $failed->site), false)
            ->assertSee(route('ops.sites.show', $legacy), false)
            ->assertDontSee(route('ops.sites.edit', $unhealthy), false)
            ->assertDontSee(route('ops.sites.edit', $failed->site), false)
            ->getContent();

        $unhealthyPos = strpos($html, 'id="fleet-unhealthy-heading"');
        $failedPos = strpos($html, 'id="fleet-failed-heading"');
        $dockerfilePos = strpos($html, 'id="fleet-attention-heading"');

        $this->assertNotFalse($unhealthyPos);
        $this->assertNotFalse($failedPos);
        $this->assertNotFalse($dockerfilePos);
        $this->assertLessThan($failedPos, $unhealthyPos);
        $this->assertLessThan($dockerfilePos, $failedPos);
    }
}
