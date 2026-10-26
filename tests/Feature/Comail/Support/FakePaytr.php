<?php

namespace Tests\Feature\Comail\Support;

use App\Services\Billing\PaytrCheckout;
use App\Services\Billing\PaytrTokenBuilder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * PayTR get-token for Http::fake (checks paytr_token like PayTR does) and a
 * builder for signed notification posts.
 */
class FakePaytr
{
    public const MERCHANT_ID = '123456';

    public const MERCHANT_KEY = 'paytr-merchant-key-never-show';

    public const MERCHANT_SALT = 'paytr-merchant-salt-never-show';

    /** @var list<array<string, mixed>> */
    public array $requests = [];

    public bool $failToken = false;

    private int $next = 1;

    /**
     * @return array<string, callable>
     */
    public function fake(): array
    {
        return [PaytrCheckout::TOKEN_URL => fn (Request $request): PromiseInterface => $this->handle($request)];
    }

    public function handle(Request $request): PromiseInterface
    {
        parse_str($request->body(), $form);
        $this->requests[] = $form;

        $expected = (new PaytrTokenBuilder)->buildPaymentToken(
            self::MERCHANT_ID,
            self::MERCHANT_KEY,
            self::MERCHANT_SALT,
            (string) $form['user_ip'],
            (string) $form['merchant_oid'],
            (string) $form['email'],
            (int) $form['payment_amount'],
            json_decode((string) base64_decode((string) $form['user_basket']), true),
            (int) $form['no_installment'],
            (int) $form['max_installment'],
            (string) $form['currency'],
            (int) $form['test_mode'],
        );

        if ($this->failToken || ! hash_equals($expected, (string) $form['paytr_token'])) {
            return Http::response(['status' => 'failed', 'reason' => 'paytr_token gecersiz'], 200);
        }

        return Http::response(['status' => 'success', 'token' => 'paytr-iframe-token-'.$this->next++], 200);
    }

    /**
     * @return array<string, string>
     */
    public static function notification(string $merchantOid, string $status, int $totalAmount, ?int $paymentAmount = null, ?string $key = null): array
    {
        $hash = (new PaytrTokenBuilder)->buildCallbackHash(
            $key ?? self::MERCHANT_KEY,
            self::MERCHANT_SALT,
            $merchantOid,
            $status,
            (string) $totalAmount,
        );

        return array_filter([
            'merchant_oid' => $merchantOid,
            'status' => $status,
            'total_amount' => (string) $totalAmount,
            'payment_amount' => $paymentAmount === null ? null : (string) $paymentAmount,
            'hash' => $hash,
            'failed_reason_code' => $status === 'success' ? null : '0',
            'failed_reason_msg' => $status === 'success' ? null : 'Kart limiti yetersiz',
            'test_mode' => '1',
            'payment_type' => 'card',
            'currency' => 'TL',
        ], static fn (?string $value): bool => $value !== null);
    }
}
