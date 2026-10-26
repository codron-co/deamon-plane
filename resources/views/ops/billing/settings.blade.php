@extends('layouts.ops')

@php
    /** @var \App\Models\BillingSetting $settings */
    $disabled = ! $canEdit;
    $secretHint = fn (string $attribute): string => $settings->hasSecret($attribute) ? __('billing.settings.secret_saved') : __('billing.settings.secret_missing');
@endphp

@section('title', __('billing.settings.title'))

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.billing.orders.index') }}">{{ __('billing.nav.orders') }}</a>
@endsection

@section('content')
    <p class="page-lede">{{ __('billing.settings.lede') }}</p>

    <form method="POST" action="{{ route('ops.billing.settings.update') }}" class="ops-form ops-form-stack" data-ops-pending>
        @csrf
        @method('PUT')

        @if ($errors->any())
            <ul class="ops-alert-list" role="alert">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        <article class="site-card" data-billing-provider="iyzico">
            <div class="site-card-head">
                <h2>{{ __('billing.settings.iyzico.title') }}</h2>
                <span class="status-chip {{ $settings->iyzicoReady() ? 'status-active' : '' }}">{{ $settings->iyzicoReady() ? __('billing.settings.ready') : __('billing.settings.not_ready') }}</span>
            </div>
            <p class="field-hint">{{ __('billing.settings.iyzico.hint') }}</p>
            <div class="field">
                <label class="field-check">
                    <input type="hidden" name="iyzico_enabled" value="0">
                    <input type="checkbox" name="iyzico_enabled" value="1" @checked(old('iyzico_enabled', $settings->iyzico_enabled)) @disabled($disabled)>
                    <span>{{ __('billing.settings.iyzico.enabled') }}</span>
                </label>
            </div>
            <div class="field">
                <label class="field-check">
                    <input type="hidden" name="iyzico_sandbox" value="0">
                    <input type="checkbox" name="iyzico_sandbox" value="1" @checked(old('iyzico_sandbox', $settings->iyzico_sandbox)) @disabled($disabled)>
                    <span>{{ __('billing.settings.iyzico.sandbox') }}</span>
                </label>
            </div>
            <div class="field">
                <label class="field-label" for="billing-iyzico-api-key">{{ __('billing.settings.iyzico.api_key') }}</label>
                <p class="field-hint">{{ $secretHint('iyzico_api_key') }}</p>
                <input id="billing-iyzico-api-key" class="field-input" type="password" name="iyzico_api_key" value="" autocomplete="new-password" @disabled($disabled)>
            </div>
            <div class="field">
                <label class="field-label" for="billing-iyzico-secret">{{ __('billing.settings.iyzico.secret') }}</label>
                <p class="field-hint">{{ $secretHint('iyzico_secret') }}</p>
                <input id="billing-iyzico-secret" class="field-input" type="password" name="iyzico_secret" value="" autocomplete="new-password" @disabled($disabled)>
            </div>
            <p class="field-hint">{{ __('billing.settings.iyzico.callback') }} <code>{{ route('pay.callback.iyzico') }}</code></p>
        </article>

        <article class="site-card" data-billing-provider="paytr">
            <div class="site-card-head">
                <h2>{{ __('billing.settings.paytr.title') }}</h2>
                <span class="status-chip {{ $settings->paytrReady() ? 'status-active' : '' }}">{{ $settings->paytrReady() ? __('billing.settings.ready') : __('billing.settings.not_ready') }}</span>
            </div>
            <p class="field-hint">{{ __('billing.settings.paytr.hint') }}</p>
            <p class="field-hint">{{ __('billing.settings.paytr.notify_url') }} <code>{{ route('pay.callback.paytr') }}</code></p>
            <div class="field">
                <label class="field-check">
                    <input type="hidden" name="paytr_enabled" value="0">
                    <input type="checkbox" name="paytr_enabled" value="1" @checked(old('paytr_enabled', $settings->paytr_enabled)) @disabled($disabled)>
                    <span>{{ __('billing.settings.paytr.enabled') }}</span>
                </label>
            </div>
            <div class="field">
                <label class="field-check">
                    <input type="hidden" name="paytr_test_mode" value="0">
                    <input type="checkbox" name="paytr_test_mode" value="1" @checked(old('paytr_test_mode', $settings->paytr_test_mode)) @disabled($disabled)>
                    <span>{{ __('billing.settings.paytr.test_mode') }}</span>
                </label>
            </div>
            <div class="field">
                <label class="field-label" for="billing-paytr-merchant-id">{{ __('billing.settings.paytr.merchant_id') }}</label>
                <input id="billing-paytr-merchant-id" class="field-input" type="text" inputmode="numeric" name="paytr_merchant_id" value="{{ old('paytr_merchant_id', $settings->paytr_merchant_id) }}" autocomplete="off" @disabled($disabled)>
            </div>
            <div class="field">
                <label class="field-label" for="billing-paytr-merchant-key">{{ __('billing.settings.paytr.merchant_key') }}</label>
                <p class="field-hint">{{ $secretHint('paytr_merchant_key') }}</p>
                <input id="billing-paytr-merchant-key" class="field-input" type="password" name="paytr_merchant_key" value="" autocomplete="new-password" @disabled($disabled)>
            </div>
            <div class="field">
                <label class="field-label" for="billing-paytr-merchant-salt">{{ __('billing.settings.paytr.merchant_salt') }}</label>
                <p class="field-hint">{{ $secretHint('paytr_merchant_salt') }}</p>
                <input id="billing-paytr-merchant-salt" class="field-input" type="password" name="paytr_merchant_salt" value="" autocomplete="new-password" @disabled($disabled)>
            </div>
        </article>

        <article class="site-card" data-billing-provider="eft">
            <div class="site-card-head">
                <h2>{{ __('billing.settings.eft.title') }}</h2>
                <span class="status-chip {{ $settings->eftReady() ? 'status-active' : '' }}">{{ $settings->eftReady() ? __('billing.settings.ready') : __('billing.settings.not_ready') }}</span>
            </div>
            <p class="field-hint">{{ __('billing.settings.eft.hint') }}</p>
            <div class="field">
                <label class="field-check">
                    <input type="hidden" name="eft_enabled" value="0">
                    <input type="checkbox" name="eft_enabled" value="1" @checked(old('eft_enabled', $settings->eft_enabled)) @disabled($disabled)>
                    <span>{{ __('billing.settings.eft.enabled') }}</span>
                </label>
            </div>
            @foreach (['bank_name' => 120, 'account_holder' => 160] as $field => $max)
                <div class="field">
                    <label class="field-label" for="billing-{{ $field }}">{{ __('billing.settings.eft.'.$field) }}</label>
                    <input id="billing-{{ $field }}" class="field-input" type="text" name="{{ $field }}" value="{{ old($field, $settings->{$field}) }}" maxlength="{{ $max }}" @disabled($disabled)>
                </div>
            @endforeach
            <div class="field">
                <label class="field-label" for="billing-iban">{{ __('billing.settings.eft.iban') }}</label>
                <input id="billing-iban" class="field-input" type="text" name="iban" value="{{ old('iban', filled($settings->iban) ? \App\Models\BillingSetting::formatIban((string) $settings->iban) : '') }}" maxlength="42" autocomplete="off" spellcheck="false" @disabled($disabled)>
            </div>
            <div class="field">
                <label class="field-label" for="billing-eft-note">{{ __('billing.settings.eft.note') }}</label>
                <textarea id="billing-eft-note" class="field-input" name="eft_note" rows="2" maxlength="500" @disabled($disabled)>{{ old('eft_note', $settings->eft_note) }}</textarea>
            </div>
        </article>

        <article class="site-card">
            <h2>{{ __('billing.settings.general.title') }}</h2>
            <div class="field">
                <label class="field-check">
                    <input type="hidden" name="vat_included" value="0">
                    <input type="checkbox" name="vat_included" value="1" @checked(old('vat_included', $settings->vat_included)) @disabled($disabled)>
                    <span>{{ __('billing.settings.general.vat_included') }}</span>
                </label>
            </div>
            <div class="field">
                <label class="field-label" for="billing-grace-days">{{ __('billing.settings.general.grace_days') }}</label>
                <p class="field-hint">{{ __('billing.settings.general.grace_hint') }}</p>
                <input id="billing-grace-days" class="field-input" type="number" min="0" max="90" name="grace_days" value="{{ old('grace_days', $settings->grace_days ?? 7) }}" required @disabled($disabled)>
            </div>
            <div class="field">
                <label class="field-label" for="billing-distance-sales">{{ __('billing.settings.general.distance_sales_url') }}</label>
                <input id="billing-distance-sales" class="field-input" type="url" name="distance_sales_url" value="{{ old('distance_sales_url', $settings->distance_sales_url) }}" maxlength="500" placeholder="https://" @disabled($disabled)>
            </div>
        </article>

        @if ($canEdit)
            <div class="form-actions">
                <button type="submit" class="btn btn-primary" data-pending-label="{{ __('ops.actions.working') }}">{{ __('billing.settings.save') }}</button>
            </div>
        @else
            <p class="field-hint">{{ __('ops.super_admin_only') }}</p>
        @endif
    </form>
@endsection
