@extends('pay.layout')

@php
    /** @var \App\Models\MailOrder|null $order */
    $tone = match ($state) {
        'paid' => 'is-success',
        'failed', 'invalid', 'provider_error', 'unavailable' => 'is-danger',
        default => '',
    };
@endphp

@section('title', __('billing.pay.results.'.$state.'.title'))

@section('content')
    <h1 data-pay-state="{{ $state }}">{{ __('billing.pay.results.'.$state.'.title') }}</h1>
    <p class="pay-lede">{{ __('billing.pay.results.'.$state.'.lede') }}</p>

    @if ($order)
        <h2>{{ __('billing.order.summary') }}</h2>
        <dl class="pay-group">
            <div class="pay-row"><dt>{{ __('billing.pay.summary.package') }}</dt><dd>{{ $order->mailPackage?->name }}</dd></div>
            <div class="pay-row"><dt>{{ __('billing.pay.summary.period') }}</dt><dd>{{ __('billing.periods.'.$order->period) }}</dd></div>
            <div class="pay-row"><dt>{{ __('billing.pay.summary.reference') }}</dt><dd class="pay-mono">{{ $order->reference }}</dd></div>
            <div class="pay-row"><dt>{{ __('billing.pay.summary.amount') }}</dt><dd>{{ \App\Services\Billing\MailBilling::money($order->amount, $order->currency) }}</dd></div>
            <div class="pay-row"><dt>{{ __('billing.order.facts.status') }}</dt><dd class="pay-state {{ $tone }}">{{ __('billing.statuses.'.$order->status) }}</dd></div>
        </dl>
    @endif

    @if ($backUrl)
        <div class="pay-actions">
            <a class="pay-button" href="{{ $backUrl }}" target="_top" rel="noopener">{{ __('billing.pay.back') }}</a>
        </div>
    @endif
@endsection
