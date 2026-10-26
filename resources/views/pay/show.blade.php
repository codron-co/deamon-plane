@extends('pay.layout')

@php
    /** @var \App\Models\MailOrder $order */
    $money = \App\Services\Billing\MailBilling::money($order->amount, $order->currency);
    $siteHost = $order->site?->primary_domain;
@endphp

@section('title', __('billing.pay.title'))

@section('content')
    <h1>{{ __('billing.pay.title') }}</h1>
    <p class="pay-lede">{{ __('billing.pay.lede') }}</p>

    <h2>{{ __('billing.order.summary') }}</h2>
    <dl class="pay-group" data-pay-summary>
        <div class="pay-row"><dt>{{ __('billing.pay.summary.package') }}</dt><dd>{{ $order->mailPackage?->name }}</dd></div>
        <div class="pay-row"><dt>{{ __('billing.pay.summary.period') }}</dt><dd>{{ __('billing.periods.'.$order->period) }}</dd></div>
        @if (filled($siteHost))
            <div class="pay-row"><dt>{{ __('billing.pay.summary.site') }}</dt><dd>{{ $siteHost }}</dd></div>
        @endif
        <div class="pay-row"><dt>{{ __('billing.pay.summary.reference') }}</dt><dd class="pay-mono" data-pay-reference>{{ $order->reference }}</dd></div>
        <div class="pay-row is-total">
            <dt>{{ __('billing.pay.summary.amount') }}</dt>
            <dd data-pay-amount>{{ $money }}<small>{{ $vatIncluded ? __('billing.pay.vat_included') : __('billing.pay.vat_excluded') }}</small></dd>
        </div>
    </dl>

    @if ($frame)
        <h2>{{ __('billing.pay.card_title') }}</h2>
        <div class="pay-frame">
            <iframe src="{{ $frame }}" id="{{ $order->method === 'paytr' ? 'paytriframe' : 'iyzico-frame' }}" title="{{ __('billing.pay.card_title') }}" scrolling="{{ $order->method === 'paytr' ? 'no' : 'yes' }}" allow="payment" data-pay-frame="{{ $order->method }}"></iframe>
        </div>
        <p class="pay-note">{{ __('billing.pay.card_note') }}</p>
    @elseif ($bank)
        <h2>{{ __('billing.pay.eft_title') }}</h2>
        <p class="pay-lede">{{ __('billing.pay.eft_lede') }}</p>
        <dl class="pay-group" data-pay-bank>
            <div class="pay-row"><dt>{{ __('billing.pay.bank_name') }}</dt><dd>{{ $bank['bank_name'] }}</dd></div>
            <div class="pay-row"><dt>{{ __('billing.pay.account_holder') }}</dt><dd>{{ $bank['account_holder'] }}</dd></div>
            <div class="pay-row">
                <dt>{{ __('billing.pay.iban') }}</dt>
                <dd><span class="pay-mono">{{ $bank['iban'] }}</span><button type="button" class="pay-copy" data-copy="{{ str_replace(' ', '', $bank['iban']) }}" data-copied-label="{{ __('billing.pay.copied') }}">{{ __('billing.pay.copy') }}</button></dd>
            </div>
            <div class="pay-row">
                <dt>{{ __('billing.pay.description') }}</dt>
                <dd><span class="pay-mono">{{ $order->reference }}</span><button type="button" class="pay-copy" data-copy="{{ $order->reference }}" data-copied-label="{{ __('billing.pay.copied') }}">{{ __('billing.pay.copy') }}</button></dd>
            </div>
        </dl>
        @if (filled($bank['note']))
            <p class="pay-note">{{ $bank['note'] }}</p>
        @endif
        @if ($order->status === \App\Models\MailOrder::STATUS_TRANSFER_REPORTED)
            <p class="pay-banner">{{ __('billing.pay.eft_reported') }}</p>
        @elseif ($order->expires_at)
            <p class="pay-note">{{ __('billing.pay.expires', ['date' => $order->expires_at->timezone(config('app.timezone'))->format('d.m.Y H:i')]) }}</p>
        @endif
    @endif

    @if ($backUrl)
        <div class="pay-actions">
            <a class="pay-link" href="{{ $backUrl }}" rel="noopener">{{ __('billing.pay.back') }}</a>
        </div>
    @endif
@endsection

@section('scripts')
    @if ($frame && $order->method === 'paytr')
        <script src="https://www.paytr.com/js/iframeResizer.min.js"></script>
        <script>if (window.iFrameResize) { window.iFrameResize({}, '#paytriframe'); }</script>
    @endif
    @if ($bank)
        <script>
            document.querySelectorAll('[data-copy]').forEach(function (button) {
                button.addEventListener('click', function () {
                    if (!navigator.clipboard) { return; }
                    navigator.clipboard.writeText(button.getAttribute('data-copy')).then(function () {
                        var label = button.textContent;
                        button.textContent = button.getAttribute('data-copied-label');
                        setTimeout(function () { button.textContent = label; }, 1600);
                    });
                });
            });
        </script>
    @endif
@endsection
