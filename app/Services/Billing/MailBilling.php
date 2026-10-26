<?php

namespace App\Services\Billing;

use App\Jobs\ApplyComailPackageJob;
use App\Models\AuditLog;
use App\Models\BillingSetting;
use App\Models\MailOrder;
use App\Models\MailPackage;
use App\Models\MailSubscription;
use App\Models\Site;
use App\Models\User;
use App\Services\Cloudflare\CloudflareHostname;
use App\Services\Comail\ComailException;
use Illuminate\Database\Eloquent\Collection;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * CoMail package purchases: pricing, orders, the paid transition (idempotent,
 * callbacks may repeat), EFT reporting/confirmation and the daily expiry.
 * The effective package stays `sites.mail_package_id`; paying sets it and
 * re-applies the mailcow limits, expiry resets it to the default package.
 */
class MailBilling
{
    public function settings(): BillingSetting
    {
        return BillingSetting::current();
    }

    /**
     * Packages a site may see and buy, by sort.
     *
     * @return Collection<int, MailPackage>
     */
    public function packages(): Collection
    {
        return MailPackage::query()->where('is_active', true)->orderBy('sort')->orderBy('name')->get();
    }

    /**
     * @return array{period: string|null, status: string|null, period_start: string|null, period_end: string|null, grace_ends_at: string|null, auto_renew: bool}
     */
    public function subscriptionState(Site $site): array
    {
        $subscription = $site->mailSubscription()->first();
        if (! $subscription instanceof MailSubscription) {
            return ['period' => null, 'status' => null, 'period_start' => null, 'period_end' => null, 'grace_ends_at' => null, 'auto_renew' => false];
        }

        return [
            'period' => (string) $subscription->period,
            'status' => (string) $subscription->status,
            'period_start' => $subscription->current_period_start?->toIso8601String(),
            'period_end' => $subscription->current_period_end?->toIso8601String(),
            'grace_ends_at' => $subscription->status === MailSubscription::STATUS_GRACE
                ? $subscription->graceEndsAt($this->settings()->graceDays())?->toIso8601String()
                : null,
            'auto_renew' => (bool) $subscription->auto_renew,
        ];
    }

    /**
     * @param  array{package: string, period: string, method: string, billing: array<string, mixed>, return_url?: string|null}  $data
     */
    public function createOrder(Site $site, array $data, ?string $ip = null): MailOrder
    {
        $package = MailPackage::query()->where('slug', (string) $data['package'])->where('is_active', true)->first();
        if (! $package instanceof MailPackage) {
            throw ComailException::validation('Paket bulunamadı ya da satışta değil.');
        }

        $settings = $this->settings();
        $method = (string) $data['method'];
        if (! in_array($method, $settings->methods(), true)) {
            throw new ComailException('method_disabled', 'Bu ödeme yöntemi şu anda kullanılamıyor.');
        }

        $period = (string) $data['period'];
        $price = $package->priceFor($period);
        if ($price === null) {
            throw new ComailException('price_missing', $period === MailOrder::PERIOD_YEARLY
                ? 'Bu paketin yıllık fiyatı yok.'
                : 'Bu paketin aylık fiyatı yok.');
        }

        $subscription = $site->mailSubscription()->first();
        $kind = match (true) {
            ! $subscription instanceof MailSubscription || ! $subscription->isLive() => MailOrder::KIND_NEW,
            (string) $subscription->mail_package_id === (string) $package->id => MailOrder::KIND_RENEWAL,
            default => MailOrder::KIND_CHANGE,
        };

        $eft = $method === MailOrder::METHOD_EFT;
        $order = MailOrder::query()->create([
            'site_id' => $site->id,
            'mail_package_id' => $package->id,
            'period' => $period,
            'kind' => $kind,
            'amount' => $price,
            'currency' => (string) ($package->currency ?: 'TRY'),
            'method' => $method,
            'status' => $eft ? MailOrder::STATUS_AWAITING_TRANSFER : MailOrder::STATUS_PENDING,
            'reference' => MailOrder::newReference(),
            'billing' => $data['billing'],
            'return_url' => self::allowedReturnUrl($site, $data['return_url'] ?? null),
            'expires_at' => $eft ? now()->addDays(MailOrder::EFT_TTL_DAYS) : now()->addMinutes(MailOrder::CARD_TTL_MINUTES),
        ]);

        $this->audit($site, 'comail.order_created', [
            'order_id' => $order->id,
            'reference' => $order->reference,
            'package' => $package->slug,
            'period' => $period,
            'kind' => $kind,
            'method' => $method,
            'amount' => (string) $order->amount,
            'currency' => $order->currency,
        ], null, $ip, 'cms');

        return $order;
    }

