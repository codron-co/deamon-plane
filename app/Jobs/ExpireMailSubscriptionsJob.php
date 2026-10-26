<?php

namespace App\Jobs;

use App\Services\Billing\MailBilling;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Daily CoMail billing pass: subscriptions past their period go to grace,
 * then expire to the default package; open card checkouts and EFT orders
 * past their window expire.
 */
class ExpireMailSubscriptionsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(MailBilling $billing): void
    {
        $subscriptions = $billing->expireSubscriptions();
        $orders = $billing->expireOrders();

        if ($subscriptions['grace'] + $subscriptions['expired'] + $orders > 0) {
            Log::info('comail.billing_expiry', $subscriptions + ['orders_expired' => $orders]);
        }
    }
}
