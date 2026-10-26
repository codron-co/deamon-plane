<?php

namespace App\Services\Billing;

use App\Models\BillingSetting;
use App\Models\MailOrder;
use App\Services\Comail\ComailException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * iyzico Checkout Form (request and signature logic from the Deamon CMS gateway).
 *
 * initialize → the hosted `paymentPageUrl` is embedded on Plane's pay page →
 * iyzico POSTs only a `token` to /pay/callback/iyzico → retrieve() reads the
 * result server-to-server and checks its HMAC signature. No card data ever
 * reaches Plane; only whitelisted, non-card fields are kept.
 */
class IyzicoCheckout
{
    public const SANDBOX_BASE_URL = 'https://sandbox-api.iyzipay.com';

    public const LIVE_BASE_URL = 'https://api.iyzipay.com';

    public const INITIALIZE_PATH = '/payment/iyzipos/checkoutform/initialize/auth/ecom';

    public const RETRIEVE_PATH = '/payment/iyzipos/checkoutform/auth/ecom/detail';

    /** Retrieve fields worth keeping (never card number, BIN, last four or card holder). */
    private const KEEP = [
        'status', 'paymentStatus', 'paymentId', 'conversationId', 'basketId', 'price', 'paidPrice',
        'currency', 'installment', 'fraudStatus', 'errorCode', 'errorMessage', 'errorGroup', 'systemTime',
    ];

    public function __construct(private readonly IyzicoSignatureBuilder $signatures) {}

    /**
     * @return array{token: string, page_url: string}
     */
    public function initialize(MailOrder $order, BillingSetting $settings, string $ip, string $callbackUrl): array
    {
        $order->loadMissing(['mailPackage', 'site']);
        $billing = is_array($order->billing) ? $order->billing : [];
        $price = self::amount((string) $order->amount);
        $name = trim((string) ($billing['name'] ?? '')) ?: 'Müşteri';
        $contact = trim((string) ($billing['company'] ?? '')) ?: $name;
        $address = trim((string) ($billing['address'] ?? '')) ?: 'Belirtilmedi';
        $city = trim((string) ($billing['city'] ?? '')) ?: 'İstanbul';
        $taxId = (string) preg_replace('/\D+/', '', (string) ($billing['tckn_or_vkn'] ?? ''));

        $addressPayload = [
            'contactName' => $contact,
            'city' => $city,
            'country' => 'Turkey',
            'address' => $address,
        ];

        $body = [
            'locale' => 'tr',
            'conversationId' => (string) $order->reference,
            'price' => $price,
            'paidPrice' => $price,
            'currency' => $order->currency !== '' ? (string) $order->currency : 'TRY',
            'basketId' => (string) $order->reference,
            'paymentGroup' => 'PRODUCT',
            'callbackUrl' => $callbackUrl,
            'enabledInstallments' => [1],
            'buyer' => [
                'id' => 'site-'.$order->site_id,
                'name' => self::firstName($name),
                'surname' => self::lastName($name),
                'gsmNumber' => self::phone((string) ($billing['phone'] ?? '')),
                'email' => (string) ($billing['email'] ?? ''),
                // iyzico wants a TCKN (11 digits); a company VKN is not one.
                'identityNumber' => strlen($taxId) === 11 ? $taxId : '11111111111',
                'registrationAddress' => $address,
                'ip' => $ip !== '' ? $ip : '127.0.0.1',
                'city' => $city,
                'country' => 'Turkey',
            ],
            'shippingAddress' => $addressPayload,
            'billingAddress' => $addressPayload,
            'basketItems' => [[
                'id' => (string) $order->mailPackage->slug,
                'name' => self::itemName($order),
                'category1' => 'E-posta',
                'itemType' => 'VIRTUAL',
                'price' => $price,
            ]],
        ];

        $json = $this->post($settings, self::INITIALIZE_PATH, $body, 'initialize');

        if (($json['status'] ?? '') !== 'success' || ! is_string($json['token'] ?? null) || $json['token'] === '') {
            Log::warning('billing.iyzico_initialize_failed', [
                'order_id' => $order->id,
                'error_code' => $json['errorCode'] ?? null,
                'error' => is_string($json['errorMessage'] ?? null) ? mb_substr($json['errorMessage'], 0, 200) : null,
            ]);

            throw new ComailException('provider_error', 'Ödeme sayfası açılamadı. Biraz sonra tekrar deneyin.', 502);
        }

        return [
            'token' => (string) $json['token'],
            'page_url' => is_string($json['paymentPageUrl'] ?? null) ? $json['paymentPageUrl'] : '',
        ];
    }

