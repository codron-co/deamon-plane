<?php

namespace Tests\Feature\Comail;

use App\Models\AuditLog;
use App\Models\MailOrder;
use App\Models\MailServer;
use App\Models\MailSubscription;
use App\Services\Billing\PayLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Comail\Support\BillingTestHelpers;
use Tests\Feature\Comail\Support\FakeIyzico;
use Tests\TestCase;

class ComailBillingApiTest extends TestCase
{
    use BillingTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBilling();
    }

    public function test_billing_needs_the_site_signature(): void
    {
        $site = $this->comailSite();

        $this->signed('GET', '/billing', $site, [], 'wrong-secret')->assertUnauthorized();
        $this->getJson('/internal/site/v1/comail/billing')->assertUnauthorized();
    }

    public function test_billing_without_comail_server_is_not_configured(): void
    {
        MailServer::query()->delete();
        $site = $this->comailSite();

        $this->signed('GET', '/billing', $site)
            ->assertStatus(422)
            ->assertJsonPath('error', 'comail_not_configured');
    }

    public function test_billing_lists_current_package_active_packages_methods_and_bank(): void
    {
        $this->billingSettings();
        $this->starterPackage();
        $site = $this->comailSite();
        $this->comailDomain($site);

        $response = $this->signed('GET', '/billing', $site)->assertOk();

        $response->assertJsonPath('ok', true)
            ->assertJsonPath('current.package.slug', 'free')
            ->assertJsonPath('current.package.mailbox_limit', 1)
            ->assertJsonPath('current.period', null)
            ->assertJsonPath('current.status', null)
            ->assertJsonPath('current.period_end', null)
            ->assertJsonPath('current.auto_renew', false)
            ->assertJsonPath('current.over_limit', false)
            ->assertJsonPath('methods', ['iyzico', 'paytr', 'eft'])
            ->assertJsonPath('vat_included', true)
            ->assertJsonPath('bank.bank_name', 'Örnek Bank')
            ->assertJsonPath('bank.account_holder', 'CodRon Yazılım Ltd.')
            ->assertJsonPath('bank.iban', 'TR33 0006 1005 1978 6457 8413 26')
            ->assertJsonPath('bank.note', 'Açıklamaya sipariş numarasını yazın.')
            ->assertJsonPath('orders', []);

        // Active packages only (business stays inactive), by sort.
        $this->assertSame(['free', 'starter'], array_column($response->json('packages'), 'slug'));
        $starter = $response->json('packages.1');
        $this->assertEquals(99.9, $starter['price_monthly']);
        $this->assertEquals(999.0, $starter['price_yearly']);
        $this->assertSame('TRY', $starter['currency']);
        $this->assertFalse($starter['is_current']);
        $this->assertTrue($response->json('packages.0.is_current'));
        $this->assertNull($response->json('packages.0.price_monthly'));

        // Never a key.
        $this->assertStringNotContainsString(FakeIyzico::SECRET, $response->getContent());
    }

    public function test_methods_follow_the_settings_and_bank_is_null_without_eft(): void
    {
        $this->billingSettings(['eft_enabled' => false, 'paytr_enabled' => false]);
        $site = $this->comailSite();

        $this->signed('GET', '/billing', $site)
            ->assertOk()
            ->assertJsonPath('methods', ['iyzico'])
            ->assertJsonPath('bank', null);
    }

    public function test_card_order_returns_a_hosted_checkout_url(): void
    {
        $this->billingSettings();
        $this->starterPackage();
        $site = $this->comailSite();

        $response = $this->signed('POST', '/billing/orders', $site, [
            'package' => 'starter',
            'period' => 'yearly',
            'method' => 'iyzico',
            'billing' => $this->billingDetails(),
            'return_url' => 'https://acme.test/admin/yonetim/paket',
        ])->assertCreated();

        $order = MailOrder::query()->sole();
        $this->assertSame('pending', $order->status);
        $this->assertSame('new', $order->kind);
        $this->assertSame('999.00', (string) $order->amount);
        $this->assertSame('TRY', $order->currency);
        $this->assertMatchesRegularExpression('/\ACM-[A-Z2-9]{6}\z/', $order->reference);
        $this->assertSame('https://acme.test/admin/yonetim/paket', $order->return_url);
        $this->assertSame('Ayşe Yılmaz', $order->billing['name']);
        $this->assertEqualsWithDelta(now()->addMinutes(30)->getTimestamp(), $order->expires_at->getTimestamp(), 5);

        $response->assertJsonPath('order.id', $order->id)
            ->assertJsonPath('order.reference', $order->reference)
            ->assertJsonPath('order.package.slug', 'starter')
            ->assertJsonPath('order.period', 'yearly')
            ->assertJsonPath('order.method', 'iyzico')
            ->assertJsonPath('order.status', 'pending')
            ->assertJsonPath('order.currency', 'TRY')
            ->assertJsonPath('checkout_url', PayLink::url($order));
        $this->assertEquals(999.0, $response->json('order.amount'));
        $this->assertStringStartsWith(config('app.url').'/pay/'.$order->id.'/', $response->json('checkout_url'));

        $this->assertTrue(AuditLog::query()->where('action', 'comail.order_created')->where('subject_id', $site->id)->exists());
        // Nothing is charged or initialized before the customer opens the page.
        $this->assertSame([], $this->iyzico->calls);
    }

    public function test_eft_order_returns_bank_details_and_reference(): void
    {
        $this->billingSettings();
        $this->starterPackage();
        $site = $this->comailSite();

        $response = $this->signed('POST', '/billing/orders', $site, [
            'package' => 'starter',
            'period' => 'monthly',
            'method' => 'eft',
            'billing' => $this->billingDetails(),
        ])->assertCreated();

        $order = MailOrder::query()->sole();
        $this->assertSame('awaiting_transfer', $order->status);
        $this->assertEqualsWithDelta(now()->addDays(7)->getTimestamp(), $order->expires_at->getTimestamp(), 5);
        $this->assertNull($order->return_url);

        $response->assertJsonPath('order.status', 'awaiting_transfer')
            ->assertJsonPath('reference', $order->reference)
            ->assertJsonPath('bank.iban', 'TR33 0006 1005 1978 6457 8413 26')
            ->assertJsonPath('currency', 'TRY')
            ->assertJsonMissingPath('checkout_url');
        $this->assertEquals(99.9, $response->json('amount'));
    }

    public function test_order_errors_price_missing_method_disabled_and_validation(): void
    {
        $this->billingSettings(['paytr_enabled' => false]);
        $this->starterPackage(yearly: null);
        $site = $this->comailSite();
        $body = fn (array $overrides = []): array => $overrides + [
            'package' => 'starter',
            'period' => 'monthly',
            'method' => 'iyzico',
            'billing' => $this->billingDetails(),
        ];

        $this->signed('POST', '/billing/orders', $site, $body(['period' => 'yearly']))
            ->assertStatus(422)->assertJsonPath('error', 'price_missing');
        $this->signed('POST', '/billing/orders', $site, $body(['package' => 'free']))
            ->assertStatus(422)->assertJsonPath('error', 'price_missing');
        $this->signed('POST', '/billing/orders', $site, $body(['method' => 'paytr']))
            ->assertStatus(422)->assertJsonPath('error', 'method_disabled');
        $this->signed('POST', '/billing/orders', $site, $body(['method' => 'bitcoin']))
            ->assertStatus(422)->assertJsonPath('error', 'validation_failed');
        $this->signed('POST', '/billing/orders', $site, $body(['package' => 'business']))
            ->assertStatus(422)->assertJsonPath('error', 'validation_failed');

        $billing = $this->billingDetails();
        $billing['tckn_or_vkn'] = '123';
        $this->signed('POST', '/billing/orders', $site, $body(['billing' => $billing]))
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonPath('message', 'TCKN 11, VKN 10 haneli olmalı.');

        unset($billing['tckn_or_vkn'], $billing['email']);
        $this->signed('POST', '/billing/orders', $site, $body(['billing' => $billing]))
            ->assertStatus(422)->assertJsonPath('error', 'validation_failed');

        $this->assertSame(0, MailOrder::query()->count());
    }

    public function test_return_url_must_be_https_on_the_sites_own_host(): void
    {
        $this->billingSettings();
        $this->starterPackage();
        $site = $this->comailSite();

        $urls = [
            'https://www.acme.test/admin?x=1' => 'https://www.acme.test/admin?x=1',
            'http://acme.test/admin' => null,
            'https://evil.test/admin' => null,
            'https://acme.test.evil.test/admin' => null,
            'https://user:pass@acme.test/admin' => null,
            'javascript:alert(1)' => null,
        ];
        foreach ($urls as $url => $expected) {
            $order = $this->placeOrder($site, 'iyzico', ['return_url' => $url]);
            $this->assertSame($expected, $order->return_url, $url);
        }
    }

    public function test_renewal_and_change_kinds_follow_the_live_subscription(): void
    {
        $this->billingSettings();
        $starter = $this->starterPackage();
        $this->businessPackage();
        $site = $this->comailSite();
        MailSubscription::query()->create([
            'site_id' => $site->id,
            'mail_package_id' => $starter->id,
            'period' => 'monthly',
            'status' => 'active',
            'current_period_start' => now()->subDays(20),
            'current_period_end' => now()->addDays(10),
        ]);

        $this->assertSame('renewal', $this->placeOrder($site, 'eft')->kind);
        $this->assertSame('change', $this->placeOrder($site, 'eft', ['package' => 'business'])->kind);
    }

    public function test_transfer_reported_and_cancel(): void
    {
        $this->billingSettings();
        $this->starterPackage();
        $site = $this->comailSite();
        $order = $this->placeOrder($site, 'eft');

        $this->signed('POST', '/billing/orders/'.$order->id.'/transfer-reported', $site, ['note' => 'Ziraat şubesinden gönderildi'])
            ->assertOk()
            ->assertJsonPath('order.status', 'transfer_reported')
            ->assertJsonPath('status', 'transfer_reported');
        $order->refresh();
        $this->assertNotNull($order->transfer_reported_at);
        $this->assertStringContainsString('Ziraat şubesinden gönderildi', (string) $order->notes);

        // Reported again: still fine; cancel is no longer allowed for the customer.
        $this->signed('POST', '/billing/orders/'.$order->id.'/transfer-reported', $site)->assertOk();
        $this->signed('POST', '/billing/orders/'.$order->id.'/cancel', $site)
            ->assertStatus(422)->assertJsonPath('error', 'order_not_cancellable');

        $other = $this->placeOrder($site, 'eft');
        $this->signed('POST', '/billing/orders/'.$other->id.'/cancel', $site)
            ->assertOk()->assertJsonPath('order.status', 'canceled');
        $this->signed('POST', '/billing/orders/'.$other->id.'/cancel', $site)
            ->assertStatus(422)->assertJsonPath('error', 'order_not_cancellable');
        $this->signed('POST', '/billing/orders/'.$other->id.'/transfer-reported', $site)
            ->assertStatus(422)->assertJsonPath('error', 'validation_failed');

        $card = $this->placeOrder($site, 'paytr');
        $this->signed('POST', '/billing/orders/'.$card->id.'/transfer-reported', $site)
            ->assertStatus(422)->assertJsonPath('error', 'validation_failed');
        $this->signed('POST', '/billing/orders/'.$card->id.'/cancel', $site)->assertOk()->assertJsonPath('order.status', 'canceled');

        $this->assertTrue(AuditLog::query()->where('action', 'comail.transfer_reported')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'comail.order_canceled')->exists());
    }

    public function test_a_site_cannot_touch_another_sites_order(): void
    {
        $this->billingSettings();
        $this->starterPackage();
        $site = $this->comailSite();
        $other = $this->comailSite('other.test');
        $order = $this->placeOrder($site, 'eft');

        $this->signed('POST', '/billing/orders/'.$order->id.'/cancel', $other)
            ->assertNotFound()->assertJsonPath('error', 'not_found');
        $this->signed('POST', '/billing/orders/'.$order->id.'/transfer-reported', $other)
            ->assertNotFound();
        $this->signed('GET', '/billing', $other)->assertOk()->assertJsonPath('orders', []);
        $this->assertSame('awaiting_transfer', $order->fresh()->status);
    }

    public function test_billing_lists_the_sites_orders_newest_first(): void
    {
        $this->billingSettings();
        $this->starterPackage();
        $site = $this->comailSite();
        $first = $this->placeOrder($site, 'eft');
        $this->travel(1)->minutes();
        $second = $this->placeOrder($site, 'iyzico');

        $orders = $this->signed('GET', '/billing', $site)->assertOk()->json('orders');

        $this->assertSame([$second->id, $first->id], array_column($orders, 'id'));
        $this->assertSame(['id', 'reference', 'package', 'package_slug', 'package_name', 'period', 'kind', 'amount', 'currency', 'method', 'status', 'created_at', 'paid_at', 'expires_at'], array_keys($orders[0]));
        $this->assertSame(['slug' => 'starter', 'name' => 'Başlangıç'], $orders[0]['package']);
    }

    public function test_over_limit_shows_in_billing_and_overview_after_a_downgrade(): void
    {
        $this->billingSettings();
        $site = $this->comailSite();
        $this->comailDomain($site);
        // Free package: 1 mailbox, 1024 MB. The site still holds two boxes of 1024 MB.
        $this->mailcow->addMailbox('info@acme.test', 1024);
        $this->mailcow->addMailbox('satis@acme.test', 1024);

        $this->signed('GET', '/billing', $site)->assertOk()
            ->assertJsonPath('current.over_limit', true)
            ->assertJsonPath('current.usage.mailboxes', 2);
        $this->signed('GET', '/overview', $site)->assertOk()
            ->assertJsonPath('over_limit', true)
            ->assertJsonPath('subscription', null);

        // New objects are refused with the v1 limit errors.
        $this->signed('POST', '/mailboxes', $site, [
            'local_part' => 'yeni',
            'domain' => 'acme.test',
            'password' => 'Gizli-Parola-42x',
        ])->assertStatus(422)->assertJsonPath('error', 'limit_mailboxes');

        // Shrinking a box is allowed so the site can get back under its package.
        $this->signed('PATCH', '/mailboxes/satis@acme.test', $site, ['quota_mb' => 512])
            ->assertOk()->assertJsonPath('quota_mb', 512);
    }

    public function test_overview_carries_the_subscription_state(): void
    {
        $starter = $this->starterPackage();
        $site = $this->comailSite(attributes: ['mail_package_id' => $starter->id]);
        $this->comailDomain($site);
        MailSubscription::query()->create([
            'site_id' => $site->id,
            'mail_package_id' => $starter->id,
            'period' => 'yearly',
            'status' => 'active',
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->addMonths(11),
        ]);

        $this->signed('GET', '/overview', $site)->assertOk()
            ->assertJsonPath('package.slug', 'starter')
            ->assertJsonPath('over_limit', false)
            ->assertJsonPath('subscription.status', 'active')
            ->assertJsonPath('subscription.period', 'yearly');
        $this->signed('GET', '/billing', $site)->assertOk()
            ->assertJsonPath('current.status', 'active')
            ->assertJsonPath('current.period', 'yearly')
            ->assertJsonPath('packages.1.is_current', true);
    }

    public function test_package_with_yearly_price_only_sells_yearly(): void
    {
        $this->billingSettings();
        $this->starterPackage(monthly: null, yearly: '900.00');
        $site = $this->comailSite();

        $this->signed('POST', '/billing/orders', $site, [
            'package' => 'starter', 'period' => 'monthly', 'method' => 'eft', 'billing' => $this->billingDetails(),
        ])->assertStatus(422)->assertJsonPath('error', 'price_missing');
        $this->assertSame('900.00', (string) $this->placeOrder($site, 'eft', ['period' => 'yearly'])->amount);
    }
}
