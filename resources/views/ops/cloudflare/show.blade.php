@extends('layouts.ops')

@section('title', __('cloudflare.title'))

@section('content')
    <p class="page-lede">{{ __('cloudflare.lede') }}</p>

    <section class="ops-panel" aria-labelledby="cf-permissions-heading">
        <h2 id="cf-permissions-heading">{{ __('cloudflare.permissions') }}</h2>
        <p>{{ __('cloudflare.permissions_lede') }}</p>
        <ol class="ops-checklist">
            <li>{{ __('cloudflare.permissions_items.resource') }}</li>
            <li>{{ __('cloudflare.permissions_items.dns') }}</li>
            <li>{{ __('cloudflare.permissions_items.zone') }}</li>
        </ol>
        <p class="field-hint">{{ __('cloudflare.permissions_items.template') }}</p>
        <p class="field-hint">{{ __('cloudflare.not_required') }}</p>
    </section>

    <section class="settings-panel" aria-labelledby="cf-connection-heading">
        <h2 id="cf-connection-heading">{{ __('cloudflare.connection') }}</h2>
        <form method="POST" action="{{ route('ops.cloudflare.update') }}" class="ops-form settings-form">
            @csrf

            <div class="field">
                <label class="field-label" for="cf-account-id">{{ __('cloudflare.fields.account_id') }}</label>
                <p class="field-hint">{{ __('cloudflare.fields.account_id_hint') }}</p>
                <input
                    id="cf-account-id"
                    class="field-input"
                    type="text"
                    name="account_id"
                    value="{{ old('account_id', $settings->account_id) }}"
                    maxlength="32"
                    autocomplete="off"
                    spellcheck="false"
                    @disabled(! $canWrite)
                    @required($canWrite)
                >
                @error('account_id')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label class="field-label" for="cf-api-token">{{ __('cloudflare.fields.api_token') }}</label>
                <p class="field-hint">
                    {{ $hasToken ? __('cloudflare.fields.token_saved') : __('cloudflare.fields.token_hint') }}
                </p>
                <input
                    id="cf-api-token"
                    class="field-input"
                    type="password"
                    name="api_token"
                    value=""
                    placeholder="{{ $hasToken ? '••••••••' : 'Cloudflare API token' }}"
                    autocomplete="new-password"
                    @disabled(! $canWrite)
                >
                @error('api_token')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label class="field-label" for="cf-origin-ipv4">{{ __('cloudflare.fields.origin_ipv4') }}</label>
                <p class="field-hint">{{ __('cloudflare.fields.origin_ipv4_hint') }}</p>
                <input
                    id="cf-origin-ipv4"
                    class="field-input"
                    type="text"
                    name="origin_ipv4"
                    value="{{ old('origin_ipv4', $settings->origin_ipv4 ?: config('ops.cloudflare.default_origin_ipv4')) }}"
                    autocomplete="off"
                    spellcheck="false"
                    @disabled(! $canWrite)
                    @required($canWrite)
                >
                @error('origin_ipv4')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label class="field-check">
                    <input
                        type="checkbox"
                        name="mail_template_enabled"
                        value="1"
                        @checked(old('mail_template_enabled', $settings->mail_template_enabled ?? true))
                        @disabled(! $canWrite)
                    >
                    <span>{{ __('cloudflare.fields.mail') }}</span>
                </label>
                <p class="field-hint">{{ __('cloudflare.fields.mail_hint') }}</p>
            </div>

            @if ($canWrite)
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">{{ __('cloudflare.save') }}</button>
                    <button type="submit" class="btn btn-secondary" formaction="{{ route('ops.cloudflare.test') }}">{{ __('cloudflare.test') }}</button>
                </div>
            @else
                <p class="field-hint">{{ __('cloudflare.readonly') }}</p>
            @endif
        </form>
    </section>

    <section class="ops-panel" aria-labelledby="cf-probe-heading">
        <h2 id="cf-probe-heading">{{ __('cloudflare.probe.title') }}</h2>
        @php
            $probe = is_array($settings->last_probe_payload) ? $settings->last_probe_payload : [];
            $missing = is_array($probe['missing'] ?? null) ? $probe['missing'] : [];
        @endphp
        @if ($settings->last_probe_at)
            <p>
                <time datetime="{{ $settings->last_probe_at->toIso8601String() }}">
                    {{ $settings->last_probe_at->toDateTimeString() }}
                </time>
            </p>
            @if (($probe['dns_unverified'] ?? false) === true)
                <p class="ops-alert ops-alert-warning" role="status">{{ __('cloudflare.probe.unverified_dns') }}</p>
            @elseif ($missing !== [])
                <p class="ops-alert ops-alert-warning" role="status">{{ __('cloudflare.probe.partial', ['missing' => implode(', ', $missing)]) }}</p>
            @else
                <p>{{ __('cloudflare.probe.ok') }}</p>
            @endif
        @else
            <p class="field-hint">{{ __('cloudflare.probe.never') }}</p>
        @endif
    </section>
@endsection
