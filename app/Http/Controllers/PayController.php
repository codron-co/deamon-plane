<?php

namespace App\Http\Controllers;

use App\Models\BillingSetting;
use App\Models\MailOrder;
use App\Services\Billing\IyzicoCheckout;
use App\Services\Billing\MailBilling;
use App\Services\Billing\PayLink;
use App\Services\Billing\PaytrCheckout;
use App\Services\Comail\ComailException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Hosted CoMail checkout on Plane's own domain (customers' browsers, no
 * Plane login, no session). Card data is typed into the provider's frame only;
 * every result is verified server-side before the order is paid.
 */
class PayController extends Controller
{
    /** Reuse a provider checkout for this long instead of opening a new one per reload. */
    private const CHECKOUT_REUSE_MINUTES = 25;

    /** PayTR ok page: how often to look for the notification before handing over. */
    private const DONE_ATTEMPTS = 5;

    public function __construct(
        private readonly MailBilling $billing,
        private readonly IyzicoCheckout $iyzico,
        private readonly PaytrCheckout $paytr,
    ) {}

    public function show(Request $request, string $order, string $token): Response
    {
        app()->setLocale('tr');
        $mailOrder = $this->order($order, $token);
        if (! $mailOrder instanceof MailOrder) {
            return $this->renderResult(null, 'invalid', 404);
        }

        if ($mailOrder->isPaid()) {
            return $this->renderResult($mailOrder, 'paid');
        }

        if (! $mailOrder->isOpen() || ($mailOrder->isExpired() && $mailOrder->status !== MailOrder::STATUS_TRANSFER_REPORTED)) {
            return $this->renderResult($mailOrder, $mailOrder->status === MailOrder::STATUS_FAILED ? 'failed' : ($mailOrder->status === MailOrder::STATUS_CANCELED ? 'canceled' : 'expired'));
        }

        $settings = $this->billing->settings();
        $frame = null;
        if ($mailOrder->isCard()) {
            $ready = $mailOrder->method === MailOrder::METHOD_IYZICO ? $settings->iyzicoReady() : $settings->paytrReady();
            if (! $ready) {
                return $this->renderResult($mailOrder, 'unavailable', 503);
            }

            try {
                $frame = $this->frameUrl($mailOrder, $settings, (string) $request->ip());
            } catch (ComailException) {
                return $this->renderResult($mailOrder, 'provider_error', 502);
            }
        }

        return $this->page('pay.show', [
            'order' => $mailOrder,
            'frame' => $frame,
            'bank' => $mailOrder->method === MailOrder::METHOD_EFT ? $settings->bank() : null,
            'vatIncluded' => (bool) $settings->vat_included,
            'backUrl' => $this->backUrl($mailOrder),
        ]);
    }

    /**
     * PayTR's merchant_ok_url / merchant_fail_url (loaded inside the PayTR frame).
     * The order only turns paid by the verified notification.
     */
    public function done(Request $request, string $order, string $token, string $result): Response
    {
        app()->setLocale('tr');
        $mailOrder = $this->order($order, $token);
        if (! $mailOrder instanceof MailOrder) {
            return $this->renderResult(null, 'invalid', 404);
        }

        if ($mailOrder->isPaid()) {
            return $this->handOver($mailOrder, 'paid');
        }

        if ($result !== 'ok' || ! $mailOrder->isOpen()) {
            return $this->handOver($mailOrder, 'failed');
        }

        $attempt = max(0, min(self::DONE_ATTEMPTS, (int) $request->query('attempt', 0)));
        if ($attempt >= self::DONE_ATTEMPTS) {
            // The notification is late; the CMS reads the order from Plane anyway.
            return $this->handOver($mailOrder, 'pending');
        }

        return $this->page('pay.waiting', [
            'order' => $mailOrder,
            'next' => PayLink::doneUrl($mailOrder, 'ok').'?attempt='.($attempt + 1),
        ]);
    }

    private function renderResult(?MailOrder $order, string $state, int $status = 200): Response
    {
        app()->setLocale('tr');

        return $this->page('pay.result', [
            'order' => $order,
            'state' => $state,
            'backUrl' => $order instanceof MailOrder ? $this->backUrl($order) : null,
        ], $status);
    }

