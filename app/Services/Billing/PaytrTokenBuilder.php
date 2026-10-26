<?php

namespace App\Services\Billing;

/**
 * PayTR iFrame API token and notification hash, ported from the Deamon CMS
 * gateway (proven in production).
 */
final class PaytrTokenBuilder
{
    /**
     * @param  array<int, array{0: string, 1: string, 2: int}>  $basketItems
     */
    public function buildPaymentToken(
        string $merchantId,
        string $merchantKey,
        string $merchantSalt,
        string $userIp,
        string $merchantOid,
        string $email,
        int $paymentAmountCents,
        array $basketItems,
        int $noInstallment,
        int $maxInstallment,
        string $currency,
        int $testMode,
    ): string {
        $userBasket = $this->encodeBasket($basketItems);

        $hashStr = $merchantId
            .$userIp
            .$merchantOid
            .$email
            .(string) $paymentAmountCents
            .$userBasket
            .(string) $noInstallment
            .(string) $maxInstallment
            .$currency
            .(string) $testMode;

        return base64_encode(hash_hmac('sha256', $hashStr.$merchantSalt, $merchantKey, true));
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: int}>  $basketItems
     */
    public function encodeBasket(array $basketItems): string
    {
        return base64_encode((string) json_encode($basketItems, JSON_UNESCAPED_UNICODE));
    }

    public function buildCallbackHash(
        string $merchantKey,
        string $merchantSalt,
        string $merchantOid,
        string $status,
        string $totalAmount,
    ): string {
        $hashStr = $merchantOid.$merchantSalt.$status.$totalAmount;

        return base64_encode(hash_hmac('sha256', $hashStr, $merchantKey, true));
    }

    /**
     * @param  list<array{name: string, unit_price: float|string, quantity: int}>  $items
     * @return array<int, array{0: string, 1: string, 2: int}>
     */
    public function formatBasket(array $items): array
    {
        $basket = [];
        foreach ($items as $item) {
            $basket[] = [
                (string) $item['name'],
                number_format((float) $item['unit_price'], 2, '.', ''),
                (int) $item['quantity'],
            ];
        }

        return $basket;
    }

    public function paytrCurrency(string $currency): string
    {
        return match (strtoupper(trim($currency))) {
            'USD' => 'USD',
            'EUR' => 'EUR',
            'GBP' => 'GBP',
            'RUB' => 'RUB',
            default => 'TL',
        };
    }
}
