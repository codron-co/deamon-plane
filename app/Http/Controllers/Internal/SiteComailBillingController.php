<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\MailOrder;
use App\Models\MailPackage;
use App\Models\MailServer;
use App\Models\Site;
use App\Services\Billing\MailBilling;
use App\Services\Billing\PayLink;
use App\Services\Comail\ComailDirectory;
use App\Services\Comail\ComailException;
use App\Services\Comail\ComailLimits;
use App\Services\Comail\MailcowClient;
use App\Services\Comail\MailcowException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * CMS → Plane CoMail billing (`/internal/site/v1/comail/billing*`), same
 * signed per-site auth as the rest of the CoMail site API. Card orders get a
 * hosted checkout link on Plane; EFT orders get the bank details and the
 * reference to write into the transfer note. Errors: `{ok:false, error, message}`.
 */
class SiteComailBillingController extends Controller
{
    private const ORDER_HISTORY = 20;

    public function __construct(
        private readonly MailBilling $billing,
        private readonly ComailLimits $limits,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return $this->run($request, function (Site $site, MailServer $server): JsonResponse {
            $settings = $this->billing->settings();
            $package = $this->limits->package($site);
            $state = $this->billing->subscriptionState($site);

            $usage = null;
            try {
                $usage = $this->limits->usage(new ComailDirectory($site, MailcowClient::forServer($server)));
            } catch (MailcowException $exception) {
                // Billing stays usable while mailcow is unreachable; only over_limit is unknown.
                Log::warning('site.comail_billing_usage_failed', ['site_id' => $site->id, 'error' => $exception->error]);
            }

            return $this->ok([
                'current' => [
                    'package' => $package->toLimitsArray() + ['description' => $package->description],
                    'period' => $state['period'],
                    'status' => $state['status'],
                    'period_start' => $state['period_start'],
                    'period_end' => $state['period_end'],
                    'grace_ends_at' => $state['grace_ends_at'],
                    'auto_renew' => $state['auto_renew'],
                    'over_limit' => $usage !== null && $this->limits->overLimit($package, $usage),
                    'usage' => $usage,
                ],
                'packages' => $this->billing->packages()
                    ->map(fn (MailPackage $row): array => $this->packageRow($row, $package))
                    ->values()
                    ->all(),
                'methods' => $settings->methods(),
                'vat_included' => (bool) $settings->vat_included,
                'bank' => $settings->bank(),
                'distance_sales_url' => filled($settings->distance_sales_url) ? (string) $settings->distance_sales_url : null,
                'orders' => $site->mailOrders()->with('mailPackage')->latest()->latest('id')->limit(self::ORDER_HISTORY)->get()
                    ->map(fn (MailOrder $order): array => $this->billing->orderRow($order))
                    ->values()
                    ->all(),
            ]);
        });
    }

    public function store(Request $request): JsonResponse
    {
        return $this->run($request, function (Site $site) use ($request): JsonResponse {
            $data = $this->validated($request, [
                'package' => ['required', 'string', 'max:64'],
                'period' => ['required', 'string', Rule::in(MailOrder::PERIODS)],
                'method' => ['required', 'string', Rule::in(MailOrder::METHODS)],
                'billing' => ['required', 'array'],
                'billing.name' => ['required', 'string', 'max:160'],
                'billing.company' => ['nullable', 'string', 'max:160'],
                'billing.tckn_or_vkn' => ['required', 'string', 'regex:/\A\d{10,11}\z/'],
                'billing.tax_office' => ['nullable', 'string', 'max:120'],
                'billing.address' => ['required', 'string', 'max:500'],
                'billing.city' => ['required', 'string', 'max:80'],
                'billing.district' => ['nullable', 'string', 'max:80'],
                'billing.phone' => ['required', 'string', 'max:32', 'regex:/\A[0-9 +()\-]{7,32}\z/'],
                'billing.email' => ['required', 'string', 'email', 'max:190'],
                'return_url' => ['nullable', 'string', 'max:500'],
            ]);

            $billing = [];
            foreach (['name', 'company', 'tckn_or_vkn', 'tax_office', 'address', 'city', 'district', 'phone', 'email'] as $key) {
                $value = trim((string) ($data['billing'][$key] ?? ''));
                $billing[$key] = $value !== '' ? $value : null;
            }

            $order = $this->billing->createOrder($site, [
                'package' => strtolower(trim((string) $data['package'])),
                'period' => (string) $data['period'],
                'method' => (string) $data['method'],
                'billing' => $billing,
                'return_url' => $data['return_url'] ?? null,
            ], $request->ip());

            $row = $this->billing->orderRow($order);
            if ($order->isCard()) {
                return $this->ok(['order' => $row, 'checkout_url' => PayLink::url($order)], 201);
            }

            return $this->ok([
                'order' => $row,
                'bank' => $this->billing->settings()->bank(),
                'reference' => (string) $order->reference,
                'amount' => round((float) $order->amount, 2),
                'currency' => (string) $order->currency,
                'expires_at' => $order->expires_at?->toIso8601String(),
            ], 201);
        });
    }

