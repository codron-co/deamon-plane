<?php

namespace Tests\Feature\Coolify;

use App\Enums\DeploymentStatus;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CoolifyConnection;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use App\Services\Coolify\CoolifyDeployBusyException;
use App\Services\Coolify\CoolifyDeployGate;
use App\Services\Sites\ComposePackException;
use App\Services\Sites\CoolifyDeploySettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CoolifyDeployGateTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'plane-deploy-gate-token';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_gate_blocks_when_peer_site_has_unfinished_deployment(): void
    {
        [$busy, $peer] = $this->peerSites();

        Deployment::factory()->create([
            'site_id' => $busy->id,
            'status' => DeploymentStatus::InProgress,
            'started_at' => now()->subMinute(),
            'finished_at' => null,
        ]);

        $this->expectException(CoolifyDeployBusyException::class);

        app(CoolifyDeployGate::class)->assertCanStartDeploy($peer);
    }

    public function test_gate_allows_when_peer_deployments_are_finished(): void
    {
        [$busy, $peer] = $this->peerSites();

        Deployment::factory()->create([
            'site_id' => $busy->id,
            'status' => DeploymentStatus::Finished,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(5),
        ]);

        app(CoolifyDeployGate::class)->assertCanStartDeploy($peer);

        $this->assertSame(0, app(CoolifyDeployGate::class)->unfinishedCount($peer));
    }

    public function test_gate_allows_sites_on_a_different_connection(): void
    {
        [$busy] = $this->peerSites();
        $other = $this->siteOnConnection(
            CoolifyConnection::factory()->create([
                'base_url' => 'https://other-coolify.example',
                'api_token' => 'other-token',
            ]),
            'app-other',
        );

        Deployment::factory()->create([
            'site_id' => $busy->id,
            'status' => DeploymentStatus::InProgress,
            'started_at' => now()->subMinute(),
            'finished_at' => null,
        ]);

        app(CoolifyDeployGate::class)->assertCanStartDeploy($other);

        $this->assertSame(0, app(CoolifyDeployGate::class)->unfinishedCount($other));
    }

    public function test_redeploy_is_blocked_while_connection_has_open_deploy(): void
    {
        [$busy, $peer] = $this->peerSites();

        Deployment::factory()->create([
            'site_id' => $busy->id,
            'status' => DeploymentStatus::InProgress,
            'started_at' => now()->subMinute(),
            'finished_at' => null,
        ]);

        Http::fake();

        $this->actingAs($this->operator())
            ->post(route('ops.sites.deploy', $peer))
            ->assertRedirect()
            ->assertSessionHas('error', __('coolify.errors.deploy_busy', [
                'max' => 1,
                'count' => 1,
            ]));

        Http::assertNothingSent();
        $this->assertSame(0, $peer->deployments()->count());
    }

    public function test_redeploy_settings_wraps_busy_as_compose_pack_exception(): void
    {
        [$busy, $peer] = $this->peerSites();

        Deployment::factory()->create([
            'site_id' => $busy->id,
            'status' => DeploymentStatus::InProgress,
            'started_at' => now()->subMinute(),
            'finished_at' => null,
        ]);

        Http::fake();

        try {
            app(CoolifyDeploySettings::class)->redeploy($peer, $this->operator());
            $this->fail('Expected ComposePackException');
        } catch (ComposePackException $exception) {
            $this->assertSame(503, $exception->getCode());
            $this->assertInstanceOf(CoolifyDeployBusyException::class, $exception->getPrevious());
            $this->assertStringContainsString('already running', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_redeploy_proceeds_when_no_open_deploys_on_connection(): void
    {
        [, $peer] = $this->peerSites();
        Queue::fake();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/envs')) {
                return Http::response([], 200);
            }

            if ($request->method() === 'POST' && str_contains($request->url(), '/deploy')) {
                return Http::response(['deployments' => [['deployment_uuid' => 'dep-gate-1']]], 200);
            }

            return Http::response(['error' => 'unexpected'], 404);
        });

        $this->actingAs($this->operator())
            ->post(route('ops.sites.deploy', $peer))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('deployments', [
            'site_id' => $peer->id,
            'coolify_deployment_uuid' => 'dep-gate-1',
            'status' => 'in_progress',
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/deploy'));
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function peerSites(): array
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => self::TOKEN,
        ]);

        return [
            $this->siteOnConnection($connection, 'app-busy'),
            $this->siteOnConnection($connection, 'app-peer'),
        ];
    }

    private function siteOnConnection(CoolifyConnection $connection, string $appUuid): Site
    {
        return Site::factory()->create([
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => $appUuid,
            'coolify_connection_id' => $connection->id,
        ]);
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }
}