    /**
     * The money is in (verified card callback or EFT confirmed by ops):
     * subscription + effective package + mailcow limits. Idempotent: an
     * already paid order returns false and changes nothing. A late card
     * callback for an expired or canceled order still activates, because the
     * customer has paid.
     *
     * @param  array{provider_payment_id?: string|null, provider_payload?: array<string, mixed>|null}  $provider
     */
    public function markPaid(MailOrder $order, array $provider, string $source, ?User $actor = null, ?string $ip = null): bool
    {
        $result = DB::transaction(function () use ($order, $provider, $actor): ?array {
            $locked = MailOrder::query()->whereKey($order->getKey())->lockForUpdate()->first();
            if (! $locked instanceof MailOrder || $locked->isPaid()) {
                return null;
            }

            $from = $locked->status;
            $now = now();
            $package = MailPackage::query()->findOrFail($locked->mail_package_id);
            $subscription = MailSubscription::query()->where('site_id', $locked->site_id)->lockForUpdate()->first();

            $continues = $subscription instanceof MailSubscription
                && $subscription->isLive()
                && (string) $subscription->mail_package_id === (string) $package->id
                && $subscription->current_period_end !== null;

            $start = $continues ? ($subscription->current_period_start ?? $now) : $now;
            $base = $continues ? $subscription->current_period_end->copy() : $now->copy();
            $end = self::addPeriod($base, (string) $locked->period);

            $subscription ??= new MailSubscription(['site_id' => $locked->site_id]);
            $subscription->fill([
                'mail_package_id' => $package->id,
                'period' => $locked->period,
                'status' => MailSubscription::STATUS_ACTIVE,
                'current_period_start' => $start,
                'current_period_end' => $end,
                'canceled_at' => null,
            ])->save();

            Site::query()->whereKey($locked->site_id)->update(['mail_package_id' => $package->id]);

            $payload = $provider['provider_payload'] ?? null;
            $locked->forceFill([
                'status' => MailOrder::STATUS_PAID,
                'paid_at' => $now,
                'confirmed_by' => $actor?->id,
                'provider_payment_id' => filled($provider['provider_payment_id'] ?? null)
                    ? mb_substr((string) $provider['provider_payment_id'], 0, 64)
                    : $locked->provider_payment_id,
                'provider_payload' => is_array($payload)
                    ? array_merge(is_array($locked->provider_payload) ? $locked->provider_payload : [], ['result' => $payload])
                    : $locked->provider_payload,
            ])->save();

            return ['order' => $locked, 'from' => $from, 'package' => $package, 'end' => $end];
        });

        if ($result === null) {
            $order->refresh();

            return false;
        }

        /** @var MailOrder $paid */
        $paid = $result['order'];
        $order->setRawAttributes($paid->getAttributes(), true);

        ApplyComailPackageJob::dispatch((string) $paid->site_id);

        $site = Site::withTrashed()->find($paid->site_id);
        if ($site instanceof Site) {
            $this->audit($site, 'comail.order_paid', [
                'order_id' => $paid->id,
                'reference' => $paid->reference,
                'method' => $paid->method,
                'amount' => (string) $paid->amount,
                'currency' => $paid->currency,
                'package' => $result['package']->slug,
                'period' => $paid->period,
                'kind' => $paid->kind,
                'from_status' => $result['from'],
                'period_end' => $result['end']->toIso8601String(),
            ], $actor, $ip, $source);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function markFailed(MailOrder $order, string $reason, ?array $payload, string $source, ?User $actor = null, ?string $ip = null): bool
    {
        $changed = DB::transaction(function () use ($order, $reason, $payload): bool {
            $locked = MailOrder::query()->whereKey($order->getKey())->lockForUpdate()->first();
            if (! $locked instanceof MailOrder || ! $locked->isOpen()) {
                return false;
            }

            $locked->forceFill([
                'status' => MailOrder::STATUS_FAILED,
                'notes' => self::appendNote($locked->notes, $reason),
                'provider_payload' => $payload !== null
                    ? array_merge(is_array($locked->provider_payload) ? $locked->provider_payload : [], ['result' => $payload])
                    : $locked->provider_payload,
            ])->save();

            return true;
        });

        $order->refresh();
        if ($changed) {
            $this->auditOrder($order, 'comail.order_failed', ['reason' => mb_substr($reason, 0, 255)], $actor, $ip, $source);
        }

        return $changed;
    }

    /**
     * Customer (pending / awaiting_transfer only) or ops (any open order) cancels.
     */
    public function cancel(MailOrder $order, string $source, ?User $actor = null, ?string $ip = null, ?string $reason = null): void
    {
        $allowed = $source === 'ops' ? $order->isOpen() : $order->isCancellable();
        if (! $allowed) {
            throw new ComailException('order_not_cancellable', 'Bu sipariş artık iptal edilemez.');
        }

        $order->forceFill([
            'status' => MailOrder::STATUS_CANCELED,
            'notes' => $reason !== null && $reason !== '' ? self::appendNote($order->notes, $reason) : $order->notes,
        ])->save();

        $this->auditOrder($order, 'comail.order_canceled', array_filter(['reason' => $reason]), $actor, $ip, $source);
    }

    public function reportTransfer(MailOrder $order, ?string $note, ?string $ip = null): void
    {
        if ($order->method !== MailOrder::METHOD_EFT
            || ! in_array($order->status, [MailOrder::STATUS_AWAITING_TRANSFER, MailOrder::STATUS_TRANSFER_REPORTED], true)) {
            throw ComailException::validation('Bu sipariş için havale bildirimi yapılamaz.');
        }

        $note = trim((string) $note);
        $order->forceFill([
            'status' => MailOrder::STATUS_TRANSFER_REPORTED,
            'transfer_reported_at' => now(),
            'notes' => $note !== '' ? self::appendNote($order->notes, 'Müşteri: '.$note) : $order->notes,
        ])->save();

        $this->auditOrder($order, 'comail.transfer_reported', ['has_note' => $note !== ''], null, $ip, 'cms');
    }

    /**
     * Card checkouts after 30 minutes and unreported EFT orders after 7 days.
     */
    public function expireOrders(): int
    {
        $count = 0;
        MailOrder::query()
            ->whereIn('status', [MailOrder::STATUS_PENDING, MailOrder::STATUS_AWAITING_TRANSFER])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->each(function (MailOrder $order) use (&$count): void {
                $order->forceFill(['status' => MailOrder::STATUS_EXPIRED])->save();
                $this->auditOrder($order, 'comail.order_expired', [], null, null, 'schedule');
                $count++;
            });

        return $count;
    }

    /**
     * active → grace once current_period_end passed; grace → expired after
     * grace_days: the site falls back to the default package (limits re-applied,
     * nothing deleted; see ComailLimits::safeDomainAttributes).
     *
     * @return array{grace: int, expired: int}
     */
    public function expireSubscriptions(): array
    {
        $now = now();
        $graceDays = $this->settings()->graceDays();
        $grace = 0;
        $expired = 0;

        MailSubscription::query()
            ->where('status', MailSubscription::STATUS_ACTIVE)
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', $now)
            ->orderBy('id')
            ->each(function (MailSubscription $subscription) use (&$grace): void {
                $subscription->forceFill(['status' => MailSubscription::STATUS_GRACE])->save();
                $this->auditSubscription($subscription, 'comail.subscription_grace');
                $grace++;
            });

        MailSubscription::query()
            ->where('status', MailSubscription::STATUS_GRACE)
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', $now->copy()->subDays($graceDays))
            ->orderBy('id')
            ->each(function (MailSubscription $subscription) use (&$expired): void {
                $subscription->forceFill(['status' => MailSubscription::STATUS_EXPIRED])->save();

                // Only when the site still runs on this subscription's package (ops may have set another one).
                Site::query()
                    ->whereKey($subscription->site_id)
                    ->where('mail_package_id', $subscription->mail_package_id)
                    ->update(['mail_package_id' => null]);

                ApplyComailPackageJob::dispatch((string) $subscription->site_id);
                $this->auditSubscription($subscription, 'comail.subscription_expired');
                $expired++;
            });

        return ['grace' => $grace, 'expired' => $expired];
    }

    /**
     * Where the customer goes after the checkout: the order's return_url with
     * `comail_order` and `status`, or null for Plane's own result page.
     */
    public function returnUrl(MailOrder $order, string $status): ?string
    {
        $url = (string) $order->return_url;
        if ($url === '') {
            return null;
        }

        $fragment = '';
        if (str_contains($url, '#')) {
            [$url, $fragment] = explode('#', $url, 2);
            $fragment = '#'.$fragment;
        }

        return $url.(str_contains($url, '?') ? '&' : '?')
            .http_build_query(['comail_order' => $order->id, 'status' => $status])
            .$fragment;
    }

    /**
     * return_url must be https and on one of the site's own hosts.
     */
    public static function allowedReturnUrl(Site $site, mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '' || strlen($url) > 500) {
            return null;
        }

        $url = trim($url);
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || ! is_string($parts['host'] ?? null)) {
            return null;
        }

        $host = strtolower(rtrim($parts['host'], '.'));

        return in_array($host, self::siteHosts($site), true) ? $url : null;
    }