    public function transferReported(Request $request, string $id): JsonResponse
    {
        return $this->run($request, function (Site $site) use ($request, $id): JsonResponse {
            $data = $this->validated($request, ['note' => ['nullable', 'string', 'max:500']]);
            $order = $this->ownedOrder($site, $id);
            $this->billing->reportTransfer($order, $data['note'] ?? null, $request->ip());

            return $this->ok($this->billing->orderRow($order->fresh() ?? $order), key: 'order');
        });
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        return $this->run($request, function (Site $site) use ($request, $id): JsonResponse {
            $order = $this->ownedOrder($site, $id);
            $this->billing->cancel($order, 'cms', null, $request->ip());

            return $this->ok($this->billing->orderRow($order->fresh() ?? $order), key: 'order');
        });
    }

    // ── Plumbing ────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function packageRow(MailPackage $package, MailPackage $current): array
    {
        return [
            'slug' => (string) $package->slug,
            'name' => (string) $package->name,
            'description' => $package->description,
            'mailbox_limit' => (int) $package->mailbox_limit,
            'storage_mb' => (int) $package->storage_mb,
            'mailbox_max_quota_mb' => (int) $package->mailbox_max_quota_mb,
            'alias_limit' => (int) $package->alias_limit,
            'domain_limit' => $package->domain_limit === null ? null : (int) $package->domain_limit,
            'price_monthly' => $package->price_monthly === null ? null : round((float) $package->price_monthly, 2),
            'price_yearly' => $package->price_yearly === null ? null : round((float) $package->price_yearly, 2),
            'currency' => (string) ($package->currency ?: 'TRY'),
            'is_default' => (bool) $package->is_default,
            'is_current' => (string) $package->id === (string) $current->id,
        ];
    }

    private function ownedOrder(Site $site, string $id): MailOrder
    {
        $order = $site->mailOrders()->whereKey($id)->first();
        if (! $order instanceof MailOrder) {
            throw ComailException::notFound('Sipariş bulunamadı.');
        }

        return $order;
    }

    /**
     * @param  Closure(Site, MailServer): JsonResponse  $handler
     */
    private function run(Request $request, Closure $handler): JsonResponse
    {
        $site = $request->attributes->get('mailSite');
        if (! $site instanceof Site) {
            abort(401);
        }

        try {
            $server = MailServer::comail();
            if (! $server instanceof MailServer) {
                throw ComailException::notConfigured();
            }

            return $handler($site, $server);
        } catch (ComailException $exception) {
            return $this->error($exception->error, $exception->getMessage(), $exception->status);
        } catch (MailcowException $exception) {
            return $exception->isUnreachable()
                ? $this->error('provider_unreachable', $exception->turkishMessage(), 502)
                : $this->error('provider_error', $exception->turkishMessage(), 422);
        }
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules, self::MESSAGES, self::ATTRIBUTES);
        if ($validator->fails()) {
            throw ComailException::validation((string) $validator->errors()->first());
        }

        return $validator->validated();
    }

    private const ATTRIBUTES = [
        'package' => 'Paket',
        'period' => 'Dönem',
        'method' => 'Ödeme yöntemi',
        'billing' => 'Fatura bilgileri',
        'billing.name' => 'Ad soyad',
        'billing.company' => 'Firma',
        'billing.tckn_or_vkn' => 'TCKN / VKN',
        'billing.tax_office' => 'Vergi dairesi',
        'billing.address' => 'Adres',
        'billing.city' => 'İl',
        'billing.district' => 'İlçe',
        'billing.phone' => 'Telefon',
        'billing.email' => 'E-posta',
        'return_url' => 'Dönüş adresi',
        'note' => 'Not',
    ];

    private const MESSAGES = [
        'required' => ':attribute zorunlu.',
        'string' => ':attribute metin olmalı.',
        'array' => ':attribute eksik.',
        'email' => ':attribute geçerli bir e-posta adresi olmalı.',
        'in' => ':attribute geçersiz.',
        'billing.tckn_or_vkn.regex' => 'TCKN 11, VKN 10 haneli olmalı.',
        'regex' => ':attribute biçimi geçersiz.',
        'max' => [
            'string' => ':attribute en fazla :max karakter olabilir.',
        ],
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    private function ok(array $data, int $status = 200, ?string $key = null): JsonResponse
    {
        $body = ['ok' => true] + $data;
        if ($key !== null) {
            $body[$key] = $data;
        }

        return response()->json($body, $status);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => $code, 'message' => $message], $status);
    }
}
