<?php

namespace App\Services\Billing;

use App\Models\MailOrder;

/**
 * Hosted checkout link `/pay/{order}/{token}`: the token is an HMAC of the
 * order id and its expiry with the app key, so it is bound to one order and
 * dies with it.
 */
final class PayLink
{
    public static function token(MailOrder $order): string
    {
        $payload = 'comail-pay|'.$order->id.'|'.($order->expires_at?->getTimestamp() ?? 0);

        return substr(hash_hmac('sha256', $payload, (string) config('app.key')), 0, 48);
    }

    public static function valid(MailOrder $order, string $token): bool
    {
        return $token !== '' && hash_equals(self::token($order), $token);
    }

    public static function url(MailOrder $order): string
    {
        return route('pay.show', ['order' => $order->id, 'token' => self::token($order)]);
    }

    public static function doneUrl(MailOrder $order, string $result): string
    {
        return route('pay.done', ['order' => $order->id, 'token' => self::token($order), 'result' => $result]);
    }
}
