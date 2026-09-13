<?php

namespace Tests\Feature\Ops;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Models\CoolifyConnection;
use App\Models\OpsBackgroundJob;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use App\Services\Ops\OpsJobRunner;
use App\Support\Lists\ListFragment;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * /domains had a per-row Bind and nothing else. Clearing 30 unbound hosts
 * after a Cloudflare change was 30 clicks. The bulk bar adopts the P0-1
 * summary contract; the sweep talks to Coolify once per site and treats an
 * already-bound host as a no-op.
 */
class DomainBulkBindTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Queue::fake();
        Sleep::fake();
        Http::preventStrayRequests();
        config()->set('ops.coolify.rate.min_interval_ms', 0);
        config()->set('ops.coolify.retry.max_attempts', 1);
        config()->set('ops.coolify.bulk.max_site_attempts', 1);
    }

    public function test_the_bulk_bar_publishes_the_filtered_total_and_a_counted_confirm(): void
    {
        $this->boundHost('keep.example.test');
        $this->unboundHost('one.example.test');
        $this->unboundHost('two.example.test');

        $html = $this->actingAs($this->user(OpsRole::Operator))
            ->get(route('ops.domains', ['unbound' => 1]))
            ->assertOk()
            ->assertSee('data-bulk-total="2"', false)
            ->assertDontSee('data-bulk-total="3"', false)
            ->assertSee('name="domain_ids[]"', false)
            ->getContent();

        $template = __('domains.bulk.confirm_unbound', ['count' => '__COUNT__']);
        $this->assertStringContainsString('data-confirm-template="'.e($template).'"', $html);
        $this->assertStringContainsString(
            'data-confirm="'.e(__('domains.bulk.confirm_unbound', ['count' => 2])).'"',
            $html,
        );
        $this->assertStringContainsString(__('domains.bulk.skip_hint'), $html);
        $this->assertStringContainsString(__('domains.bulk.summary_all_unbound', ['total' => 2]), $html);
    }

    public function test_all_equals_one_under_the_unbound_filter_queues_only_that_set(): void
    {
        $keep = $this->boundHost('keep.example.test');
        $one = $this->unboundHost('one.example.test');
        $two = $this->unboundHost('two.example.test');
        $other = $this->unboundHost('other.example.test');

        $this->actingAs($this->user(OpsRole::Operator))
            ->postJson(route('ops.domains.bulk-bind'), [
                'all' => '1',
                'filter_unbound' => '1',
                'filter_q' => 'example.test',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('job.type', 'domains.bulk_bind');

        $payload = OpsBackgroundJob::query()->latest('id')->firstOrFail()->payload;
        $ids = $payload['domain_ids'];
        sort($ids);

        $this->assertSame([$one->id, $two->id, $other->id], $ids);
        $this->assertNotContains($keep->id, $ids);
    }

    public function test_two_aliases_on_one_site_cost_one_coolify_write(): void
    {
        $connection = $this->connection();
        $site = $this->site($connection, 'shop.example.test', 'app-shop');
        $primary = $this->host($site, 'shop.example.test', primary: true);
        $www = $this->host($site, 'www.shop.example.test', www: true);

        Http::fake([
            'https://coolify.test/*' => Http::response(['uuid' => 'app-shop'], 200),
        ]);

        $job = $this->job([$primary->id, $www->id]);
        $message = app(OpsJobRunner::class)->run($job);

        $this->assertStringContainsString(__('ops.bulk.result', ['ok' => 2]), $message);
        $this->assertStringNotContainsString(__('ops.jobs.status.triggered'), $message);
        $this->assertNotNull($primary->fresh()?->verified_at);
        $this->assertNotNull($www->fresh()?->verified_at);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH');
        $this->assertSame(1, collect(Http::recorded())->filter(
            fn (array $pair): bool => $pair[0]->method() === 'PATCH',
        )->count());
    }

    public function test_re_binding_an_already_bound_host_is_a_noop(): void
    {
        $keep = $this->boundHost('keep.example.test');

        $message = app(OpsJobRunner::class)->run($this->job([$keep->id]));

        $this->assertStringContainsString(__('ops.bulk.result', ['ok' => 1]), $message);
        $this->assertNotNull($keep->fresh()?->verified_at);
        Http::assertNothingSent();
    }

    public function test_a_429_is_skipped_not_failed_and_leaves_the_host_unbound(): void
    {
        $row = $this->unboundHost('slow.example.test');

        Http::fake([
            'https://coolify.test/*' => Http::response(['message' => 'Too Many Attempts.'], 429),
        ]);

        $message = app(OpsJobRunner::class)->run($this->job([$row->id]));

        $this->assertStringContainsString(__('ops.bulk.result_skipped', ['ok' => 0, 'skipped' => 1]), $message);
        $this->assertStringContainsString(__('ops.bulk.rate_limited'), $message);
        $this->assertStringNotContainsString(__('ops.bulk.result_failed', ['ok' => 0, 'failed' => 1]), $message);
        $this->assertNull($row->fresh()?->verified_at);
    }

    public function test_a_host_without_a_coolify_app_is_unready_not_a_rate_limit_skip(): void
    {
        $site = Site::factory()->create([
            'name' => 'No App',
            'coolify_app_uuid' => null,
        ]);
        $orphan = SiteDomain::factory()->create([
            'site_id' => $site->id,
            'domain' => 'orphan.example.test',
            'verified_at' => null,
        ]);

        $message = app(OpsJobRunner::class)->run($this->job([$orphan->id]));

        $this->assertStringContainsString(__('domains.flash.unready', ['count' => 1]), $message);
        $this->assertStringNotContainsString(__('ops.bulk.rate_limited'), $message);
        Http::assertNothingSent();
    }

    public function test_a_mixed_list_confirm_says_already_bound_hosts_are_skipped(): void
    {
        $this->boundHost('keep.example.test');
        $this->unboundHost('one.example.test');
        $this->unboundHost('two.example.test');

        $html = $this->actingAs($this->user(OpsRole::Operator, 'tr'))
            ->get(route('ops.domains'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'data-confirm="'.e(trans('domains.bulk.confirm', ['count' => 3], 'tr')).'"',
            $html,
        );
        $this->assertStringContainsString(trans('domains.bulk.skip_count', ['unbound' => 2], 'tr'), $html);
        $this->assertStringNotContainsString(trans('domains.bulk.confirm_unbound', ['count' => 3], 'tr'), $html);
        $this->assertStringNotContainsString(trans('domains.bulk.confirm', ['count' => 3], 'en'), $html);
    }

    public function test_the_overlay_copy_reads_as_turkish_for_a_turkish_operator(): void
    {
        $this->unboundHost('tr.example.test');

        $this->actingAs($this->user(OpsRole::Operator, 'tr'))
            ->get(route('ops.domains'))
            ->assertOk()
            ->assertSee(trans('domains.bulk.confirm_title', [], 'tr'), false)
            ->assertSee(trans('domains.actions.bind', [], 'tr'), false)
            ->assertDontSee(trans('domains.bulk.confirm_title', [], 'en'), false);
    }

    public function test_a_viewer_never_sees_the_bulk_bar(): void
    {
        $this->unboundHost('view.example.test');

        $this->actingAs($this->user(OpsRole::Viewer))
            ->get(route('ops.domains'))
            ->assertOk()
            ->assertDontSee('data-ops-bulk', false)
            ->assertDontSee('name="domain_ids[]"', false)
            ->assertDontSee(route('ops.domains.bulk-bind'), false)
            ->assertDontSee(route('ops.domains.bulk-clear'), false);
    }

    public function test_the_async_region_reships_the_summary(): void
    {
        $this->unboundHost('a.example.test');
        $this->unboundHost('b.example.test');

        $this->actingAs($this->user(OpsRole::Operator))
            ->withHeader(ListFragment::HEADER, ListFragment::VALUE)
            ->get(route('ops.domains', ['unbound' => 1]))
            ->assertOk()
            ->assertHeader('X-Ops-List-Region', '1')
            ->assertSee('data-bulk-total="2"', false)
            ->assertSee('data-ops-bulk-summary-text', false);
    }

    public function test_bind_does_not_claim_a_coolify_rebuild(): void
    {
        $job = OpsBackgroundJob::query()->create([
            'type' => 'domains.bulk_bind',
            'title' => 'bind',
            'status' => 'completed',
            'payload' => ['domain_ids' => [1]],
            'actor_user_id' => $this->user(OpsRole::Operator)->id,
        ]);

        $this->assertFalse($job->triggersRemoteWork());
        $this->assertSame(__('ops.jobs.status.completed'), $job->statusLabel());
        $this->assertSame(trans_choice('ops.jobs.subject_domains', 1, ['count' => 1]), $job->subjectLabel());
    }

    /**
     * @param  list<int>  $ids
     */
    private function job(array $ids): OpsBackgroundJob
    {
        return OpsBackgroundJob::query()->create([
            'type' => 'domains.bulk_bind',
            'title' => __('ops.jobs.bulk_bind'),
            'status' => 'queued',
            'payload' => ['domain_ids' => $ids],
            'actor_user_id' => $this->user(OpsRole::Operator)->id,
        ]);
    }

    private function unboundHost(string $host): SiteDomain
    {
        $connection = $this->connection();
        $site = $this->site($connection, $host, 'app-'.str_replace('.', '-', $host));

        return $this->host($site, $host, primary: true);
    }

    private function boundHost(string $host): SiteDomain
    {
        $row = $this->unboundHost($host);
        $row->forceFill(['verified_at' => now()])->save();

        return $row->fresh() ?? $row;
    }

    private function host(Site $site, string $host, bool $primary = false, bool $www = false): SiteDomain
    {
        return SiteDomain::factory()->create([
            'site_id' => $site->id,
            'domain' => $host,
            'is_primary' => $primary,
            'is_www' => $www,
            'verified_at' => null,
        ]);
    }

    private function site(CoolifyConnection $connection, string $primary, string $uuid): Site
    {
        return Site::factory()->create([
            'name' => $primary,
            'primary_domain' => $primary,
            'status' => SiteStatus::Active,
            'coolify_app_uuid' => $uuid,
            'coolify_connection_id' => $connection->id,
        ]);
    }

    private function connection(): CoolifyConnection
    {
        return CoolifyConnection::query()->first() ?? CoolifyConnection::factory()->create([
            'base_url' => 'https://coolify.test',
            'api_token' => 'domain-bind-token',
            'is_default' => true,
        ]);
    }

    private function user(OpsRole $role, ?string $locale = null): User
    {
        $user = User::factory()->create($locale === null ? [] : ['locale' => $locale]);
        $user->assignRole($role->value);

        return $user;
    }
}
