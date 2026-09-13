<?php

namespace Tests\Feature\Ops;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\OpsRole;
use App\Models\CoolifyConnection;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * `/jobs` is polled by every open ops tab and each poll reads the Coolify queue
 * per connection. That is the pressure behind the `Too Many Attempts.` incident
 * ([docs/superpowers/specs/2026-09-11-coolify-throttle-cure-design.md]), so the
 * read is shared and the widget's interval has to be able to grow.
 */
class JobsPollPressureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        Cache::flush();
        Queue::fake();
    }

    public function test_concurrent_pollers_share_one_coolify_queue_read(): void
    {
        $this->fakeCoolifyQueue();
        $this->queuedDeployment();

        $this->actingAs($this->operator())->getJson(route('ops.jobs'))->assertOk();
        $this->actingAs($this->operator())->getJson(route('ops.jobs'))->assertOk();
        $this->actingAs($this->operator())->getJson(route('ops.jobs'))->assertOk();

        $this->assertSame(1, $this->queueReads());
    }

    public function test_a_new_cache_window_reads_coolify_again(): void
    {
        $this->fakeCoolifyQueue();
        $this->queuedDeployment();

        $this->actingAs($this->operator())->getJson(route('ops.jobs'))->assertOk();
        $this->travel(config('ops.coolify.deploy.queue_cache_seconds') + 1)->seconds();
        $this->actingAs($this->operator())->getJson(route('ops.jobs'))->assertOk();

        $this->assertSame(2, $this->queueReads());
    }

    public function test_the_cache_can_be_switched_off(): void
    {
        config()->set('ops.coolify.deploy.queue_cache_seconds', 0);
        $this->fakeCoolifyQueue();
        $this->queuedDeployment();

        $this->actingAs($this->operator())->getJson(route('ops.jobs'))->assertOk();
        $this->actingAs($this->operator())->getJson(route('ops.jobs'))->assertOk();

        $this->assertSame(2, $this->queueReads());
    }

    public function test_cancelling_a_deploy_drops_the_cached_queue(): void
    {
        $this->fakeCoolifyQueue([
            'https://coolify.test/api/v1/deployments/dep-cache-cancel/cancel' => Http::response([
                'deployment_uuid' => 'dep-cache-cancel',
                'status' => 'cancelled-by-user',
            ], 200),
            'https://coolify.test/api/v1/deployments/dep-cache-cancel' => Http::response([
                'deployment_uuid' => 'dep-cache-cancel',
                'status' => 'cancelled-by-user',
            ], 200),
        ]);
        $deployment = $this->queuedDeployment('dep-cache-cancel');
        $operator = $this->operator();

        $this->actingAs($operator)->getJson(route('ops.jobs'))->assertOk();
        $this->assertSame(1, $this->queueReads());

        $this->actingAs($operator)
            ->postJson(route('ops.jobs.deployments.cancel', $deployment))
            ->assertOk();

        // The operator just changed the queue, so the next poll must not be told
        // the version of it they cancelled.
        $this->actingAs($operator)->getJson(route('ops.jobs'))->assertOk();
        $this->assertSame(2, $this->queueReads());
    }

    public function test_widget_carries_the_configured_poll_pacing(): void
    {
        config()->set('ops.jobs.poll', [
            'fast_ms' => 2000,
            'slow_ms' => 6000,
            'max_ms' => 12000,
            'slow_after' => 4,
        ]);

        $html = view('ops.partials.jobs-widget')->render();

        $this->assertStringContainsString('data-poll-fast-ms="2000"', $html);
        $this->assertStringContainsString('data-poll-slow-ms="6000"', $html);
        $this->assertStringContainsString('data-poll-max-ms="12000"', $html);
        $this->assertStringContainsString('data-poll-slow-after="4"', $html);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function fakeCoolifyQueue(array $extra = []): void
    {
        Http::fake($extra + [
            'https://coolify.test/api/v1/deployments' => Http::response([], 200),
        ]);
    }

    private function queueReads(): int
    {
        return Http::recorded()
            ->filter(static fn (array $pair): bool => str_ends_with(
                parse_url((string) $pair[0]->url(), PHP_URL_PATH) ?? '',
                '/api/v1/deployments',
            ))
            ->count();
    }

    private function queuedDeployment(string $uuid = 'dep-cache-1'): Deployment
    {
        $site = Site::factory()->create([
            'coolify_connection_id' => CoolifyConnection::query()->value('id')
                ?? CoolifyConnection::factory()->create()->id,
            'coolify_app_uuid' => 'app-'.$uuid,
        ]);

        return Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::Queued,
            'trigger' => DeploymentTrigger::Manual,
            'coolify_deployment_uuid' => $uuid,
            'started_at' => null,
            'finished_at' => null,
        ]);
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }
}
