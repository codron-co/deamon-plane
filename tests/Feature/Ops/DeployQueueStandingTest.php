<?php

namespace Tests\Feature\Ops;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\OpsRole;
use App\Models\CoolifyConnection;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use App\Services\Ops\OpsCoolifyDeployQueue;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * `max_concurrent_per_server = 1` turns every bulk deploy into a queue, so a row
 * that only says "kuyrukta" hides how long the operator is waiting.
 */
class DeployQueueStandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
        Cache::flush();
    }

    public function test_two_queued_deploys_on_one_connection_report_positions_and_shared_depth(): void
    {
        $connection = CoolifyConnection::factory()->create();
        $first = $this->queuedDeployment($connection, 'dep-q-1');
        $second = $this->queuedDeployment($connection, 'dep-q-2');

        $standing = app(OpsCoolifyDeployQueue::class)->queueStanding();

        $this->assertSame(1, $standing['positions'][$first->id]['position']);
        $this->assertSame(2, $standing['positions'][$second->id]['position']);
        $this->assertSame(2, $standing['positions'][$first->id]['depth']);
        $this->assertSame(2, $standing['positions'][$second->id]['depth']);
        $this->assertSame(2, $standing['queued']);
        $this->assertSame(0, $standing['running']);
    }

    public function test_queues_on_separate_connections_do_not_share_depth(): void
    {
        $one = CoolifyConnection::factory()->create();
        $other = CoolifyConnection::factory()->create(['is_default' => false, 'name' => 'Coolify two']);
        $here = $this->queuedDeployment($one, 'dep-here');
        $there = $this->queuedDeployment($other, 'dep-there');

        $standing = app(OpsCoolifyDeployQueue::class)->queueStanding();

        $this->assertSame(['position' => 1, 'depth' => 1], $standing['positions'][$here->id]);
        $this->assertSame(['position' => 1, 'depth' => 1], $standing['positions'][$there->id]);
    }

    public function test_running_build_is_counted_but_never_given_a_queue_position(): void
    {
        $connection = CoolifyConnection::factory()->create();
        $running = $this->queuedDeployment($connection, 'dep-running');
        $running->forceFill(['status' => DeploymentStatus::InProgress])->save();
        $waiting = $this->queuedDeployment($connection, 'dep-waiting');

        $standing = app(OpsCoolifyDeployQueue::class)->queueStanding();

        $this->assertArrayNotHasKey($running->id, $standing['positions']);
        $this->assertSame(1, $standing['positions'][$waiting->id]['position']);
        $this->assertSame(1, $standing['running']);
        $this->assertSame(1, $standing['queued']);
    }

    public function test_finished_deploys_leave_the_queue(): void
    {
        $connection = CoolifyConnection::factory()->create();
        $done = $this->queuedDeployment($connection, 'dep-done');
        $done->forceFill(['status' => DeploymentStatus::Finished, 'finished_at' => now()])->save();
        $waiting = $this->queuedDeployment($connection, 'dep-still');

        $standing = app(OpsCoolifyDeployQueue::class)->queueStanding();

        $this->assertSame(['position' => 1, 'depth' => 1], $standing['positions'][$waiting->id]);
        $this->assertSame(1, $standing['queued']);
    }

    public function test_oldest_queued_deploy_is_first_in_line(): void
    {
        $connection = CoolifyConnection::factory()->create();
        $late = $this->queuedDeployment($connection, 'dep-late', now()->subMinute());
        $early = $this->queuedDeployment($connection, 'dep-early', now()->subMinutes(9));

        $standing = app(OpsCoolifyDeployQueue::class)->queueStanding();

        $this->assertSame(1, $standing['positions'][$early->id]['position']);
        $this->assertSame(2, $standing['positions'][$late->id]['position']);
    }

    public function test_widget_row_carries_a_translated_queue_label_for_a_waiting_deploy(): void
    {
        $connection = CoolifyConnection::factory()->create();
        $first = $this->queuedDeployment($connection, 'dep-label-1');
        $this->queuedDeployment($connection, 'dep-label-2');

        $queue = app(OpsCoolifyDeployQueue::class);
        $row = $queue->applyQueueStanding(
            ($first->fresh(['site']) ?? $first)->toWidget(),
            $queue->queueStanding(),
        );

        $this->assertSame(1, $row['queue_position']);
        $this->assertSame(2, $row['queue_depth']);
        $this->assertSame(__('ops.jobs.queue_position', ['position' => 1, 'depth' => 2]), $row['queue_label']);
    }

    /**
     * The widget never assembles copy, so both the row label and the header line
     * must already read as Turkish sentences when Plane runs in Turkish.
     */
    public function test_queue_copy_reads_as_turkish(): void
    {
        App::setLocale('tr');

        $connection = CoolifyConnection::factory()->create();
        $first = $this->queuedDeployment($connection, 'dep-tr-1');
        $this->queuedDeployment($connection, 'dep-tr-2');

        $queue = app(OpsCoolifyDeployQueue::class);
        $standing = $queue->queueStanding();
        $row = $queue->applyQueueStanding(($first->fresh(['site']) ?? $first)->toWidget(), $standing);

        $this->assertSame('sırada 1 / 2', $row['queue_label']);
        $this->assertSame('2 kuyrukta', $queue->queueSummaryLabel($standing));
        $this->assertSame(
            '1 derleniyor · 22 kuyrukta',
            $queue->queueSummaryLabel(['running' => 1, 'queued' => 22, 'positions' => []]),
        );
    }

    public function test_running_widget_row_gets_no_queue_label(): void
    {
        $connection = CoolifyConnection::factory()->create();
        $running = $this->queuedDeployment($connection, 'dep-live');
        $running->forceFill(['status' => DeploymentStatus::InProgress])->save();

        $queue = app(OpsCoolifyDeployQueue::class);
        $row = $queue->applyQueueStanding(
            ($running->fresh(['site']) ?? $running)->toWidget(),
            $queue->queueStanding(),
        );

        $this->assertNull($row['queue_label']);
        $this->assertNull($row['queue_position']);
        $this->assertNull($row['queue_depth']);
    }

    public function test_summary_label_names_the_build_and_the_wait(): void
    {
        $queue = app(OpsCoolifyDeployQueue::class);

        $this->assertSame(
            __('ops.jobs.queue_building', ['count' => 1]).' · '.__('ops.jobs.queue_waiting', ['count' => 22]),
            $queue->queueSummaryLabel(['running' => 1, 'queued' => 22, 'positions' => []]),
        );
        // Nothing building yet: the line must not claim a build that is not running.
        $this->assertSame(
            __('ops.jobs.queue_waiting', ['count' => 3]),
            $queue->queueSummaryLabel(['running' => 0, 'queued' => 3, 'positions' => []]),
        );
    }

    public function test_summary_label_is_absent_when_nothing_is_waiting(): void
    {
        $this->assertNull(
            app(OpsCoolifyDeployQueue::class)->queueSummaryLabel(['running' => 1, 'queued' => 0, 'positions' => []]),
        );
    }

    public function test_jobs_index_reports_position_depth_and_header_summary(): void
    {
        Queue::fake();
        $connection = CoolifyConnection::factory()->create();
        Http::fake([
            'https://coolify.test/api/v1/deployments' => Http::response([], 200),
        ]);

        $running = $this->queuedDeployment($connection, 'dep-idx-run');
        $running->forceFill(['status' => DeploymentStatus::InProgress])->save();
        $this->queuedDeployment($connection, 'dep-idx-1');
        $this->queuedDeployment($connection, 'dep-idx-2');

        $response = $this->actingAs($this->operator())
            ->getJson(route('ops.jobs'))
            ->assertOk()
            ->assertJsonPath('queue.running', 1)
            ->assertJsonPath('queue.queued', 2)
            ->assertJsonPath(
                'queue.label',
                __('ops.jobs.queue_building', ['count' => 1]).' · '.__('ops.jobs.queue_waiting', ['count' => 2]),
            );

        $rows = collect($response->json('deployments'))
            ->keyBy(static fn (array $row): string => (string) $row['coolify_deployment_uuid']);

        $this->assertSame(1, $rows['dep-idx-1']['queue_position']);
        $this->assertSame(2, $rows['dep-idx-1']['queue_depth']);
        $this->assertSame(2, $rows['dep-idx-2']['queue_position']);
        $this->assertSame(
            __('ops.jobs.queue_position', ['position' => 2, 'depth' => 2]),
            $rows['dep-idx-2']['queue_label'],
        );
        $this->assertNull($rows['dep-idx-run']['queue_label']);
    }

    public function test_depth_counts_the_whole_queue_not_the_widget_page(): void
    {
        $connection = CoolifyConnection::factory()->create();
        foreach (range(1, 35) as $index) {
            $this->queuedDeployment($connection, 'dep-deep-'.$index);
        }

        $standing = app(OpsCoolifyDeployQueue::class)->queueStanding();

        $this->assertSame(35, $standing['queued']);
        $this->assertSame(35, collect($standing['positions'])->first()['depth']);
    }

    /**
     * Rows stay fresh by default: the jobs endpoint kicks a Coolify re-read for
     * anything older than 20s, which is not what these assertions are about.
     */
    private function queuedDeployment(CoolifyConnection $connection, string $uuid, ?Carbon $createdAt = null): Deployment
    {
        $site = Site::factory()->create([
            'coolify_connection_id' => $connection->id,
            'coolify_app_uuid' => 'app-'.$uuid,
        ]);

        $deployment = Deployment::factory()->create([
            'site_id' => $site->id,
            'status' => DeploymentStatus::Queued,
            'trigger' => DeploymentTrigger::Manual,
            'coolify_deployment_uuid' => $uuid,
            'started_at' => null,
            'finished_at' => null,
        ]);

        if ($createdAt !== null) {
            $deployment->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
        }

        return $deployment;
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole(OpsRole::Operator->value);

        return $operator;
    }
}
