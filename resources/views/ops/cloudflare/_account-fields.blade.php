@php
    $canWrite = $canWrite ?? false;
    $requireToken = $requireToken ?? false;
@endphp

<div class="field">
    <label class="field-label" for="cf-name">{{ __('cloudflare.fields.name') }}</label>
    <input id="cf-name" class="field-input" type="text" name="name" value="{{ old('name', $account->name) }}" maxlength="120" required @disabled(! $canWrite)>
    @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="cf-account-id">{{ __('cloudflare.fields.account_id') }}</label>
    <p class="field-hint">{{ __('cloudflare.fields.account_id_hint') }}</p>
    <input id="cf-account-id" class="field-input" type="text" name="account_id" value="{{ old('account_id', $account->account_id) }}" maxlength="32" autocomplete="off" spellcheck="false" @disabled(! $canWrite) @required($canWrite)>
    @error('account_id') <p class="field-error" role="alert">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="cf-api-token">{{ __('cloudflare.fields.api_token') }}</label>
    <p class="field-hint">{{ $account->exists && $account->hasToken() ? __('cloudflare.fields.token_saved') : __('cloudflare.fields.token_hint') }}</p>
    <input id="cf-api-token" class="field-input" type="password" name="api_token" value="" placeholder="{{ $account->exists && $account->hasToken() ? '••••••••' : __('cloudflare.fields.token_placeholder') }}" autocomplete="new-password" @disabled(! $canWrite) @required($requireToken && ! ($account->exists && $account->hasToken()))>
    @error('api_token') <p class="field-error" role="alert">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="cf-wildcard-domain">{{ __('cloudflare.fields.wildcard_domain') }}</label>
    <p class="field-hint">{{ __('cloudflare.fields.wildcard_domain_hint') }}</p>
    <input
        id="cf-wildcard-domain"
        class="field-input"
        type="text"
        name="wildcard_domain"
        value="{{ old('wildcard_domain', $account->wildcard_domain ?: config('ops.cloudflare.wildcard_domain')) }}"
        maxlength="255"
        spellcheck="false"
        autocomplete="off"
        placeholder="codron.co"
        @disabled(! $canWrite)
    >
    @error('wildcard_domain') <p class="field-error" role="alert">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-check">
        <input type="hidden" name="is_enabled" value="0">
        <input type="checkbox" name="is_enabled" value="1" @checked(old('is_enabled', $account->is_enabled ?? true)) @disabled(! $canWrite)>
        <span>{{ __('cloudflare.fields.is_enabled') }}</span>
    </label>
</div>

<div class="field">
    <label class="field-check">
        <input type="hidden" name="is_default" value="0">
        <input type="checkbox" name="is_default" value="1" @checked(old('is_default', $account->is_default ?? false)) @disabled(! $canWrite)>
        <span>{{ __('cloudflare.fields.is_default') }}</span>
    </label>
    <p class="field-hint">{{ __('cloudflare.fields.is_default_hint') }}</p>
</div>
