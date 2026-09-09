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

        $this->actingAs($operator)
            ->get(route('ops.fleet'))
            ->assertOk()
            ->assertSee('Fleet snapshot', false)
            ->assertSee('Unhealthy', false)
            ->assertSee('Failed deploys', false)
            ->assertSee('Status error or agent health fail', false)
            ->assertSee('main 1', false)
            ->assertSee('beta 1', false)
            ->assertSee('alpha 1', false);
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
        $this->assertMatchesRegularExpression('/Unhealthy<\/p>\s*<p class="kpi-value">2<\/p>/', $html);
        $this->assertSame(5, substr_count($html, 'class="kpi-card"'));
        $this->assertStringNotContainsString('Dockerfile (eski pack)', $html);
        $this->assertStringNotContainsString('Compose\'a geçirilmedi', $html);
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
            ->assertSee('Fleet snapshot', false)
            ->assertSee('Dockerfile (eski pack)', false)
            ->assertSee('Compose\'a geçirilmedi', false)
            ->assertSee('Legacy Dockerfile Site', false)
            ->assertSee('legacy.example.test', false)
            ->assertSee(route('ops.sites.edit', $legacy), false)
            ->assertDontSee('Compose Site', false)
            ->getContent();

        $this->assertSame(5, substr_count($html, 'class="kpi-card"'));
        $this->assertSame(1, substr_count($html, 'id="fleet-attention-heading"'));
        $this->assertStringNotContainsString('eski sürüm', $html);
    }
}
