<?php

namespace Tests\Feature\Ops;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\OpsRole;
use App\Models\CoolifyConnection;
use App\Models\Deployment;
use App\Models\OpsBackgroundJob;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The jobs widget is a mixed two-hour page. After a bulk sweep the failures
 * hide behind completed rows, and every poll still reads Coolify. `failed=1`
 * is the triage lane: only this operator's failures, no live queue merge.
 */
class JobsFailedFilterTest extends TestCase
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

    public function test_failed_equals_one_keeps_only_this_operators_failures(): void
    {
        $operator = $this->operator();
        $other = $this->operator();
        $site = Site::factory()->create(['name' => 'Hata Sitesi']);

        $this->job($operator, ['status' => 'failed', 'payload' => ['subject' => 'Benim hata']]);
        $this->job($operator, ['status' => 'completed', 'payload' => ['subject' => 'Benim bitti']]);
        $this->job($other, ['status' => 'failed', 'payload' => ['subject' => 'Baskasinin hata']]);
        Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::Failed,
            'trigger' => DeploymentTrigger::Manual,
            'requested_by' => $operator->id,
            'updated_at' => now(),
            'finished_at' => now(),
        ]);
        Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::Finished,
            'trigger' => DeploymentTrigger::Manual,
            'requested_by' => $operator->id,
            'updated_at' => now(),
            'finished_at' => now(),
        ]);

        $payload = $this->actingAs($operator)
            ->getJson(route('ops.jobs', ['failed' => '1']))
            ->assertOk()
            ->json();

        $this->assertSame(['failed'], collect($payload['jobs'])->pluck('status')->unique()->values()->all());
        $this->assertSame(['Benim hata'], collect($payload['jobs'])->pluck('subject')->all());
        $this->assertSame(['failed'], collect($payload['deployments'])->pluck('status')->unique()->values()->all());
        $this->assertNotContains('Benim bitti', collect($payload['jobs'])->pluck('subject')->all());
        $this->assertNotContains('Baskasinin hata', collect($payload['jobs'])->pluck('subject')->all());
    }

    public function test_an_unknown_failed_value_widens_to_the_mixed_list(): void
    {
        $operator = $this->operator();
        $this->job($operator, ['status' => 'completed', 'payload' => ['subject' => 'Karisik bitti']]);
        $this->job($operator, ['status' => 'failed', 'payload' => ['subject' => 'Karisik hata']]);

        $this->actingAs($operator)
            ->getJson(route('ops.jobs', ['failed' => 'yes']))
            ->assertOk()
            ->assertJsonFragment(['subject' => 'Karisik bitti'])
            ->assertJsonFragment(['subject' => 'Karisik hata']);
    }

    public function test_failed_only_does_not_read_the_coolify_queue(): void
    {
        $this->fakeCoolifyQueue();
        $this->queuedDeployment();
        $operator = $this->operator();
        $this->job($operator, ['status' => 'failed', 'payload' => ['subject' => 'Kuyruksuz hata']]);

        $this->actingAs($operator)
            ->getJson(route('ops.jobs', ['failed' => '1']))
            ->assertOk()
            ->assertJsonFragment(['subject' => 'Kuyruksuz hata']);

        $this->assertSame(0, $this->queueReads());
    }

    public function test_the_widget_declares_the_failed_only_toggle(): void
    {
        $html = view('ops.partials.jobs-widget')->render();

        $this->assertStringContainsString('data-ops-jobs-failed', $html);
        $this->assertStringContainsString('data-copy-failed-only', $html);
        $this->assertStringContainsString(__('ops.jobs.failed_only'), $html);
    }

    public function test_turkish_copy_is_used_for_a_turkish_operator(): void
    {
        $operator = User::factory()->create(['locale' => 'tr']);
        $operator->assignRole(OpsRole::Operator->value);

        $html = $this->actingAs($operator)
            ->get(route('ops.sites'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(__('ops.jobs.failed_only', [], 'tr'), $html);
        $this->assertStringNotContainsString(__('ops.jobs.failed_only', [], 'en'), $html);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function job(User $actor, array $overrides = []): OpsBackgroundJob
    {
        return OpsBackgroundJob::query()->create(array_merge([
            'type' => 'sites.live_sync',
            'title' => 'job',
            'status' => 'completed',
            'payload' => [],
            'actor_user_id' => $actor->id,
        ], $overrides));
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

    private function queuedDeployment(string $uuid = 'dep-failed-filter'): Deployment
    {
        $connection = CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.test',
            'api_token' => 'token',
        ]);
        $site = Site::factory()->create([
            'coolify_connection_id' => $connection->id,
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
