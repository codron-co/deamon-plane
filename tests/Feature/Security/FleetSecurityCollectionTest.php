<?php

namespace Tests\Feature\Security;

use App\Jobs\CollectSiteSecurityJob;
use App\Jobs\DispatchSecurityCollectionJob;
use App\Models\Site;
use App\Models\SiteSecurityCursor;
use App\Models\SiteSecurityEvent;
use App\Services\Agent\AgentHealthResult;
use App\Services\Agent\SiteHealthChecker;
use App\Services\Security\SecurityCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Security\Concerns\FakesSecuritySites;
use Tests\TestCase;

class FleetSecurityCollectionTest extends TestCase
{
    use FakesSecuritySites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSecurityFakes();
    }

    public function test_collect_stores_events_with_a_signed_call_and_keeps_the_cursor(): void
    {
        $site = $this->activeSite('alpha');
        $this->fakeSite('alpha', $this->summaryPayload([
            $this->eventPayload('e1'),
            $this->eventPayload('e2', ['path' => '/wp-login.php?token=SECRET123']),
        ]));

        $result = app(SecurityCollector::class)->collect($site);

        $this->assertSame(2, $result['stored']);
        $this->assertSame(2, SiteSecurityEvent::query()->count());
        $this->assertSame('/wp-login.php', SiteSecurityEvent::query()->where('event_uid', 'e2')->value('path'), 'query string must be cut');
        $this->assertTrue($this->siteCalls[0]['signed']);
        $this->assertSame('GET', $this->siteCalls[0]['method']);
        $this->assertSame('/internal/control/v1/security/summary', $this->siteCalls[0]['path']);

        $cursor = SiteSecurityCursor::query()->where('site_id', $site->id)->firstOrFail();
        $this->assertSame('c-2', $cursor->cursor);
        $this->assertTrue($cursor->supported);
        $this->assertTrue($cursor->module_enabled);
        $this->assertSame(0, $cursor->consecutive_failures);
        $this->assertSame(2, $cursor->summary['counts']['24h']['total']);
        $this->assertSame('203.0.113.7', $cursor->summary['top_ips'][0]['ip']);
    }

    public function test_next_poll_sends_the_cursor_and_replayed_events_are_not_duplicated(): void
    {
        $site = $this->activeSite('alpha');
        $this->fakeSite('alpha', $this->summaryPayload([$this->eventPayload('e1')]));

        app(SecurityCollector::class)->collect($site);
        $second = app(SecurityCollector::class)->collect($site->fresh());

        $this->assertSame(0, $second['stored']);
        $this->assertSame(1, SiteSecurityEvent::query()->count());
        $this->assertNull($this->siteCalls[0]['query']);
        $this->assertSame('since=c-1', $this->siteCalls[1]['query']);
    }

    public function test_the_same_uid_with_a_new_count_updates_the_row_without_duplicating(): void
    {
        $site = $this->activeSite('alpha');
        $round = 0;
        $this->fakeSite('alpha', function (Request $request) use (&$round) {
            $round++;

            return Http::response($this->summaryPayload(
                [$this->eventPayload('e1', ['count' => $round === 1 ? 1 : 7])],
                ['cursor' => 'v1.17000000'.$round.'.1'],
            ), 200);
        });
        app(SecurityCollector::class)->collect($site);

        $second = app(SecurityCollector::class)->collect($site->fresh());

        $this->assertSame(0, $second['stored'], 'an update is not a new event');
        $this->assertSame(1, SiteSecurityEvent::query()->count());
        $this->assertSame(7, SiteSecurityEvent::query()->value('count'));
        $this->assertSame('v1.170000002.1', SiteSecurityCursor::query()->value('cursor'));
    }

    public function test_the_same_uid_on_two_sites_is_two_events(): void
    {
        $a = $this->activeSite('alpha');
        $b = $this->activeSite('beta');
        $this->fakeSite('alpha', $this->summaryPayload([$this->eventPayload('same')]));
        $this->fakeSite('beta', $this->summaryPayload([$this->eventPayload('same')]));

        app(SecurityCollector::class)->collect($a);
        app(SecurityCollector::class)->collect($b);

        $this->assertSame(2, SiteSecurityEvent::query()->count());
    }

    public function test_invalid_events_are_dropped_and_secrets_never_stored(): void
    {
        $site = $this->activeSite('alpha');
        $this->fakeSite('alpha', $this->summaryPayload([
            $this->eventPayload('ok'),
            $this->eventPayload('bad-ip', ['ip' => 'not-an-ip']),
            $this->eventPayload('bad uid!', []),
            $this->eventPayload('no-time', ['occurred_at' => 'garbage']),
            $this->eventPayload('future', ['occurred_at' => now()->addDay()->toIso8601String()]),
        ]));

        $result = app(SecurityCollector::class)->collect($site);

        $this->assertSame(1, $result['stored']);
        $this->assertSame(['ok'], SiteSecurityEvent::query()->pluck('event_uid')->all());
        $this->assertArrayNotHasKey('body', SiteSecurityEvent::query()->first()->getAttributes());
    }

    public function test_a_payload_that_echoes_the_agent_secret_is_discarded(): void
    {
        $site = $this->activeSite('alpha');
        $secret = (string) $site->agent_secret_encrypted;
        $this->fakeSite('alpha', $this->summaryPayload([$this->eventPayload('e1', ['user_agent' => 'leak '.$secret])]));

        app(SecurityCollector::class)->collect($site);

        $this->assertSame(0, SiteSecurityEvent::query()->count());
        $cursor = SiteSecurityCursor::query()->where('site_id', $site->id)->firstOrFail();
        $this->assertSame(1, $cursor->consecutive_failures);
    }

    public function test_a_failing_site_is_recorded_not_thrown_and_others_still_collect(): void
    {
        $bad = $this->activeSite('alpha');
        $good = $this->activeSite('beta');
        $this->fakeSite('alpha', fn (Request $request) => Http::response('boom', 500));
        $this->fakeSite('beta', $this->summaryPayload([$this->eventPayload('e1')]));

        $first = app(SecurityCollector::class)->collect($bad);
        $second = app(SecurityCollector::class)->collect($good);

        $this->assertSame('failed', $first['state']);
        $cursor = SiteSecurityCursor::query()->where('site_id', $bad->id)->firstOrFail();
        $this->assertSame(1, $cursor->consecutive_failures);
        $this->assertNotNull($cursor->last_error);
        $this->assertSame(1, $second['stored']);

        // The job swallows even a crash: a queue worker never sees an exception for one site.
        (new CollectSiteSecurityJob($bad->id))->handle(app(SecurityCollector::class));
        $this->assertSame(2, SiteSecurityCursor::query()->where('site_id', $bad->id)->value('consecutive_failures'));
    }

    public function test_an_old_cms_without_the_endpoint_is_marked_unsupported_not_failing(): void
    {
        $site = $this->activeSite('alpha');
        $this->fakeSite('alpha', fn (Request $request) => Http::response('Not Found', 404));

        $result = app(SecurityCollector::class)->collect($site);

        $this->assertSame('unsupported', $result['state']);
        $cursor = SiteSecurityCursor::query()->where('site_id', $site->id)->firstOrFail();
        $this->assertFalse($cursor->supported);
        $this->assertSame(0, $cursor->consecutive_failures);
    }

    public function test_a_module_that_is_off_stores_no_events_but_is_remembered(): void
    {
        $site = $this->activeSite('alpha');
        $this->fakeSite('alpha', $this->summaryPayload([$this->eventPayload('e1')], ['module_enabled' => false]));

        app(SecurityCollector::class)->collect($site);

        $this->assertSame(0, SiteSecurityEvent::query()->count());
        $this->assertFalse(SiteSecurityCursor::query()->where('site_id', $site->id)->firstOrFail()->module_enabled);
    }

    public function test_pages_are_bounded_per_run(): void
    {
        config(['ops.security.max_pages_per_run' => 2]);
        $site = $this->activeSite('alpha');
        $page = 0;
        $this->fakeSite('alpha', function (Request $request) use (&$page) {
            $page++;

            return Http::response($this->summaryPayload([$this->eventPayload('p'.$page)], ['has_more' => true, 'cursor' => 'c'.$page]), 200);
        });

        app(SecurityCollector::class)->collect($site);

        $this->assertSame(2, count($this->siteCalls), 'never more than max_pages_per_run calls to one site in a run');
        $this->assertSame(2, SiteSecurityEvent::query()->count());
    }

    public function test_dispatch_spaces_sites_out_and_puts_the_test_site_first(): void
    {
        config(['ops.security.spacing_seconds' => 5]);
        $this->activeSite('zeta');
        $this->activeSite('test');
        $this->activeSite('beta');
        Site::factory()->withSecrets()->create(['slug' => 'draft', 'name' => 'Draft', 'primary_domain' => 'draft.example.test']);
        Queue::fake();

        (new DispatchSecurityCollectionJob)->handle();

        $pushed = Queue::pushed(CollectSiteSecurityJob::class);
        $this->assertCount(3, $pushed, 'draft sites are not polled');
        $order = $pushed->map(fn (CollectSiteSecurityJob $job) => Site::query()->find($job->siteId)->slug)->all();
        $this->assertSame('test', $order[0]);
        $delays = $pushed->map(fn (CollectSiteSecurityJob $job) => (int) round(now()->diffInSeconds($job->delay, false)))->all();
        $this->assertGreaterThanOrEqual(0, $delays[0]);
        $this->assertGreaterThanOrEqual(4, $delays[1], 'jobs are spaced, not fired together');
        $this->assertGreaterThanOrEqual(9, $delays[2]);
    }

    public function test_dispatch_skips_recently_unsupported_and_backs_off_failing_sites(): void
    {
        $old = $this->activeSite('old');
        $flaky = $this->activeSite('flaky');
        $fine = $this->activeSite('fine');
        SiteSecurityCursor::query()->create(['site_id' => $old->id, 'supported' => false, 'last_polled_at' => now()->subHours(2)]);
        SiteSecurityCursor::query()->create(['site_id' => $flaky->id, 'supported' => true, 'consecutive_failures' => 5, 'last_polled_at' => now()->subMinutes(15)]);
        SiteSecurityCursor::query()->create(['site_id' => $fine->id, 'supported' => true, 'last_polled_at' => now()->subMinutes(15)]);

        $due = collect((new DispatchSecurityCollectionJob)->due())->pluck('slug')->all();

        $this->assertSame(['fine'], $due);
    }

    public function test_the_health_poll_keeps_the_small_security_block(): void
    {
        $site = $this->activeSite('alpha');
        Http::fake([
            'https://alpha.example.test/internal/control/v1/health' => Http::response([
                'deamon_version' => '1.2.120',
                'queue_ok' => true,
                'security' => ['module_enabled' => true, 'critical_24h' => 3, 'blocks_active' => 2, 'secret_sauce' => 'x'],
            ], 200),
        ]);

        app(SiteHealthChecker::class)->check($site);

        $this->assertSame(
            ['module_enabled' => true, 'critical_24h' => 3, 'blocks_active' => 2],
            $site->fresh()->last_health_payload['security'],
        );
        $this->assertNotNull(AgentHealthResult::fromCmsPayload(['queue_ok' => true], 200)->summary);
    }
}
