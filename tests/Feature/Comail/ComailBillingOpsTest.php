<?php

namespace Tests\Feature\Comail;

use App\Enums\OpsRole;
use App\Models\AuditLog;
use App\Models\BillingSetting;
use App\Models\MailPackage;
use App\Models\MailSubscription;
use App\Models\McpToken;
use App\Models\Site;
use App\Services\Billing\MailBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Comail\Support\BillingTestHelpers;
use Tests\Feature\Comail\Support\FakeIyzico;
use Tests\Feature\Comail\Support\FakePaytr;
use Tests\TestCase;

class ComailBillingOpsTest extends TestCase
{
    use BillingTestHelpers;
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBilling();
        $this->billingSettings();
        $this->starterPackage();
        $this->site = $this->comailSite();
        $this->comailDomain($this->site);
    }

    // ── Orders ──────────────────────────────────────────────────────────

    public function test_orders_list_filters_and_opens_the_detail_page(): void
    {
        $eft = $this->placeOrder($this->site, 'eft');
        $card = $this->placeOrder($this->site, 'iyzico');

        $this->actingAs($this->opsUser(OpsRole::Viewer))->get(route('ops.billing.orders.index'))
            ->assertOk()
            ->assertSee($eft->reference)
            ->assertSee($card->reference)
            ->assertSee('data-href="'.route('ops.billing.orders.show', $eft).'"', false)
            ->assertSee('99,90 TL');

        $this->actingAs($this->opsUser(OpsRole::Viewer))->get(route('ops.billing.orders.index', ['method' => 'eft']))
            ->assertOk()->assertSee($eft->reference)->assertDontSee($card->reference);
        $this->actingAs($this->opsUser(OpsRole::Viewer))->get(route('ops.billing.orders.index', ['status' => 'paid']))
            ->assertOk()->assertSee('data-billing-orders-empty', false);
        $this->actingAs($this->opsUser(OpsRole::Viewer))->get(route('ops.billing.orders.index', ['site' => $this->site->id]))
            ->assertOk()->assertSee($eft->reference);

        $this->actingAs($this->opsUser(OpsRole::Viewer))->get(route('ops.billing.orders.show', $eft))
            ->assertOk()
            ->assertSee($eft->reference)
            ->assertSee('Ayşe Yılmaz')
            ->assertSee('12345678901')
            ->assertDontSee('data-billing-confirm', false);
    }

    public function test_super_admin_confirms_an_eft_order(): void
    {
        $order = $this->placeOrder($this->site, 'eft');
        $admin = $this->opsUser();

        $this->actingAs($admin)->get(route('ops.billing.orders.show', $order))->assertOk()->assertSee('data-billing-confirm', false);
        $this->actingAs($admin)->post(route('ops.billing.orders.confirm', $order))
            ->assertRedirect(route('ops.billing.orders.show', $order))
            ->assertSessionHas('status');

        $order->refresh();
        $this->assertSame('paid', $order->status);
        $this->assertSame($admin->id, $order->confirmed_by);
        $this->assertSame('active', MailSubscription::query()->sole()->status);
        $this->assertSame('starter', MailPackage::forSite($this->site->fresh())->slug);
        $audit = AuditLog::query()->where('action', 'comail.order_paid')->sole();
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertSame('ops', $audit->after['source']);

        // Second click: already paid, nothing changes.
        $this->actingAs($admin)->post(route('ops.billing.orders.confirm', $order))->assertSessionHas('status');
        $this->assertSame(1, AuditLog::query()->where('action', 'comail.order_paid')->count());

        $this->actingAs($admin)->get(route('ops.billing.orders.show', $order))
            ->assertOk()->assertSee('comail.order_paid')->assertSee($admin->name);
    }

    public function test_operator_cannot_confirm_but_can_cancel_and_fail(): void
    {
        $order = $this->placeOrder($this->site, 'eft');
        $operator = $this->opsUser(OpsRole::Operator);

        $this->actingAs($operator)->post(route('ops.billing.orders.confirm', $order))->assertForbidden();
        $this->assertSame('awaiting_transfer', $order->fresh()->status);

        $this->actingAs($operator)->post(route('ops.billing.orders.fail', $order), ['reason' => 'Havale gelmedi'])
            ->assertSessionHas('status');
        $this->assertSame('failed', $order->fresh()->status);
        $this->assertStringContainsString('Havale gelmedi', (string) $order->fresh()->notes);

        $reported = $this->placeOrder($this->site, 'eft');
        $this->signed('POST', '/billing/orders/'.$reported->id.'/transfer-reported', $this->site)->assertOk();
        // Ops may cancel a reported transfer (the customer may not).
        $this->actingAs($operator)->post(route('ops.billing.orders.cancel', $reported))->assertSessionHas('status');
        $this->assertSame('canceled', $reported->fresh()->status);
        $this->actingAs($operator)->post(route('ops.billing.orders.cancel', $reported))->assertSessionHas('error');

        $this->assertTrue(AuditLog::query()->where('action', 'comail.order_failed')->where('actor_user_id', $operator->id)->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'comail.order_canceled')->where('actor_user_id', $operator->id)->exists());
    }

    public function test_viewer_cannot_change_orders(): void
    {
        $order = $this->placeOrder($this->site, 'eft');
        $viewer = $this->opsUser(OpsRole::Viewer);

        $this->actingAs($viewer)->post(route('ops.billing.orders.cancel', $order))->assertForbidden();
        $this->actingAs($viewer)->post(route('ops.billing.orders.fail', $order))->assertForbidden();
        $this->assertSame('awaiting_transfer', $order->fresh()->status);
    }

    public function test_card_orders_are_never_confirmed_by_hand(): void
    {
        $order = $this->placeOrder($this->site, 'iyzico');

        $this->actingAs($this->opsUser())->post(route('ops.billing.orders.confirm', $order))->assertSessionHas('error');
        $this->assertSame('pending', $order->fresh()->status);
    }

    // ── Settings ────────────────────────────────────────────────────────

    public function test_settings_page_never_renders_secrets(): void
    {
        $this->actingAs($this->opsUser())->get(route('ops.billing.settings.edit'))
            ->assertOk()
            ->assertDontSee(FakeIyzico::SECRET)
            ->assertDontSee(FakeIyzico::API_KEY)
            ->assertDontSee(FakePaytr::MERCHANT_KEY)
            ->assertDontSee(FakePaytr::MERCHANT_SALT)
            ->assertSee(FakePaytr::MERCHANT_ID)
            ->assertSee(route('pay.callback.paytr'))
            ->assertSee('TR33 0006 1005 1978 6457 8413 26');

        $this->assertStringNotContainsString(FakeIyzico::SECRET, (string) json_encode(BillingSetting::current()->toArray()));
    }

    public function test_super_admin_saves_settings_and_blank_secrets_keep_the_stored_value(): void
    {
        $admin = $this->opsUser();

        $this->actingAs($admin)->put(route('ops.billing.settings.update'), [
            'iyzico_enabled' => '1',
            'iyzico_sandbox' => '0',
            'iyzico_api_key' => '',
            'iyzico_secret' => 'new-iyzico-secret',
            'paytr_enabled' => '0',
            'paytr_test_mode' => '1',
            'paytr_merchant_id' => '654321',
            'eft_enabled' => '1',
            'bank_name' => 'Yeni Bank',
            'account_holder' => 'CodRon',
            'iban' => 'tr33 0006 1005 1978 6457 8413 26',
            'vat_included' => '0',
            'grace_days' => '3',
            'distance_sales_url' => 'https://codron.co/mesafeli-satis',
        ])->assertRedirect(route('ops.billing.settings.edit'));

        $settings = BillingSetting::current();
        $this->assertSame(FakeIyzico::API_KEY, $settings->iyzico_api_key);
        $this->assertSame('new-iyzico-secret', $settings->iyzico_secret);
        $this->assertFalse($settings->iyzico_sandbox);
        $this->assertFalse($settings->paytr_enabled);
        $this->assertSame(FakePaytr::MERCHANT_KEY, $settings->paytr_merchant_key);
        $this->assertSame('TR330006100519786457841326', $settings->iban);
        $this->assertFalse($settings->vat_included);
        $this->assertSame(3, $settings->grace_days);
        $this->assertSame(['iyzico', 'eft'], $settings->methods());
        $this->assertSame(1, BillingSetting::query()->count());

        // Stored encrypted.
        $raw = DB::table('billing_settings')->first();
        $this->assertStringNotContainsString('new-iyzico-secret', (string) $raw->iyzico_secret);

        $audit = AuditLog::query()->where('action', 'billing.settings_updated')->sole();
        $this->assertTrue($audit->after['has_iyzico_secret']);
        $this->assertStringNotContainsString('new-iyzico-secret', (string) json_encode($audit->toArray()));
        $this->assertStringNotContainsString(FakePaytr::MERCHANT_KEY, (string) json_encode($audit->toArray()));
    }

    public function test_operator_cannot_save_settings_and_iban_is_validated(): void
    {
        $this->actingAs($this->opsUser(OpsRole::Operator))->put(route('ops.billing.settings.update'), ['grace_days' => 7])->assertForbidden();
        $this->actingAs($this->opsUser(OpsRole::Operator))->get(route('ops.billing.settings.edit'))->assertOk()->assertSee('disabled', false);

        $this->actingAs($this->opsUser())->put(route('ops.billing.settings.update'), ['grace_days' => 7, 'iban' => 'not-an-iban'])
            ->assertSessionHasErrors('iban');
    }

    // ── Packages, site card, nav ────────────────────────────────────────

    public function test_package_form_saves_the_yearly_price(): void
    {
        $package = MailPackage::query()->where('slug', 'starter')->firstOrFail();

        $this->actingAs($this->opsUser())->get(route('ops.mail-packages.edit', $package))->assertOk()->assertSee('name="price_yearly"', false);
        $this->actingAs($this->opsUser())->put(route('ops.mail-packages.update', $package), [
            'name' => 'Başlangıç',
            'slug' => 'starter',
            'mailbox_limit' => 5,
            'storage_mb' => 10240,
            'mailbox_max_quota_mb' => 5120,
            'alias_limit' => 50,
            'price_monthly' => '120.00',
            'price_yearly' => '1200.00',
            'currency' => 'TRY',
            'is_active' => '1',
        ])->assertRedirect();

        $this->assertSame('1200.00', (string) $package->fresh()->price_yearly);
        $this->actingAs($this->opsUser())->get(route('ops.mail-packages.index'))->assertOk()->assertSee('1.200,00 TRY');
    }

    public function test_package_with_orders_cannot_be_deleted(): void
    {
        $this->placeOrder($this->site, 'eft');
        $package = MailPackage::query()->where('slug', 'starter')->firstOrFail();

        $this->actingAs($this->opsUser())->delete(route('ops.mail-packages.destroy', $package))->assertSessionHas('error');
        $this->assertNotNull($package->fresh());
    }

    public function test_site_card_shows_the_subscription_and_nav_has_billing(): void
    {
        $this->actingAs($this->opsUser())->get(route('ops.sites.show', $this->site))
            ->assertOk()
            ->assertSee('data-comail-subscription="none"', false)
            ->assertSee(route('ops.billing.orders.index', ['site' => $this->site->id]), false)
            ->assertSee(route('ops.billing.settings.edit'), false);

        $order = $this->placeOrder($this->site, 'eft');
        app(MailBilling::class)->markPaid($order, [], 'ops');

        $end = MailSubscription::query()->sole()->current_period_end->format('Y-m-d');
        $this->actingAs($this->opsUser())->get(route('ops.sites.show', $this->site))
            ->assertOk()
            ->assertSee('data-comail-subscription="active"', false)
            ->assertSee($end);
    }

    // ── MCP ─────────────────────────────────────────────────────────────

    public function test_mcp_lists_orders_and_confirm_needs_a_danger_token(): void
    {
        $order = $this->placeOrder($this->site, 'eft');
        $admin = $this->opsUser();

        [, $read] = McpToken::issue($admin, 'agent', ['read']);
        $list = $this->mcpTool('list_mail_orders', ['method' => 'eft'], $read);
        $this->assertFalse($list['isError']);
        $this->assertSame($order->reference, $list['structuredContent']['orders'][0]['reference']);

        [, $write] = McpToken::issue($admin, 'agent', ['read', 'write']);
        $refused = $this->mcpTool('confirm_mail_order', ['order' => $order->reference, 'confirmed' => true], $write);
        $this->assertTrue($refused['isError']);
        $this->assertSame('awaiting_transfer', $order->fresh()->status);

        [, $danger] = McpToken::issue($admin, 'agent', McpToken::abilityValues());
        $done = $this->mcpTool('confirm_mail_order', ['order' => $order->reference, 'confirmed' => true], $danger);
        $this->assertFalse($done['isError'], json_encode($done));
        $this->assertSame('paid', $order->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{isError: bool, structuredContent: array<string, mixed>}
     */
    private function mcpTool(string $name, array $arguments, string $token): array
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ], ['Authorization' => 'Bearer '.$token])->assertOk()->json('result');
    }
}
