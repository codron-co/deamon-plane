<?php

namespace Tests\Feature\Comail;

use App\Jobs\ExpireMailSubscriptionsJob;
use App\Models\AuditLog;
use App\Models\MailOrder;
use App\Models\MailPackage;
use App\Models\MailSubscription;
use App\Models\Site;
use App\Services\Billing\MailBilling;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Comail\Support\BillingTestHelpers;
use Tests\TestCase;

class ComailBillingExpiryTest extends TestCase
{
    use BillingTestHelpers;
    use RefreshDatabase;

    private MailPackage $starter;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBilling();
        $this->billingSettings(['grace_days' => 7]);
        $this->starter = $this->starterPackage();
        $this->site = $this->comailSite(attributes: ['mail_package_id' => $this->starter->id]);
        $this->comailDomain($this->site);
    }

    public function test_the_job_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => $event->description === 'ops-comail-billing-expiry');

        $this->assertCount(1, $events);
        $this->assertSame('55 3 * * *', $events->first()->expression);
    }

    public function test_active_goes_to_grace_then_expires_to_the_default_package(): void
    {
        $subscription = $this->subscription(now()->addDay());

        $this->runJob();
        $this->assertSame('active', $subscription->fresh()->status);

        $this->travel(2)->days();
        $this->runJob();
        $this->assertSame('grace', $subscription->fresh()->status);
        $this->assertSame((string) $this->starter->id, (string) $this->site->fresh()->mail_package_id);
        $this->signed('GET', '/billing', $this->site)->assertOk()
            ->assertJsonPath('current.status', 'grace')
            ->assertJsonPath('current.package.slug', 'starter');

        $this->travel(5)->days();
        $this->runJob();
        $this->assertSame('grace', $subscription->fresh()->status);

        $this->travel(2)->days();
        $this->runJob();
        $this->assertSame('expired', $subscription->fresh()->status);
        $this->assertNull($this->site->fresh()->mail_package_id);
        $this->assertSame('free', MailPackage::forSite($this->site->fresh())->slug);
        // Limits re-applied to the default package.
        $this->assertSame(1, $this->mailcow->domains['acme.test']['mailboxes']);
        $this->assertSame(1024, $this->mailcow->domains['acme.test']['quota']);

        $this->assertTrue(AuditLog::query()->where('action', 'comail.subscription_grace')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'comail.subscription_expired')->exists());
        $this->signed('GET', '/billing', $this->site)->assertOk()
            ->assertJsonPath('current.status', 'expired')
            ->assertJsonPath('current.package.slug', 'free');
    }

    public function test_expiry_never_fails_and_deletes_nothing_when_usage_exceeds_the_new_package(): void
    {
        // Starter usage: 3 boxes (2048 + 2048 + 4096 MB) and 12 aliases; free = 1 box, 1024 MB, 10 aliases.
        $this->mailcow->addMailbox('a@acme.test', 2048);
        $this->mailcow->addMailbox('b@acme.test', 2048);
        $this->mailcow->addMailbox('c@acme.test', 4096);
        for ($i = 1; $i <= 12; $i++) {
            $this->mailcow->aliases[1000 + $i] = ['id' => 1000 + $i, 'address' => "alias{$i}@acme.test", 'goto' => 'a@acme.test', 'domain' => 'acme.test', 'active' => 1];
        }
        $this->subscription(now()->subDays(30));

        $this->runJob();

        $domain = $this->mailcow->domains['acme.test'];
        $this->assertSame(3, $domain['mailboxes']);
        $this->assertSame(8192, $domain['quota']);
        $this->assertSame(4096, $domain['maxquota']);
        $this->assertSame(12, $domain['aliases']);
        $this->assertSame(1024, $domain['defquota']);
        $this->assertCount(3, $this->mailcow->mailboxes);
        $this->assertSame([], array_filter($this->mailcow->paths(), fn (string $path): bool => str_starts_with($path, '/delete/')));
        $this->assertNull($this->site->fresh()->comailDomains()->first()->last_error);

        $this->signed('GET', '/overview', $this->site)->assertOk()->assertJsonPath('over_limit', true);
        $this->signed('POST', '/aliases', $this->site, ['address' => 'yeni@acme.test', 'goto' => ['a@acme.test']])
            ->assertStatus(422)->assertJsonPath('error', 'limit_aliases');
    }

    public function test_ops_set_package_is_not_reset_by_an_old_subscription(): void
    {
        $business = $this->businessPackage();
        $this->subscription(now()->subDays(30));
        $this->site->forceFill(['mail_package_id' => $business->id])->save();

        $this->runJob();

        $this->assertSame((string) $business->id, (string) $this->site->fresh()->mail_package_id);
    }

    public function test_paid_renewal_during_grace_reactivates(): void
    {
        $end = now()->subDays(2)->startOfSecond();
        $subscription = $this->subscription($end, 'grace');

        $order = $this->placeOrder($this->site, 'eft');
        app(MailBilling::class)->markPaid($order, [], 'ops');

        $subscription->refresh();
        $this->assertSame('active', $subscription->status);
        $this->assertSame($end->copy()->addMonthNoOverflow()->getTimestamp(), $subscription->current_period_end->getTimestamp());
    }

    public function test_open_orders_expire_card_after_30_minutes_and_eft_after_7_days(): void
    {
        $card = $this->placeOrder($this->site, 'iyzico');
        $eft = $this->placeOrder($this->site, 'eft');
        $reported = $this->placeOrder($this->site, 'eft');
        $this->signed('POST', '/billing/orders/'.$reported->id.'/transfer-reported', $this->site)->assertOk();

        $this->travel(31)->minutes();
        $this->runJob();
        $this->assertSame('expired', $card->fresh()->status);
        $this->assertSame('awaiting_transfer', $eft->fresh()->status);

        $this->travel(7)->days();
        $this->runJob();
        $this->assertSame('expired', $eft->fresh()->status);
        // A reported transfer waits for ops.
        $this->assertSame('transfer_reported', $reported->fresh()->status);
        $this->assertSame(2, AuditLog::query()->where('action', 'comail.order_expired')->count());
        $this->assertSame(2, MailOrder::query()->where('status', 'expired')->count());
    }

    private function runJob(): void
    {
        dispatch_sync(new ExpireMailSubscriptionsJob);
    }

    private function subscription(Carbon $end, string $status = 'active'): MailSubscription
    {
        return MailSubscription::query()->create([
            'site_id' => $this->site->id,
            'mail_package_id' => $this->starter->id,
            'period' => 'monthly',
            'status' => $status,
            'current_period_start' => $end->copy()->subMonth(),
            'current_period_end' => $end,
        ]);
    }
}
