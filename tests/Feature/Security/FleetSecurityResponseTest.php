<?php

namespace Tests\Feature\Security;

use App\Enums\OpsRole;
use App\Models\AuditLog;
use App\Models\FleetAllowlistEntry;
use App\Models\FleetIpBlock;
use App\Models\OpsBackgroundJob;
use App\Services\Ops\OpsJobRunner;
use App\Services\Security\FleetSecurityApplier;
use App\Services\Security\FleetSecurityResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use RuntimeException;
use Tests\Feature\Security\Concerns\FakesSecuritySites;
use Tests\TestCase;

class FleetSecurityResponseTest extends TestCase
{
    use FakesSecuritySites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSecurityFakes();
        config(['ops.security.apply_spacing_ms' => 0]);
    }

    public function test_block_writes_intent_audit_and_a_job_and_is_idempotent_per_address(): void
    {
        Queue::fake();
        $emin = $this->user();

        $first = app(FleetSecurityResponse::class)->block('203.0.113.7', 'scanner', now()->addHours(6), $emin, '10.0.0.1');
        $again = app(FleetSecurityResponse::class)->block('203.0.113.7', 'scanner', null, $emin);

        $this->assertFalse($first['reused']);
        $this->assertTrue($again['reused']);
        $this->assertSame($first['block']->id, $again['block']->id, 'one record per address');
        $this->assertSame(1, FleetIpBlock::query()->count());
        $this->assertSame(2, OpsBackgroundJob::query()->where('type', 'security.fleet_apply')->count());

        $audit = AuditLog::query()->where('action', 'security.fleet_block')->sole();
        $this->assertSame($emin->id, $audit->actor_user_id);
        $this->assertSame('203.0.113.7', $audit->after['ip']);
        $this->assertSame('10.0.0.1', $audit->ip);
    }

    public function test_the_test_site_goes_first_then_the_rest_one_by_one(): void
    {
        $this->activeSite('zeta');
        $this->activeSite('test');
        $this->activeSite('beta');
        foreach (['zeta', 'test', 'beta'] as $slug) {
            $this->fakeSite($slug, ['ok' => true, 'state' => 'blocked']);
        }
        Queue::fake();
        $result = app(FleetSecurityResponse::class)->block('203.0.113.7', 'scanner', null, $this->user());

        app(OpsJobRunner::class)->run($result['job']);

        $this->assertSame(['test', 'beta', 'zeta'], array_column($this->siteCalls, 'host'));
        $this->assertTrue(collect($this->siteCalls)->every(fn (array $call) => $call['signed'] && $call['method'] === 'POST'));
        $this->assertSame('/internal/control/security/block', $this->siteCalls[0]['path']);
        $body = $this->siteCalls[0]['body'];
        $this->assertSame('203.0.113.7', $body['ip']);
        $this->assertSame($result['block']->uid, $body['uid']);
        $this->assertSame('plane', $body['source']);

        $block = $result['block']->fresh();
        $this->assertSame(FleetIpBlock::STATUS_APPLIED, $block->status);
        $this->assertSame('ok', $block->applied['beta']['state']);
    }

    public function test_when_the_test_site_refuses_no_other_site_is_touched(): void
    {
        $this->activeSite('test');
        $this->activeSite('beta');
        $this->fakeSite('test', fn (Request $request) => Http::response('nope', 500));
        $this->fakeSite('beta', ['ok' => true]);
        Queue::fake();
        $result = app(FleetSecurityResponse::class)->block('203.0.113.7', null, null, $this->user());

        try {
            app(OpsJobRunner::class)->run($result['job']);
            $this->fail('the job must fail when the canary refuses');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('test site', $exception->getMessage());
        }

        $this->assertSame(['test'], array_column($this->siteCalls, 'host'));
        $block = $result['block']->fresh();
        $this->assertSame(FleetIpBlock::STATUS_FAILED, $block->status);
        $this->assertArrayNotHasKey('beta', $block->applied);
    }

    public function test_a_missing_endpoint_on_the_test_site_also_stops_the_sweep(): void
    {
        $this->activeSite('test');
        $this->activeSite('beta');
        $this->fakeSite('test', fn (Request $request) => Http::response('Not Found', 404));
        $this->fakeSite('beta', ['ok' => true]);
        Queue::fake();
        $result = app(FleetSecurityResponse::class)->block('203.0.113.7', null, null, $this->user());

        $this->expectException(RuntimeException::class);
        try {
            app(OpsJobRunner::class)->run($result['job']);
        } finally {
            $this->assertSame(['test'], array_column($this->siteCalls, 'host'));
        }
    }

    public function test_a_site_that_failed_is_retried_alone_and_finished_sites_are_not_called_again(): void
    {
        $this->activeSite('test');
        $this->activeSite('beta');
        $this->activeSite('gamma');
        $this->fakeSite('test', ['ok' => true]);
        $beta = 0;
        $this->fakeSite('beta', function (Request $request) use (&$beta) {
            $beta++;

            return $beta === 1 ? Http::response('boom', 500) : Http::response(['ok' => true], 200);
        });
        $this->fakeSite('gamma', ['ok' => true]);
        Queue::fake();
        $result = app(FleetSecurityResponse::class)->block('203.0.113.7', null, null, $this->user());

        $first = app(FleetSecurityApplier::class)->apply($result['block']);
        $this->assertSame(FleetIpBlock::STATUS_PARTIAL, $first['status']);
        $this->assertSame(1, $first['failed']);

        $this->siteCalls = [];
        $second = app(FleetSecurityApplier::class)->apply($result['block']->fresh());

        $this->assertSame(['beta'], array_column($this->siteCalls, 'host'), 'only the site that missed it');
        $this->assertSame(FleetIpBlock::STATUS_APPLIED, $second['status']);
    }

    public function test_release_pushes_an_unblock_to_every_site_including_ones_that_never_took_the_block(): void
    {
        $this->activeSite('test');
        $this->activeSite('beta');
        $this->fakeSite('test', ['ok' => true]);
        $this->fakeSite('beta', ['ok' => true]);
        Queue::fake();
        $emin = $this->user();
        $blocked = app(FleetSecurityResponse::class)->block('203.0.113.7', null, null, $emin);
        app(OpsJobRunner::class)->run($blocked['job']);
        $this->siteCalls = [];

        $released = app(FleetSecurityResponse::class)->unblock('203.0.113.7', $emin);
        app(OpsJobRunner::class)->run($released['job']);

        $this->assertSame(['test', 'beta'], array_column($this->siteCalls, 'host'));
        $this->assertSame('/internal/control/security/unblock', $this->siteCalls[0]['path']);
        $this->assertNotNull($released['block']->fresh()->released_at);
        $this->assertSame(0, FleetIpBlock::query()->active()->count());
        $this->assertNotNull(AuditLog::query()->where('action', 'security.fleet_unblock')->first());

        $none = app(FleetSecurityResponse::class)->unblock('198.51.100.1', $emin);
        $this->assertNull($none['block']);
    }

    public function test_allowlist_add_remove_and_the_allowlist_wins_over_a_block(): void
    {
        $this->activeSite('test');
        $this->fakeSite('test', ['ok' => true]);
        Queue::fake();
        $emin = $this->user();

        $added = app(FleetSecurityResponse::class)->allow('198.51.100.0/24', 'office', $emin);
        app(OpsJobRunner::class)->run($added['job']);

        $this->assertSame('/internal/control/security/allowlist', $this->siteCalls[0]['path']);
        $this->assertSame('add', $this->siteCalls[0]['body']['action']);
        $this->assertSame('198.51.100.0/24', $this->siteCalls[0]['body']['ip']);

        try {
            app(FleetSecurityResponse::class)->block('198.51.100.20', null, null, $emin);
            $this->fail('an allowlisted address must not be blockable');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('allowlist', $exception->getMessage());
        }
        $this->assertSame(0, FleetIpBlock::query()->count());

        $removed = app(FleetSecurityResponse::class)->removeAllowed('198.51.100.0/24', $emin);
        app(OpsJobRunner::class)->run($removed['job']);
        $this->assertSame('remove', $this->siteCalls[1]['body']['action']);
        $this->assertSame(0, FleetAllowlistEntry::query()->active()->count());
    }

    public function test_dangerous_targets_are_refused(): void
    {
        Queue::fake();
        $emin = $this->user();

        foreach (['10.0.0.5', '127.0.0.1', '192.168.1.1', '0.0.0.0/0', '8.8.0.0/8', 'garbage', '::1'] as $bad) {
            try {
                app(FleetSecurityResponse::class)->block($bad, null, null, $emin);
                $this->fail("{$bad} must be refused");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(0, FleetIpBlock::query()->count());
        $ok = app(FleetSecurityResponse::class)->block('2A00:1450:4001::1', null, null, $emin);
        $this->assertSame('2a00:1450:4001::1', $ok['block']->ip, 'normalized form');
    }

    public function test_the_panel_blocks_through_the_background_job_and_roles_decide(): void
    {
        Queue::fake();
        $operator = $this->user(OpsRole::Operator);
        $viewer = $this->user(OpsRole::Viewer);

        $this->actingAs($viewer)->post(route('ops.security.block'), ['ip' => '203.0.113.7'])->assertForbidden();
        $this->assertSame(0, FleetIpBlock::query()->count());

        $this->actingAs($operator)->post(route('ops.security.block'), ['ip' => '203.0.113.7', 'reason' => 'scan', 'hours' => 24])
            ->assertRedirect()->assertSessionHas('status');
        $block = FleetIpBlock::query()->sole();
        $this->assertSame($operator->id, $block->created_by);
        $this->assertNotNull($block->expires_at);

        $this->actingAs($operator)->post(route('ops.security.block'), ['ip' => '10.0.0.1'])->assertSessionHas('error');
        $this->actingAs($operator)->post(route('ops.security.release'), ['ip' => '203.0.113.7'])->assertSessionHas('status');

        // Removing protection is a Super Admin act.
        $this->actingAs($operator)->post(route('ops.security.allow'), ['ip' => '198.51.100.9'])->assertSessionHas('status');
        $this->actingAs($operator)->post(route('ops.security.allow.remove'), ['ip' => '198.51.100.9'])->assertForbidden();
        $this->actingAs($this->user())->post(route('ops.security.allow.remove'), ['ip' => '198.51.100.9'])->assertSessionHas('status');
    }
}
