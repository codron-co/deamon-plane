@extends('layouts.ops')

@php
    /** @var \App\Models\MailOrder $order */
    $money = \App\Services\Billing\MailBilling::money($order->amount, $order->currency);
    $billing = is_array($order->billing) ? $order->billing : [];
    $canConfirm = $canDanger && $order->method === \App\Models\MailOrder::METHOD_EFT && $order->isOpen();
    $canClose = $canWrite && $order->isOpen();
@endphp

@section('title', $order->reference)

@section('breadcrumbs')
    <a href="{{ route('ops.billing.orders.index') }}">{{ __('billing.orders.title') }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ $order->reference }}</span>
@endsection

@section('content')
    <p class="page-lede">{{ __('billing.order.lede', ['site' => $order->site?->name ?? '—', 'package' => $order->mailPackage?->name ?? '—', 'period' => mb_strtolower(__('billing.periods.'.$order->period))]) }}</p>

    <section class="ops-panel" aria-labelledby="billing-order-summary">
        <h2 id="billing-order-summary">{{ __('billing.order.summary') }} <span class="status-chip {{ $order->isPaid() ? 'status-active' : '' }}" data-billing-status="{{ $order->status }}">{{ __('billing.statuses.'.$order->status) }}</span></h2>
        <dl class="site-fact-list">
            <div>
                <dt>{{ __('billing.order.facts.site') }}</dt>
                <dd>
                    @if ($order->site)
                        <a href="{{ route('ops.sites.show', $order->site) }}#site-comail">{{ $order->site->name }}</a>
                    @else
                        {{ __('ops.none') }}
                    @endif
                </dd>
            </div>
            <div><dt>{{ __('billing.order.facts.package') }}</dt><dd>{{ $order->mailPackage?->name }}</dd></div>
            <div><dt>{{ __('billing.order.facts.period') }}</dt><dd>{{ __('billing.periods.'.$order->period) }}</dd></div>
            <div><dt>{{ __('billing.order.facts.kind') }}</dt><dd>{{ __('billing.kinds.'.$order->kind) }}</dd></div>
            <div><dt>{{ __('billing.order.facts.amount') }}</dt><dd data-billing-amount>{{ $money }}</dd></div>
            <div><dt>{{ __('billing.order.facts.method') }}</dt><dd>{{ __('billing.methods.'.$order->method) }}</dd></div>
            <div><dt>{{ __('billing.order.facts.created') }}</dt><dd>{{ $order->created_at?->format('Y-m-d H:i') }}</dd></div>
            <div><dt>{{ __('billing.order.facts.expires') }}</dt><dd>{{ $order->expires_at?->format('Y-m-d H:i') ?? __('ops.none') }}</dd></div>
            <div><dt>{{ __('billing.order.facts.paid') }}</dt><dd>{{ $order->paid_at?->format('Y-m-d H:i') ?? __('ops.none') }}</dd></div>
            @if ($order->transfer_reported_at)
                <div><dt>{{ __('billing.order.facts.transfer_reported') }}</dt><dd>{{ $order->transfer_reported_at->format('Y-m-d H:i') }}</dd></div>
            @endif
            @if ($order->confirmer)
                <div><dt>{{ __('billing.order.facts.confirmed_by') }}</dt><dd>{{ $order->confirmer->name }}</dd></div>
            @endif
            @if (filled($order->provider_payment_id))
                <div><dt>{{ __('billing.order.facts.provider_payment_id') }}</dt><dd><code>{{ $order->provider_payment_id }}</code></dd></div>
            @endif
        </dl>
    </section>

    <section class="ops-panel" aria-labelledby="billing-order-billing">
        <h2 id="billing-order-billing">{{ __('billing.order.billing_title') }}</h2>
        <dl class="site-fact-list">
            @foreach (['name', 'company', 'tckn_or_vkn', 'tax_office', 'address', 'district', 'city', 'phone', 'email'] as $field)
                @if (filled($billing[$field] ?? null))
                    <div><dt>{{ __('billing.order.billing_fields.'.$field) }}</dt><dd>{{ $billing[$field] }}</dd></div>
                @endif
            @endforeach
        </dl>
        @if (filled($order->notes))
            <h3>{{ __('billing.order.notes') }}</h3>
            <p class="site-note" style="white-space: pre-line">{{ $order->notes }}</p>
        @endif
    </section>

    <section class="ops-panel" aria-labelledby="billing-order-actions">
        <h2 id="billing-order-actions">{{ __('billing.order.actions.title') }}</h2>
        @if (! $canConfirm && ! $canClose)
            <p class="muted">{{ $order->isOpen() ? __('ops.super_admin_only') : __('billing.order.actions.none') }}</p>
        @else
            <div class="site-operation-line">
                @if ($canConfirm)
                    <form method="POST" action="{{ route('ops.billing.orders.confirm', $order) }}" data-ops-pending data-confirm="{{ __('billing.order.actions.confirm_body', ['reference' => $order->reference, 'amount' => $money]) }}" data-confirm-title="{{ __('billing.order.actions.confirm_title') }}" data-confirm-label="{{ __('billing.order.actions.confirm') }}">
                        @csrf
                        <button type="submit" class="btn btn-primary btn-sm" data-billing-confirm>{{ __('billing.order.actions.confirm') }}</button>
                    </form>
                @endif
                @if ($canClose)
                    <form method="POST" action="{{ route('ops.billing.orders.fail', $order) }}" data-ops-pending data-confirm="{{ __('billing.order.actions.fail_body', ['reference' => $order->reference]) }}" data-confirm-title="{{ __('billing.order.actions.fail_title') }}" data-confirm-label="{{ __('billing.order.actions.fail') }}" data-confirm-danger="true">
                        @csrf
                        <button type="submit" class="btn btn-ghost btn-sm">{{ __('billing.order.actions.fail') }}</button>
                    </form>
                    <form method="POST" action="{{ route('ops.billing.orders.cancel', $order) }}" data-ops-pending data-confirm="{{ __('billing.order.actions.cancel_body', ['reference' => $order->reference]) }}" data-confirm-title="{{ __('billing.order.actions.cancel_title') }}" data-confirm-label="{{ __('billing.order.actions.cancel') }}" data-confirm-danger="true">
                        @csrf
                        <button type="submit" class="btn btn-danger btn-sm">{{ __('billing.order.actions.cancel') }}</button>
                    </form>
                @endif
            </div>
        @endif
    </section>

    <section class="ops-panel" aria-labelledby="billing-order-activity">
        <h2 id="billing-order-activity">{{ __('billing.order.activity') }}</h2>
        @if ($activity->isEmpty())
            <p class="muted">{{ __('billing.order.no_activity') }}</p>
        @else
            <ul class="ops-alert-list">
                @foreach ($activity as $entry)
                    <li><code>{{ $entry->action }}</code> · {{ $entry->created_at?->format('Y-m-d H:i') }} · {{ $entry->actor?->name ?? ($entry->after['source'] ?? '') }}</li>
                @endforeach
            </ul>
        @endif
    </section>
@endsection
