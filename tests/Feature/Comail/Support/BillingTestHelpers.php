<?php

namespace Tests\Feature\Comail\Support;

use App\Models\BillingSetting;
use App\Models\MailOrder;
use App\Models\MailPackage;
use App\Models\Site;
use Illuminate\Support\Facades\Http;

trait BillingTestHelpers
{
    use ComailTestHelpers;

    protected FakeIyzico $iyzico;

    protected FakePaytr $paytr;

    protected function setUpBilling(bool $withServer = true): void
    {
        $this->setUpComail($withServer);
        $this->iyzico = new FakeIyzico;
        $this->paytr = new FakePaytr;
        Http::fake($this->iyzico->fake() + $this->paytr->fake());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function billingSettings(array $overrides = []): BillingSetting
    {
        $settings = new BillingSetting;
        $settings->fill($overrides + [
            'iyzico_enabled' => true,
            'iyzico_api_key' => FakeIyzico::API_KEY,
            'iyzico_secret' => FakeIyzico::SECRET,
            'iyzico_sandbox' => true,
            'paytr_enabled' => true,
            'paytr_merchant_id' => FakePaytr::MERCHANT_ID,
            'paytr_merchant_key' => FakePaytr::MERCHANT_KEY,
            'paytr_merchant_salt' => FakePaytr::MERCHANT_SALT,
            'paytr_test_mode' => true,
            'eft_enabled' => true,
            'bank_name' => 'Örnek Bank',
            'account_holder' => 'CodRon Yazılım Ltd.',
            'iban' => 'TR330006100519786457841326',
            'eft_note' => 'Açıklamaya sipariş numarasını yazın.',
            'vat_included' => true,
            'grace_days' => 7,
        ]);
        $settings->save();

        return $settings;
    }

    /**
     * The seeded "Başlangıç" package, active and priced.
     */
    protected function starterPackage(?string $monthly = '99.90', ?string $yearly = '999.00'): MailPackage
    {
        $package = MailPackage::query()->where('slug', 'starter')->firstOrFail();
        $package->forceFill(['is_active' => true, 'price_monthly' => $monthly, 'price_yearly' => $yearly])->save();

        return $package;
    }

    protected function businessPackage(): MailPackage
    {
        $package = MailPackage::query()->where('slug', 'business')->firstOrFail();
        $package->forceFill(['is_active' => true, 'price_monthly' => '249.00', 'price_yearly' => '2490.00'])->save();

        return $package;
    }

    /**
     * @return array<string, string>
     */
    protected function billingDetails(): array
    {
        return [
            'name' => 'Ayşe Yılmaz',
            'company' => 'Acme Ltd.',
            'tckn_or_vkn' => '12345678901',
            'tax_office' => 'Kadıköy',
            'address' => 'Moda Cad. No: 1',
            'city' => 'İstanbul',
            'district' => 'Kadıköy',
            'phone' => '0532 000 00 00',
            'email' => 'ayse@acme.test',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function placeOrder(Site $site, string $method, array $overrides = []): MailOrder
    {
        $response = $this->signed('POST', '/billing/orders', $site, $overrides + [
            'package' => 'starter',
            'period' => 'monthly',
            'method' => $method,
            'billing' => $this->billingDetails(),
            'return_url' => 'https://acme.test/admin/yonetim/paket',
        ])->assertCreated();

        return MailOrder::query()->findOrFail($response->json('order.id'));
    }
}