    /**
     * @return list<string>
     */
    public static function siteHosts(Site $site): array
    {
        $hosts = $site->domains()->pluck('domain')
            ->map(fn (mixed $domain): string => CloudflareHostname::host((string) $domain))
            ->all();
        $hosts[] = CloudflareHostname::normalize((string) $site->primary_domain);
        if (filled($site->temporary_domain)) {
            $hosts[] = CloudflareHostname::host((string) $site->temporary_domain);
        }
        $agentHost = parse_url((string) $site->agent_base_url, PHP_URL_HOST);
        if (is_string($agentHost)) {
            $hosts[] = strtolower($agentHost);
        }

        $out = [];
        foreach ($hosts as $host) {
            $host = strtolower(trim($host));
            if ($host === '') {
                continue;
            }
            $out[] = $host;
            $out[] = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.'.$host;
        }

        return array_values(array_unique($out));
    }

    /**
     * Site API shape of an order.
     *
     * @return array<string, mixed>
     */
    public function orderRow(MailOrder $order): array
    {
        $order->loadMissing('mailPackage');
        $package = $order->mailPackage;

        return [
            'id' => (string) $order->id,
            'reference' => (string) $order->reference,
            'package' => $package instanceof MailPackage ? ['slug' => (string) $package->slug, 'name' => (string) $package->name] : null,
            'package_slug' => $package?->slug,
            'package_name' => $package?->name,
            'period' => (string) $order->period,
            'kind' => (string) $order->kind,
            'amount' => round((float) $order->amount, 2),
            'currency' => (string) $order->currency,
            'method' => (string) $order->method,
            'status' => (string) $order->status,
            'created_at' => $order->created_at?->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'expires_at' => $order->expires_at?->toIso8601String(),
        ];
    }