    public function showResult(string $order, string $token): Response
    {
        app()->setLocale('tr');
        $mailOrder = $this->order($order, $token);
        if (! $mailOrder instanceof MailOrder) {
            return $this->renderResult(null, 'invalid', 404);
        }

        return $this->renderResult($mailOrder, match (true) {
            $mailOrder->isPaid() => 'paid',
            $mailOrder->status === MailOrder::STATUS_FAILED => 'failed',
            $mailOrder->status === MailOrder::STATUS_CANCELED => 'canceled',
            $mailOrder->status === MailOrder::STATUS_EXPIRED => 'expired',
            default => 'pending',
        });
    }

    /**
     * iyzico posts `token` here (inside its frame) once the customer finished.
     */
    public function iyzicoCallback(Request $request): Response
    {
        app()->setLocale('tr');
        $token = trim((string) $request->input('token', ''));
        $settings = $this->billing->settings();
        if ($token === '' || strlen($token) > 255 || ! $settings->hasSecret('iyzico_secret')) {
            return $this->renderResult(null, 'invalid', 400);
        }

        $order = MailOrder::query()->where('method', MailOrder::METHOD_IYZICO)->where('provider_token', $token)->first();

        try {
            $result = $this->iyzico->retrieve($token, $settings);
        } catch (ComailException) {
            return $order instanceof MailOrder ? $this->handOver($order, 'failed') : $this->renderResult(null, 'provider_error', 502);
        }

        if (! $order instanceof MailOrder && $result['valid'] && $result['basket_id'] !== '') {
            $order = MailOrder::query()->where('method', MailOrder::METHOD_IYZICO)->where('reference', $result['basket_id'])->first();
        }

        if (! $order instanceof MailOrder) {
            Log::warning('billing.iyzico_callback_unknown_order', ['valid' => $result['valid']]);

            return $this->renderResult(null, 'invalid', 404);
        }

        if (! $result['valid']) {
            Log::warning('billing.iyzico_bad_signature', ['order_id' => $order->id]);

            return $this->handOver($order, $order->isPaid() ? 'paid' : 'failed');
        }

        $matches = $result['basket_id'] === (string) $order->reference
            && self::cents($result['paid_price']) === $order->amountCents()
            && strtoupper($result['currency']) === strtoupper((string) $order->currency);

        if ($result['success'] && $matches) {
            $this->billing->markPaid($order, [
                'provider_payment_id' => $result['payment_id'],
                'provider_payload' => $result['payload'],
            ], 'iyzico', null, $request->ip());

            return $this->handOver($order->refresh(), 'paid');
        }

        if ($order->isPaid()) {
            return $this->handOver($order, 'paid');
        }

        if ($result['success']) {
            Log::error('billing.iyzico_amount_mismatch', ['order_id' => $order->id]);
        }

        $this->billing->markFailed(
            $order,
            $result['success'] ? 'iyzico: tutar ya da sipariş eşleşmedi' : 'iyzico: '.($result['error'] !== '' ? $result['error'] : 'ödeme alınamadı'),
            $result['payload'],
            'iyzico',
            null,
            $request->ip(),
        );

        return $this->handOver($order->refresh(), 'failed');
    }

