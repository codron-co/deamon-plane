<?php

namespace Tests\Feature\Ops;

use App\Enums\DeploymentStatus;
use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CoolifyConnection;
use App\Models\Deployment;
use App\Models\OpsBackgroundJob;
use App\Models\Site;
use App\Models\User;
use App\Services\Ops\OpsJobRunner;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * `max_concurrent_per_server` is 1, so a bulk deploy is one build plus a queue.
 * The product bar: a site that never got to deploy because the host slot was
 * taken is reported as waiting, in its own Turkish words — not as `hata`, which
 * blames the site, and not as `atlandı`, which blames the request limit.
 */
class BulkDeployWaitingBucketTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'plane-waiting-bucket-token';

    private ?CoolifyConnection $connection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->app->setLocale('tr');
        Queue::fake();
        Sleep::fake();
        Http::preventStrayRequests();
        config()->set('ops.coolify.rate.min_interval_ms', 0);
        config()->set('ops.coolify.retry.max_attempts', 1);
        config()->set('ops.coolify.bulk.max_site_attempts', 2);
        config()->set('ops.coolify.deploy.max_concurrent_per_server', 1);
    }

    public function test_a_sweep_does_not_queue_behind_its_own_builds(): void
    {
        $sites = $this->sites(6);
        Http::fake(fn (Request $request) => $this->coolifyResponse($request));

        $message = app(OpsJobRunner::class)->run($this->job('sites.bulk_deploy', $sites));

        $this->assertSame(
            __('site_ops.redeploy.bulk').' '.__('ops.bulk.triggered', ['ok' => 6])
            .' — '.__('ops.bulk.triggered_note'),
            $message,
        );
        $this->assertStringNotContainsString('hata', $message);

        foreach ($sites as $site) {
            $this->assertSame(1, $site->deployments()->count(), $site->name.' should have been deployed.');
        }
    }

    public function test_sites_blocked_by_a_foreign_build_are_waiting_not_failed(): void
    {
        $sites = $this->sites(3);
        $this->openForeignBuild();
        Http::fake(fn (Request $request) => $this->coolifyResponse($request));

        $message = app(OpsJobRunner::class)->run($this->job('sites.bulk_deploy', $sites));

        $this->assertSame(
            __('site_ops.redeploy.bulk').' '
            .__('ops.bulk.triggered', ['ok' => 0]).', '.__('ops.bulk.waiting', ['waiting' => 3])
            .' — '.__('ops.bulk.deploy_busy'),
            $message,
        );
        $this->assertStringContainsString('sıra bekliyor', $message);
        $this->assertStringNotContainsString('hata', $message);
        $this->assertStringNotContainsString('atlandı', $message);
        $this->assertStringNotContainsString(__('ops.bulk.rate_limited'), $message);
    }

    public function test_a_waiting_site_is_left_exactly_as_it_was(): void
    {
        $sites = $this->sites(2);
        $this->openForeignBuild();
        Http::fake(fn (Request $request) => $this->coolifyResponse($request));

        app(OpsJobRunner::class)->run($this->job('sites.bulk_deploy', $sites));

        Http::assertNothingSent();

        foreach ($sites as $site) {
            $this->assertSame(0, $site->deployments()->count(), $site->name.' should not have a deploy row.');
        }
    }

    public function test_a_real_failure_and_a_waiting_site_keep_their_own_words(): void
    {
        // Two hosts: one with the slot taken, one free but answering 422.
        $waiting = $this->sites(1);
        $this->openForeignBuild();
        $broken = $this->siteOnSecondConnection();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'coolify-b.example')) {
                return Http::response(['message' => 'Validation failed.'], 422);
            }

            return $this->coolifyResponse($request);
        });

        $message = app(OpsJobRunner::class)->run(
            $this->job('sites.bulk_deploy', [...$waiting, $broken]),
        );

        $this->assertStringContainsString(__('ops.bulk.triggered_failed', ['ok' => 0, 'failed' => 1]), $message);
        $this->assertStringContainsString(__('ops.bulk.waiting', ['waiting' => 1]), $message);
        $this->assertStringContainsString(__('ops.bulk.deploy_busy'), $message);
        $this->assertStringNotContainsString(__('ops.bulk.rate_limited'), $message);
    }

    public function test_a_pin_sweep_behind_a_busy_slot_waits_instead_of_failing(): void
    {
        $sites = $this->sites(2);
        $this->openForeignBuild();
        Http::fake(fn (Request $request) => $this->coolifyResponse($request));

        $message = app(OpsJobRunner::class)->run($this->job('sites.bulk_pin', $sites, ['ref' => 'abc1234']));

        $this->assertStringContainsString(__('ops.bulk.waiting', ['waiting' => 2]), $message);
        $this->assertStringNotContainsString('hata', $message);
        Http::assertNothingSent();
    }

    public function test_the_gate_still_refuses_a_single_redeploy_after_the_sweep_closes(): void
    {
        $sites = $this->sites(2);
        Http::fake(fn (Request $request) => $this->coolifyResponse($request));

        app(OpsJobRunner::class)->run($this->job('sites.bulk_deploy', $sites));

        // The sweep left two open builds on the connection; an unrelated deploy
        // must still queue behind them.
        $this->actingAs($this->operator())
            ->post(route('ops.sites.deploy', $sites[0]))
            ->assertRedirect()
            ->assertSessionHas('error', __('coolify.errors.deploy_busy', [
                'max' => 1,
                'count' => 2,
            ]));

        $this->assertSame(1, $sites[0]->deployments()->count());
    }

    /**
     * A build somebody else started, on a peer site that is not in the sweep.
     */
    private function openForeignBuild(): Deployment
    {
        $peer = Site::factory()->create([
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => 'waiting-app-foreign',
            'coolify_connection_id' => $this->connection()->id,
        ]);

        return Deployment::factory()->create([
            'site_id' => $peer->id,
            'status' => DeploymentStatus::InProgress,
            'started_at' => now()->subMinute(),
            'finished_at' => null,
        ]);
    }

    /**
     * @param  list<Site>  $sites
     * @param  array<string, mixed>  $payload
     */
    private function job(string $type, array $sites, array $payload = []): OpsBackgroundJob
    {
        return OpsBackgroundJob::query()->create([
            'type' => $type,
            'title' => $type,
            'status' => 'queued',
            'progress' => 0,
            'payload' => array_merge($payload, [
                'site_ids' => array_map(static fn (Site $site) => $site->id, $sites),
            ]),
            'actor_user_id' => $this->operator()->id,
        ]);
    }

    private function coolifyResponse(Request $request)
    {
        $url = $request->url();

        if (str_contains($url, '/envs')) {
            return Http::response([], 200);
        }

        if ($request->method() === 'POST' && str_contains($url, '/deploy')) {
            return Http::response([
                'deployments' => [['deployment_uuid' => 'dep-'.bin2hex(random_bytes(4))]],
            ], 200);
        }

        if (str_contains($url, '/deployments/applications/')) {
            return Http::response([], 200);
        }

        return Http::response([
            'uuid' => 'waiting-app',
            'build_pack' => 'dockercompose',
            'git_commit_sha' => 'abc1234',
            'is_auto_deploy_enabled' => false,
            'settings' => ['is_auto_deploy_enabled' => false],
        ], 200);
    }

    /**
     * @return list<Site>
     */
    private function sites(int $count): array
    {
        $sites = [];
        for ($i = 0; $i < $count; $i++) {
            $sites[] = Site::factory()->create([
                'status' => SiteStatus::Active,
                'coolify_app_uuid' => 'waiting-app-'.$i,
                'coolify_connection_id' => $this->connection()->id,
            ]);
        }

        return $sites;
    }

    private function siteOnSecondConnection(): Site
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify-b.example',
            'api_token' => self::TOKEN.'-b',
        ]);

        return Site::factory()->create([
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => 'waiting-app-b',
            'coolify_connection_id' => $connection->id,
        ]);
    }

    private function connection(): CoolifyConnection
    {
        return $this->connection ??= CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.example',
            'api_token' => self::TOKEN,
        ]);
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }
}