    /**
     * Server-to-server result of a checkout form by its token.
     *
     * @return array{valid: bool, success: bool, payment_id: string, basket_id: string, paid_price: string, currency: string, error: string, payload: array<string, mixed>}
     */
    public function retrieve(string $token, BillingSetting $settings): array
    {
        $json = $this->post($settings, self::RETRIEVE_PATH, [
            'locale' => 'tr',
            'conversationId' => $token,
            'token' => $token,
        ], 'retrieve');

        $paymentStatus = (string) ($json['paymentStatus'] ?? '');
        $valid = $this->signatures->verifyResponseSignature([
            $paymentStatus,
            (string) ($json['paymentId'] ?? ''),
            (string) ($json['currency'] ?? ''),
            (string) ($json['basketId'] ?? ''),
            (string) ($json['conversationId'] ?? ''),
            (string) ($json['paidPrice'] ?? ''),
            (string) ($json['price'] ?? ''),
            (string) ($json['token'] ?? ''),
        ], (string) ($json['signature'] ?? ''), (string) $settings->iyzico_secret);

        return [
            'valid' => $valid,
            'success' => $valid && ($json['status'] ?? '') === 'success' && $paymentStatus === 'SUCCESS',
            'payment_id' => (string) ($json['paymentId'] ?? ''),
            'basket_id' => (string) ($json['basketId'] ?? ''),
            'paid_price' => (string) ($json['paidPrice'] ?? ''),
            'currency' => (string) ($json['currency'] ?? ''),
            'error' => mb_substr((string) ($json['errorMessage'] ?? ''), 0, 255),
            'payload' => array_intersect_key($json, array_flip(self::KEEP)),
        ];
    }

    public static function baseUrl(BillingSetting $settings): string
    {
        return $settings->iyzico_sandbox ? self::SANDBOX_BASE_URL : self::LIVE_BASE_URL;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(BillingSetting $settings, string $path, array $body, string $step): array
    {
        $json = $this->signatures->encode($body);
        $headers = $this->signatures->headers((string) $settings->iyzico_api_key, (string) $settings->iyzico_secret, $path, $json);

        try {
            /** @var Response $response */
            $response = Http::withHeaders($headers)
                ->acceptJson()
                ->timeout(20)
                ->connectTimeout(5)
                // Send exactly the bytes that were signed.
                ->withBody($json, 'application/json')
                ->post(self::baseUrl($settings).$path);
        } catch (ConnectionException) {
            Log::warning('billing.iyzico_unreachable', ['step' => $step]);

            throw new ComailException('provider_error', 'Ödeme sağlayıcısına ulaşılamadı. Biraz sonra tekrar deneyin.', 502);
        }

        if (! $response->successful()) {
            Log::warning('billing.iyzico_http_error', ['step' => $step, 'http_status' => $response->status()]);

            throw new ComailException('provider_error', 'Ödeme sağlayıcısı isteği kabul etmedi.', 502);
        }

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    public static function itemName(MailOrder $order): string
    {
        $period = $order->period === MailOrder::PERIOD_YEARLY ? 'Yıllık' : 'Aylık';

        return mb_substr('CoMail '.$order->mailPackage->name.' ('.$period.')', 0, 120);
    }

    public static function amount(string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private static function firstName(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];

        return $parts[0] ?? 'Müşteri';
    }

    private static function lastName(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];
        array_shift($parts);
        $last = trim(implode(' ', $parts));

        return $last !== '' ? $last : 'Müşteri';
    }

    public static function phone(string $phone): string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);
        if ($digits === '') {
            return '+905555555555';
        }

        if (str_starts_with($digits, '90')) {
            return '+'.$digits;
        }

        return '+90'.ltrim($digits, '0');
    }
}
