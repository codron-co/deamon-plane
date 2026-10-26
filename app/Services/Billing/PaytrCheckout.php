<?php

namespace App\Services\Billing;

use App\Models\BillingSetting;
use App\Models\MailOrder;
use App\Services\Comail\ComailException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PayTR iFrame API (request and hash logic from the Deamon CMS gateway).
 *
 * get-token → iframe `https://www.paytr.com/odeme/guvenli/{token}` on Plane's
 * pay page → PayTR POSTs the result to the notification URL set in the PayTR
 * merchant panel (`/pay/callback/paytr`), verified by hash, answered "OK".
 */
class PaytrCheckout
{
    public const TOKEN_URL = 'https://www.paytr.com/odeme/api/get-token';

    public const IFRAME_URL = 'https://www.paytr.com/odeme/guvenli/';

    /** Notification fields worth keeping (PayTR sends no card number; this is a whitelist anyway). */
    private const KEEP = [
        'merchant_oid', 'status', 'total_amount', 'payment_amount', 'payment_type', 'currency',
        'installment_count', 'test_mode', 'failed_reason_code', 'failed_reason_msg',
    ];

    public function __construct(private readonly PaytrTokenBuilder $tokens) {}

    public function iframeToken(MailOrder $order, BillingSetting $settings, string $ip, string $okUrl, string $failUrl): string
    {
        $order->loadMissing('mailPackage');
        $billing = is_array($order->billing) ? $order->billing : [];
        $merchantId = (string) $settings->paytr_merchant_id;
        $merchantKey = (string) $settings->paytr_merchant_key;
        $merchantSalt = (string) $settings->paytr_merchant_salt;

        $basket = $this->tokens->formatBasket([[
            'name' => IyzicoCheckout::itemName($order),
            'unit_price' => (string) $order->amount,
            'quantity' => 1,
        ]]);
        $amountCents = $order->amountCents();
        $currency = $this->tokens->paytrCurrency((string) $order->currency);
        $testMode = $settings->paytr_test_mode ? 1 : 0;
        $noInstallment = 1;
        $maxInstallment = 0;
        $userIp = $ip !== '' ? $ip : '127.0.0.1';
        $email = (string) ($billing['email'] ?? '');

        $payload = [
            'merchant_id' => $merchantId,
            'user_ip' => $userIp,
            'merchant_oid' => $order->merchantOid(),
            'email' => $email,
            'payment_amount' => $amountCents,
            'paytr_token' => $this->tokens->buildPaymentToken(
                $merchantId,
                $merchantKey,
                $merchantSalt,
                $userIp,
                $order->merchantOid(),
                $email,
                $amountCents,
                $basket,
                $noInstallment,
                $maxInstallment,
                $currency,
                $testMode,
            ),
            'user_basket' => $this->tokens->encodeBasket($basket),
            'debug_on' => 0,
            'no_installment' => $noInstallment,
            'max_installment' => $maxInstallment,
            'user_name' => mb_substr(trim((string) ($billing['company'] ?? '')) ?: (string) ($billing['name'] ?? 'Müşteri'), 0, 60),
            'user_address' => mb_substr(trim(implode(' ', array_filter([
                (string) ($billing['address'] ?? ''),
                (string) ($billing['district'] ?? ''),
                (string) ($billing['city'] ?? ''),
            ]))) ?: 'Belirtilmedi', 0, 400),
            'user_phone' => mb_substr((string) ($billing['phone'] ?? ''), 0, 20),
            'merchant_ok_url' => $okUrl,
            'merchant_fail_url' => $failUrl,
            'timeout_limit' => MailOrder::CARD_TTL_MINUTES,
            'currency' => $currency,
            'test_mode' => $testMode,
            'lang' => 'tr',
        ];

        try {
            $response = Http::asForm()->timeout(20)->connectTimeout(5)->post(self::TOKEN_URL, $payload);
        } catch (ConnectionException) {
            Log::warning('billing.paytr_unreachable', ['order_id' => $order->id]);

            throw new ComailException('provider_error', 'Ödeme sağlayıcısına ulaşılamadı. Biraz sonra tekrar deneyin.', 502);
        }

        $json = $response->json();
        if (! $response->successful() || ! is_array($json) || ($json['status'] ?? '') !== 'success' || ! is_string($json['token'] ?? null) || $json['token'] === '') {
            Log::warning('billing.paytr_token_failed', [
                'order_id' => $order->id,
                'http_status' => $response->status(),
                'reason' => is_array($json) && is_string($json['reason'] ?? null) ? mb_substr($json['reason'], 0, 200) : null,
            ]);

            throw new ComailException('provider_error', 'Ödeme sayfası açılamadı. Biraz sonra tekrar deneyin.', 502);
        }

        return (string) $json['token'];
    }

    /**
     * @param  array<string, mixed>  $payload  PayTR notification POST
     * @return array{valid: bool, success: bool, merchant_oid: string, payment_amount: int|null, total_amount: int|null, error: string, payload: array<string, mixed>}
     */
    public function verifyNotification(array $payload, BillingSetting $settings): array
    {
        $merchantOid = (string) ($payload['merchant_oid'] ?? '');
        $status = (string) ($payload['status'] ?? '');
        $totalAmount = (string) ($payload['total_amount'] ?? '');

        $expected = $this->tokens->buildCallbackHash(
            (string) $settings->paytr_merchant_key,
            (string) $settings->paytr_merchant_salt,
            $merchantOid,
            $status,
            $totalAmount,
        );
        $valid = $settings->paytrReady() && hash_equals($expected, (string) ($payload['hash'] ?? ''));

        return [
            'valid' => $valid,
            'success' => $valid && $status === 'success',
            'merchant_oid' => $merchantOid,
            'payment_amount' => is_numeric($payload['payment_amount'] ?? null) ? (int) $payload['payment_amount'] : null,
            'total_amount' => is_numeric($totalAmount) ? (int) $totalAmount : null,
            'error' => mb_substr((string) ($payload['failed_reason_msg'] ?? $payload['failed_reason_code'] ?? ''), 0, 255),
            'payload' => array_map(
                static fn (mixed $value): mixed => is_scalar($value) ? mb_substr((string) $value, 0, 255) : null,
                array_intersect_key($payload, array_flip(self::KEEP)),
            ),
        ];
    }
}