    /**
     * PayTR notification (server to server). Must answer plain "OK" once handled.
     */
    public function paytrCallback(Request $request): Response
    {
        $settings = $this->billing->settings();
        $result = $this->paytr->verifyNotification($request->all(), $settings);
        if (! $result['valid']) {
            Log::warning('billing.paytr_bad_hash', ['ip' => $request->ip()]);

            return $this->plain('PAYTR notification failed: bad hash', 400);
        }

        $order = MailOrder::query()
            ->where('method', MailOrder::METHOD_PAYTR)
            ->where('reference', self::referenceFromOid($result['merchant_oid']))
            ->first();
        if (! $order instanceof MailOrder) {
            Log::warning('billing.paytr_unknown_order', ['merchant_oid' => mb_substr($result['merchant_oid'], 0, 64)]);

            return $this->plain('OK');
        }

        if ($result['success']) {
            if ($result['payment_amount'] !== null && $result['payment_amount'] !== $order->amountCents()) {
                Log::error('billing.paytr_amount_mismatch', ['order_id' => $order->id]);
                $this->billing->markFailed($order, 'PayTR: tutar eşleşmedi', $result['payload'], 'paytr', null, $request->ip());

                return $this->plain('OK');
            }

            $this->billing->markPaid($order, ['provider_payload' => $result['payload']], 'paytr', null, $request->ip());

            return $this->plain('OK');
        }

        $this->billing->markFailed(
            $order,
            'PayTR: '.($result['error'] !== '' ? $result['error'] : 'ödeme alınamadı'),
            $result['payload'],
            'paytr',
            null,
            $request->ip(),
        );

        return $this->plain('OK');
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function order(string $id, string $token): ?MailOrder
    {
        if (! Str::isUlid($id)) {
            return null;
        }

        $order = MailOrder::query()->with(['mailPackage', 'site'])->find($id);

        return $order instanceof MailOrder && PayLink::valid($order, $token) ? $order : null;
    }

    /**
     * Provider frame URL; a checkout opened in the last minutes is reused on reload.
     */
    private function frameUrl(MailOrder $order, BillingSetting $settings, string $ip): string
    {
        $payload = is_array($order->provider_payload) ? $order->provider_payload : [];
        $checkout = is_array($payload['checkout'] ?? null) ? $payload['checkout'] : [];
        $issued = (int) ($checkout['issued_at'] ?? 0);
        $reusable = filled($order->provider_token)
            && ($checkout['method'] ?? null) === $order->method
            && $issued > now()->subMinutes(self::CHECKOUT_REUSE_MINUTES)->getTimestamp();

        if ($order->method === MailOrder::METHOD_IYZICO) {
            if ($reusable && is_string($checkout['page_url'] ?? null) && $checkout['page_url'] !== '') {
                return $checkout['page_url'];
            }

            $result = $this->iyzico->initialize($order, $settings, $ip, route('pay.callback.iyzico'));
            $this->rememberCheckout($order, $result['token'], ['page_url' => $result['page_url']]);

            return $result['page_url'];
        }

        if ($reusable) {
            return PaytrCheckout::IFRAME_URL.$order->provider_token;
        }

        $token = $this->paytr->iframeToken($order, $settings, $ip, PayLink::doneUrl($order, 'ok'), PayLink::doneUrl($order, 'fail'));
        $this->rememberCheckout($order, $token, []);

        return PaytrCheckout::IFRAME_URL.$token;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function rememberCheckout(MailOrder $order, string $token, array $extra): void
    {
        $payload = is_array($order->provider_payload) ? $order->provider_payload : [];
        $payload['checkout'] = ['method' => $order->method, 'issued_at' => now()->getTimestamp()] + $extra;

        $order->forceFill(['provider_token' => $token, 'provider_payload' => $payload])->save();
    }

    /**
     * Leave the provider frame: back to the site's return_url (or Plane's result page).
     */
    private function handOver(MailOrder $order, string $status): Response
    {
        $target = $this->billing->returnUrl($order, $status)
            ?? route('pay.result', ['order' => $order->id, 'token' => PayLink::token($order)]);

        return $this->page('pay.redirect', ['order' => $order, 'target' => $target, 'state' => $status]);
    }

    private function backUrl(MailOrder $order): ?string
    {
        $url = (string) $order->return_url;

        return $url !== '' ? $url : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function page(string $view, array $data, int $status = 200): Response
    {
        // The checkout page itself is never framed; the pages PayTR / iyzico load
        // inside their own frame (result, redirect, waiting) may be framed by them.
        $ancestors = $view === 'pay.show' ? "'self'" : "'self' https://www.paytr.com https://*.iyzipay.com";

        return response()->view($view, $data, $status)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Content-Security-Policy' => 'frame-ancestors '.$ancestors,
        ]);
    }

    private function plain(string $body, int $status = 200): Response
    {
        return response($body, $status)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    private static function cents(string $amount): int
    {
        return is_numeric($amount) ? (int) round(((float) $amount) * 100) : -1;
    }

    /**
     * PayTR merchant_oid is the reference without its dash (CM7K3Q9P → CM-7K3Q9P).
     */
    public static function referenceFromOid(string $oid): string
    {
        $oid = strtoupper(trim($oid));

        return str_starts_with($oid, 'CM') ? 'CM-'.substr($oid, 2) : $oid;
    }
}