    /**
     * 1.234,50 TL
     */
    public static function money(mixed $amount, ?string $currency): string
    {
        $currency = strtoupper((string) ($currency ?: 'TRY'));

        return number_format((float) $amount, 2, ',', '.').' '.($currency === 'TRY' ? 'TL' : $currency);
    }

    public static function addPeriod(CarbonInterface $from, string $period): CarbonInterface
    {
        return $period === MailOrder::PERIOD_YEARLY
            ? $from->copy()->addYearNoOverflow()
            : $from->copy()->addMonthNoOverflow();
    }

    /**
     * @param  array<string, mixed>  $after
     */
    public function auditOrder(MailOrder $order, string $action, array $after, ?User $actor, ?string $ip, string $source): void
    {
        $site = Site::withTrashed()->find($order->site_id);
        if (! $site instanceof Site) {
            return;
        }

        $this->audit($site, $action, [
            'order_id' => $order->id,
            'reference' => $order->reference,
            'method' => $order->method,
            'status' => $order->status,
        ] + $after, $actor, $ip, $source);
    }

    /**
     * @param  array<string, mixed>  $after
     */
    public function audit(Site $site, string $action, array $after, ?User $actor, ?string $ip, string $source): void
    {
        AuditLog::query()->create([
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $site->getMorphClass(),
            'subject_id' => $site->id,
            'after' => $after + ['site_id' => $site->id, 'source' => $source],
            'ip' => $ip,
        ]);
    }

    private function auditSubscription(MailSubscription $subscription, string $action): void
    {
        $site = Site::withTrashed()->find($subscription->site_id);
        if ($site instanceof Site) {
            $this->audit($site, $action, [
                'subscription_id' => $subscription->id,
                'package_id' => $subscription->mail_package_id,
                'period_end' => $subscription->current_period_end?->toIso8601String(),
            ], null, null, 'schedule');
        }
    }

    private static function appendNote(?string $notes, string $line): string
    {
        $line = mb_substr(trim($line), 0, 500);

        return trim(((string) $notes)."\n".now()->format('Y-m-d H:i').' '.$line);
    }
}
