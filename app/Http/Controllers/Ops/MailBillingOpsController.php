<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BillingSetting;
use App\Models\MailOrder;
use App\Services\Billing\MailBilling;
use App\Services\Comail\ComailException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Posta → siparişler and ödeme ayarları. Confirming an EFT activates a paid
 * package without a provider check, so it needs ops.danger; cancel / mark
 * failed need ops.write. Every action is audited (no keys, no card data).
 */
class MailBillingOpsController extends Controller
{
    public function __construct(private readonly MailBilling $billing) {}

    public function index(Request $request): View|JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(MailOrder::STATUSES)],
            'method' => ['nullable', 'string', Rule::in(MailOrder::METHODS)],
            'site' => ['nullable', 'string', 'max:64'],
        ]);
        $status = (string) ($validated['status'] ?? '');
        $method = (string) ($validated['method'] ?? '');

        $query = MailOrder::query()
            ->with(['site' => fn ($sites) => $sites->withTrashed(), 'mailPackage'])
            ->when($status !== '', fn ($orders) => $orders->where('status', $status))
            ->when($method !== '', fn ($orders) => $orders->where('method', $method))
            ->when(filled($validated['site'] ?? null), fn ($orders) => $orders->where('site_id', (string) $validated['site']))
            ->latest()
            ->latest('id');

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'orders' => $query->limit(100)->get()->map(fn (MailOrder $order): array => $this->billing->orderRow($order) + [
                    'site' => $order->site?->slug,
                    'site_id' => $order->site_id,
                    'notes' => $order->notes,
                ])->values()->all(),
            ]);
        }

        return view('ops.billing.orders.index', [
            'orders' => $query->paginate(50)->withQueryString(),
            'status' => $status,
            'method' => $method,
            'filtersActive' => $status !== '' || $method !== '',
        ]);
    }

    public function show(Request $request, MailOrder $mailOrder): View
    {
        $mailOrder->load(['site' => fn ($sites) => $sites->withTrashed(), 'mailPackage', 'confirmer']);

        return view('ops.billing.orders.show', [
            'order' => $mailOrder,
            'activity' => AuditLog::query()
                ->where('subject_type', $mailOrder->site?->getMorphClass() ?? 'site')
                ->where('subject_id', $mailOrder->site_id)
                ->where('action', 'like', 'comail.%')
                ->where('after->order_id', $mailOrder->id)
                ->with('actor')
                ->latest('created_at')
                ->limit(50)
                ->get(),
            'canWrite' => $request->user()?->can('ops.write') ?? false,
            'canDanger' => $request->user()?->can('ops.danger') ?? false,
        ]);
    }

    public function confirm(Request $request, MailOrder $mailOrder): RedirectResponse
    {
        $this->authorize('ops.danger');

        if ($mailOrder->method !== MailOrder::METHOD_EFT) {
            return $this->back($mailOrder)->with('error', __('billing.order.errors.not_eft'));
        }
        if ($mailOrder->isPaid()) {
            return $this->back($mailOrder)->with('status', __('billing.order.flash.already', ['reference' => $mailOrder->reference]));
        }
        if (! $mailOrder->isOpen()) {
            return $this->back($mailOrder)->with('error', __('billing.order.errors.not_open'));
        }

        $this->billing->markPaid($mailOrder, [], 'ops', $request->user(), $request->ip());

        return $this->back($mailOrder)->with('status', __('billing.order.flash.confirmed', ['reference' => $mailOrder->reference]));
    }

    public function cancel(Request $request, MailOrder $mailOrder): RedirectResponse
    {
        $this->authorize('ops.write');
        $reason = $this->reason($request);

        try {
            $this->billing->cancel($mailOrder, 'ops', $request->user(), $request->ip(), $reason);
        } catch (ComailException) {
            return $this->back($mailOrder)->with('error', __('billing.order.errors.not_open'));
        }

        return $this->back($mailOrder)->with('status', __('billing.order.flash.canceled', ['reference' => $mailOrder->reference]));
    }

    public function fail(Request $request, MailOrder $mailOrder): RedirectResponse
    {
        $this->authorize('ops.write');
        $reason = $this->reason($request) ?? 'Plane: elle başarısız sayıldı';

        if (! $this->billing->markFailed($mailOrder, $reason, null, 'ops', $request->user(), $request->ip())) {
            return $this->back($mailOrder)->with('error', __('billing.order.errors.not_open'));
        }

        return $this->back($mailOrder)->with('status', __('billing.order.flash.failed', ['reference' => $mailOrder->reference]));
    }

    public function editSettings(Request $request): View
    {
        return view('ops.billing.settings', [
            'settings' => BillingSetting::current(),
            'canEdit' => $request->user()?->can('ops.danger') ?? false,
            'canWrite' => $request->user()?->can('ops.write') ?? false,
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        // Payment keys and the bank account: Super Admin only.
        $this->authorize('ops.danger');

        $validated = $request->validate([
            'iyzico_enabled' => ['sometimes', 'boolean'],
            'iyzico_sandbox' => ['sometimes', 'boolean'],
            'iyzico_api_key' => ['nullable', 'string', 'max:255'],
            'iyzico_secret' => ['nullable', 'string', 'max:255'],
            'paytr_enabled' => ['sometimes', 'boolean'],
            'paytr_test_mode' => ['sometimes', 'boolean'],
            'paytr_merchant_id' => ['nullable', 'string', 'max:32', 'regex:/\A[0-9]+\z/'],
            'paytr_merchant_key' => ['nullable', 'string', 'max:255'],
            'paytr_merchant_salt' => ['nullable', 'string', 'max:255'],
            'eft_enabled' => ['sometimes', 'boolean'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'account_holder' => ['nullable', 'string', 'max:160'],
            'iban' => ['nullable', 'string', 'max:42', 'regex:/\A[A-Za-z]{2}[0-9]{2}[A-Za-z0-9 ]{10,36}\z/'],
            'eft_note' => ['nullable', 'string', 'max:500'],
            'vat_included' => ['sometimes', 'boolean'],
            'grace_days' => ['required', 'integer', 'min:0', 'max:90'],
            'distance_sales_url' => ['nullable', 'url:https', 'max:500'],
        ]);

        $settings = BillingSetting::current();
        $before = $this->snapshot($settings);

        foreach (['iyzico_enabled', 'iyzico_sandbox', 'paytr_enabled', 'paytr_test_mode', 'eft_enabled', 'vat_included'] as $flag) {
            $settings->{$flag} = (bool) ($validated[$flag] ?? false);
        }
        foreach (['paytr_merchant_id', 'bank_name', 'account_holder', 'eft_note', 'distance_sales_url'] as $field) {
            $settings->{$field} = filled($validated[$field] ?? null) ? trim((string) $validated[$field]) : null;
        }
        $settings->iban = filled($validated['iban'] ?? null) ? BillingSetting::normalizeIban((string) $validated['iban']) : null;
        $settings->grace_days = (int) $validated['grace_days'];

        // Blank secret fields keep the stored value; secrets are never echoed back.
        foreach (['iyzico_api_key', 'iyzico_secret', 'paytr_merchant_key', 'paytr_merchant_salt'] as $secret) {
            if (filled($validated[$secret] ?? null)) {
                $settings->{$secret} = trim((string) $validated[$secret]);
            }
        }

        $settings->save();

        AuditLog::query()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => 'billing.settings_updated',
            'subject_type' => BillingSetting::class,
            'subject_id' => (string) $settings->id,
            'before' => $before,
            'after' => $this->snapshot($settings),
            'ip' => $request->ip(),
        ]);

        return redirect()->route('ops.billing.settings.edit')->with('status', __('billing.settings.flash.saved'));
    }

    private function reason(Request $request): ?string
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $reason = trim((string) ($validated['reason'] ?? ''));

        return $reason !== '' ? $reason : null;
    }

    private function back(MailOrder $order): RedirectResponse
    {
        return redirect()->route('ops.billing.orders.show', $order);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(BillingSetting $settings): array
    {
        return [
            'iyzico_enabled' => (bool) $settings->iyzico_enabled,
            'iyzico_sandbox' => (bool) $settings->iyzico_sandbox,
            'has_iyzico_api_key' => $settings->hasSecret('iyzico_api_key'),
            'has_iyzico_secret' => $settings->hasSecret('iyzico_secret'),
            'paytr_enabled' => (bool) $settings->paytr_enabled,
            'paytr_test_mode' => (bool) $settings->paytr_test_mode,
            'paytr_merchant_id' => $settings->paytr_merchant_id,
            'has_paytr_merchant_key' => $settings->hasSecret('paytr_merchant_key'),
            'has_paytr_merchant_salt' => $settings->hasSecret('paytr_merchant_salt'),
            'eft_enabled' => (bool) $settings->eft_enabled,
            'bank_name' => $settings->bank_name,
            'account_holder' => $settings->account_holder,
            'iban' => $settings->iban,
            'vat_included' => (bool) $settings->vat_included,
            'grace_days' => (int) $settings->grace_days,
        ];
    }
}
