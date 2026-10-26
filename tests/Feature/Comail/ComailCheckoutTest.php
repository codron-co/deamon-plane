<?php

namespace Tests\Feature\Comail;

use App\Models\AuditLog;
use App\Models\BillingSetting;
use App\Models\MailOrder;
use App\Models\MailPackage;
use App\Models\MailSubscription;
use App\Models\Site;
use App\Services\Billing\PayLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Comail\Support\BillingTestHelpers;
use Tests\Feature\Comail\Support\FakeIyzico;
use Tests\Feature\Comail\Support\FakePaytr;
use Tests\TestCase;

class ComailCheckoutTest extends TestCase
{
    use BillingTestHelpers;
    use RefreshDatabase;

    private Site $site;

    private MailPackage $starter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBilling();
        $this->billingSettings();
        $this->starter = $this->starterPackage();
        $this->site = $this->comailSite();
        $this->comailDomain($this->site);
    }

    // ── Checkout page ───────────────────────────────────────────────────

    public function test_checkout_link_is_bound_to_the_order_and_its_expiry(): void
    {
        $order = $this->placeOrder($this->site, 'iyzico');
        $other = $this->placeOrder($this->site, 'iyzico');

        $this->get('/pay/'.$order->id.'/'.PayLink::token($other))
            ->assertNotFound()
            ->assertSee('Bağlantı geçersiz');
        $this->get('/pay/'.$order->id.'/'.str_repeat('a', 48))->assertNotFound();
        $this->get('/pay/not-an-id/'.PayLink::token($order))->assertNotFound();

        $order->forceFill(['expires_at' => $order->expires_at->addMinute()])->save();
        $this->get(PayLink::url($other))->assertOk();
        $this->assertNotSame(PayLink::token($order), PayLink::token($order->fresh()->forceFill(['expires_at' => now()])));

        $this->travel(31)->minutes();
        $this->get(PayLink::url($other))->assertOk()->assertSee('Sipariş süresi doldu');
        $this->assertSame(1, count(array_filter($this->iyzico->paths(), fn (string $path): bool => str_contains($path, 'initialize'))));
    }

    public function test_iyzico_checkout_page_embeds_the_checkout_form_without_session_or_ip_allowlist(): void
    {
        config(['ops.access.ip_allowlist' => '10.0.0.1']);
        $order = $this->placeOrder($this->site, 'iyzico');

        $response = $this->get(PayLink::url($order))->assertOk();

        $response->assertSee('Başlangıç')
            ->assertSee($order->reference)
            ->assertSee('99,90 TL')
            ->assertSee('KDV dahil')
            ->assertSee('https://sandbox-cpp.iyzipay.com?token=cf-token-1&amp;lang=tr', false)
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'")
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringNotContainsString(FakeIyzico::SECRET, $response->getContent());
        $this->assertStringNotContainsString(FakeIyzico::API_KEY, $response->getContent());

        $call = $this->iyzico->calls[0];
        $this->assertSame('sandbox-api.iyzipay.com', $call['host']);
        $this->assertTrue($call['signature_ok']);
        $this->assertSame($order->reference, $call['body']['basketId']);
        $this->assertSame('99.90', $call['body']['price']);
        $this->assertSame('99.90', $call['body']['paidPrice']);
        $this->assertSame('TRY', $call['body']['currency']);
        $this->assertSame(route('pay.callback.iyzico'), $call['body']['callbackUrl']);
        $this->assertSame('12345678901', $call['body']['buyer']['identityNumber']);
        $this->assertSame('+905320000000', $call['body']['buyer']['gsmNumber']);
        $this->assertSame('VIRTUAL', $call['body']['basketItems'][0]['itemType']);
        $this->assertSame('CoMail Başlangıç (Aylık)', $call['body']['basketItems'][0]['name']);

        $order->refresh();
        $this->assertSame('cf-token-1', $order->provider_token);

        // A reload reuses the open checkout instead of starting a new one.
        $this->get(PayLink::url($order))->assertOk();
        $this->assertCount(1, $this->iyzico->calls);
    }

    public function test_live_mode_uses_the_live_iyzico_api(): void
    {
        $this->billingSettingsUpdate(['iyzico_sandbox' => false]);
        $order = $this->placeOrder($this->site, 'iyzico');

        $this->get(PayLink::url($order))->assertOk();

        $this->assertSame('api.iyzipay.com', $this->iyzico->calls[0]['host']);
    }

    public function test_provider_error_on_initialize_shows_a_calm_error(): void
    {
        $this->iyzico->failInitialize = true;
        $order = $this->placeOrder($this->site, 'iyzico');

        $this->get(PayLink::url($order))->assertStatus(502)->assertSee('Ödeme sayfası açılamadı');
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_disabled_method_after_ordering_is_unavailable(): void
    {
        $order = $this->placeOrder($this->site, 'paytr');
        $this->billingSettingsUpdate(['paytr_enabled' => false]);

        $this->get(PayLink::url($order))->assertStatus(503)->assertSee('Ödeme yöntemi kullanılamıyor');
        $this->assertSame([], $this->paytr->requests);
    }

    public function test_eft_order_page_shows_bank_details(): void
    {
        $order = $this->placeOrder($this->site, 'eft');

        $this->get(PayLink::url($order))->assertOk()
            ->assertSee('TR33 0006 1005 1978 6457 8413 26')
            ->assertSee('data-copy="TR330006100519786457841326"', false)
            ->assertSee($order->reference)
            ->assertDontSee('<iframe', false);
    }

    // ── iyzico callback ─────────────────────────────────────────────────

    public function test_iyzico_success_activates_the_package_and_returns_to_the_site(): void
    {
        $order = $this->openIyzico();

        $response = $this->post('/pay/callback/iyzico', ['token' => 'cf-token-1'])->assertOk();

        $order->refresh();
        $this->assertSame('paid', $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame('2345678', $order->provider_payment_id);
        // Sanitised: no card data kept.
        $payload = json_encode($order->provider_payload);
        $this->assertStringNotContainsString('552879', $payload);
        $this->assertStringNotContainsString('0008', $payload);
        $this->assertStringNotContainsString('Test Kart', $payload);

        $subscription = MailSubscription::query()->where('site_id', $this->site->id)->sole();
        $this->assertSame('active', $subscription->status);
        $this->assertSame('monthly', $subscription->period);
        $this->assertSame((string) $this->starter->id, (string) $subscription->mail_package_id);
        $this->assertEqualsWithDelta(now()->addMonthNoOverflow()->getTimestamp(), $subscription->current_period_end->getTimestamp(), 5);
        $this->assertSame((string) $this->starter->id, (string) $this->site->fresh()->mail_package_id);

        // ApplyComailPackageJob ran (sync queue): mailcow got the starter limits.
        $this->assertSame(5, $this->mailcow->domains['acme.test']['mailboxes']);
        $this->assertSame(10240, $this->mailcow->domains['acme.test']['quota']);

        $target = 'https://acme.test/admin/yonetim/paket?comail_order='.$order->id.'&status=paid';
        $response->assertSee('href="'.e($target).'"', false)
            ->assertSee('window.top.location.replace', false);
        $this->assertTrue(AuditLog::query()->where('action', 'comail.order_paid')->where('subject_id', $this->site->id)->exists());

        $retrieve = collect($this->iyzico->calls)->firstWhere('path', '/payment/iyzipos/checkoutform/auth/ecom/detail');
        $this->assertTrue($retrieve['signature_ok']);
    }

    public function test_duplicate_iyzico_callback_is_idempotent(): void
    {
        $order = $this->openIyzico();

        $this->post('/pay/callback/iyzico', ['token' => 'cf-token-1'])->assertOk();
        $end = MailSubscription::query()->sole()->current_period_end;
        $this->post('/pay/callback/iyzico', ['token' => 'cf-token-1'])->assertOk()->assertSee('status=paid', false);

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertEquals($end, MailSubscription::query()->sole()->current_period_end);
        $this->assertSame(1, AuditLog::query()->where('action', 'comail.order_paid')->count());
    }

    public function test_iyzico_failure_marks_the_order_failed(): void
    {
        $order = $this->openIyzico();
        $this->iyzico->outcomes['cf-token-1'] = 'FAILURE';

        $this->post('/pay/callback/iyzico', ['token' => 'cf-token-1'])->assertOk()->assertSee('status=failed', false);

        $order->refresh();
        $this->assertSame('failed', $order->status);
        $this->assertStringContainsString('Kart reddedildi', (string) $order->notes);
        $this->assertSame(0, MailSubscription::query()->count());
        $this->assertNull($this->site->fresh()->mail_package_id);
    }

    public function test_iyzico_forged_signature_never_pays(): void
    {
        $order = $this->openIyzico();
        $this->iyzico->signWith = 'attacker-secret';

        $this->post('/pay/callback/iyzico', ['token' => 'cf-token-1'])->assertOk()->assertSee('status=failed', false);

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, MailSubscription::query()->count());
    }

    public function test_iyzico_amount_mismatch_never_pays(): void
    {
        $order = $this->openIyzico();
        $this->iyzico->paidPrices['cf-token-1'] = '1.00';

        $this->post('/pay/callback/iyzico', ['token' => 'cf-token-1'])->assertOk();

        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame(0, MailSubscription::query()->count());
    }

    public function test_iyzico_unknown_token_is_refused(): void
    {
        $this->post('/pay/callback/iyzico', ['token' => 'nope'])->assertNotFound();
        $this->post('/pay/callback/iyzico', [])->assertStatus(400);
        $this->assertSame(0, MailOrder::query()->count());
    }

    public function test_iyzico_without_return_url_hands_over_to_the_plane_result_page(): void
    {
        $order = $this->placeOrder($this->site, 'iyzico', ['return_url' => 'https://evil.test/x']);
        $this->get(PayLink::url($order))->assertOk();

        $this->post('/pay/callback/iyzico', ['token' => 'cf-token-1'])->assertOk()
            ->assertSee(str_replace('/', '\/', route('pay.result', ['order' => $order->id, 'token' => PayLink::token($order)])), false);

        $this->get(route('pay.result', ['order' => $order->id, 'token' => PayLink::token($order)]))
            ->assertOk()->assertSee('Ödemeniz alındı');
    }

    public function test_late_card_payment_after_expiry_still_activates(): void
    {
        $order = $this->openIyzico();
        $order->forceFill(['status' => 'expired'])->save();

        $this->post('/pay/callback/iyzico', ['token' => 'cf-token-1'])->assertOk();

        $this->assertSame('paid', $order->fresh()->status);
    }

    // ── PayTR ───────────────────────────────────────────────────────────

    public function test_paytr_checkout_page_gets_a_token_and_embeds_the_iframe(): void
    {
        $order = $this->placeOrder($this->site, 'paytr');

        $this->get(PayLink::url($order), ['REMOTE_ADDR' => '203.0.113.7'])->assertOk()
            ->assertSee('https://www.paytr.com/odeme/guvenli/paytr-iframe-token-1', false)
            ->assertSee('iframeResizer.min.js', false);

        $request = $this->paytr->requests[0];
        $this->assertSame(FakePaytr::MERCHANT_ID, $request['merchant_id']);
        $this->assertSame(str_replace('-', '', $order->reference), $request['merchant_oid']);
        $this->assertSame('9990', $request['payment_amount']);
        $this->assertSame('TL', $request['currency']);
        $this->assertSame('1', $request['test_mode']);
        $this->assertSame('ayse@acme.test', $request['email']);
        $this->assertSame(PayLink::doneUrl($order, 'ok'), $request['merchant_ok_url']);
        $this->assertSame(PayLink::doneUrl($order, 'fail'), $request['merchant_fail_url']);
        $this->assertArrayNotHasKey('merchant_key', $request);
        $this->assertArrayNotHasKey('merchant_salt', $request);
        $this->assertSame('paytr-iframe-token-1', $order->fresh()->provider_token);
    }

    public function test_paytr_notification_success_pays_and_answers_ok(): void
    {
        $order = $this->placeOrder($this->site, 'paytr');
        $oid = str_replace('-', '', $order->reference);

        $this->post('/pay/callback/paytr', FakePaytr::notification($oid, 'success', 9990, 9990))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSeeText('OK');

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame((string) $this->starter->id, (string) $this->site->fresh()->mail_package_id);

        // PayTR repeats notifications until it reads OK: still OK, nothing changes.
        $this->post('/pay/callback/paytr', FakePaytr::notification($oid, 'success', 9990, 9990))->assertOk()->assertSeeText('OK');
        $this->assertSame(1, AuditLog::query()->where('action', 'comail.order_paid')->count());
    }

    public function test_paytr_failed_notification_marks_failed(): void
    {
        $order = $this->placeOrder($this->site, 'paytr');

        $this->post('/pay/callback/paytr', FakePaytr::notification(str_replace('-', '', $order->reference), 'failed', 9990))
            ->assertOk()->assertSeeText('OK');

        $order->refresh();
        $this->assertSame('failed', $order->status);
        $this->assertStringContainsString('Kart limiti yetersiz', (string) $order->notes);
    }

    public function test_paytr_bad_hash_is_rejected(): void
    {
        $order = $this->placeOrder($this->site, 'paytr');

        $this->post('/pay/callback/paytr', FakePaytr::notification(str_replace('-', '', $order->reference), 'success', 9990, 9990, 'forged-key'))
            ->assertStatus(400)
            ->assertSeeText('bad hash');

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_paytr_amount_mismatch_never_pays(): void
    {
        $order = $this->placeOrder($this->site, 'paytr');

        $this->post('/pay/callback/paytr', FakePaytr::notification(str_replace('-', '', $order->reference), 'success', 100, 100))
            ->assertOk()->assertSeeText('OK');

        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame(0, MailSubscription::query()->count());
    }

    public function test_paytr_unknown_order_is_acknowledged(): void
    {
        $this->post('/pay/callback/paytr', FakePaytr::notification('CMZZZZZZ', 'success', 100))->assertOk()->assertSeeText('OK');
        $this->assertSame(0, MailSubscription::query()->count());
    }

    public function test_paytr_ok_page_waits_for_the_notification_then_hands_over(): void
    {
        $order = $this->placeOrder($this->site, 'paytr');
        $done = PayLink::doneUrl($order, 'ok');

        $this->get($done)->assertOk()->assertSee('Ödemeniz onaylanıyor')->assertSee('attempt=1', false)
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self' https://www.paytr.com https://*.iyzipay.com");

        $this->post('/pay/callback/paytr', FakePaytr::notification(str_replace('-', '', $order->reference), 'success', 9990, 9990))->assertOk();
        $this->get($done.'?attempt=1')->assertOk()->assertSee('status=paid', false);

        $late = $this->placeOrder($this->site, 'paytr');
        $this->get(PayLink::doneUrl($late, 'ok').'?attempt=5')->assertOk()->assertSee('status=pending', false);
        $this->get(PayLink::doneUrl($late, 'fail'))->assertOk()->assertSee('status=failed', false);
        $this->get(route('pay.done', ['order' => $late->id, 'token' => str_repeat('0', 48), 'result' => 'ok']))->assertNotFound();
    }

    // ── Renewal / change periods ────────────────────────────────────────

    public function test_renewal_extends_from_the_current_period_end_and_change_starts_now(): void
    {
        $end = now()->addDays(10)->startOfSecond();
        MailSubscription::query()->create([
            'site_id' => $this->site->id,
            'mail_package_id' => $this->starter->id,
            'period' => 'monthly',
            'status' => 'active',
            'current_period_start' => now()->subDays(20),
            'current_period_end' => $end,
        ]);

        $renewal = $this->placeOrder($this->site, 'paytr', ['period' => 'yearly']);
        $this->post('/pay/callback/paytr', FakePaytr::notification(str_replace('-', '', $renewal->reference), 'success', 99900, 99900))->assertOk();
        $subscription = MailSubscription::query()->sole();
        $this->assertSame('yearly', $subscription->period);
        $this->assertEquals($end->copy()->addYearNoOverflow()->getTimestamp(), $subscription->current_period_end->getTimestamp());

        $this->businessPackage();
        $change = $this->placeOrder($this->site, 'paytr', ['package' => 'business']);
        $this->assertSame('change', $change->kind);
        $this->post('/pay/callback/paytr', FakePaytr::notification(str_replace('-', '', $change->reference), 'success', 24900, 24900))->assertOk();
        $subscription->refresh();
        $this->assertSame('business', $subscription->mailPackage->slug);
        $this->assertEqualsWithDelta(now()->addMonthNoOverflow()->getTimestamp(), $subscription->current_period_end->getTimestamp(), 5);
        $this->assertSame('business', MailPackage::forSite($this->site->fresh())->slug);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function openIyzico(): MailOrder
    {
        $order = $this->placeOrder($this->site, 'iyzico');
        $this->get(PayLink::url($order))->assertOk();

        return $order->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function billingSettingsUpdate(array $attributes): void
    {
        $settings = BillingSetting::current();
        $settings->forceFill($attributes)->save();
    }
}
