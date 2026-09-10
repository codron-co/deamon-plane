@php
    $canWrite = $canWrite ?? false;
    $requireToken = $requireToken ?? false;
    $provider = old('provider', $server->provider?->value ?? 'hostinger');
@endphp

<div class="field">
    <label class="field-label" for="mail-name">{{ __('mail.fields.name') }}</label>
    <input id="mail-name" class="field-input" type="text" name="name" value="{{ old('name', $server->name) }}" maxlength="120" required @disabled(! $canWrite)>
    @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="mail-provider">{{ __('mail.fields.provider') }}</label>
    <p class="field-hint">{{ __('mail.fields.provider_hint') }}</p>
    <select id="mail-provider" name="provider" @disabled(! $canWrite) required>
        <option value="hostinger" @selected($provider === 'hostinger')>{{ __('mail.providers.hostinger') }}</option>
        <option value="mailcow" disabled>{{ __('mail.providers.mailcow') }} — {{ __('mail.coming_soon') }}</option>
    </select>
    @error('provider') <p class="field-error" role="alert">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="mail-api-token">{{ __('mail.fields.api_token') }}</label>
    <p class="field-hint">{{ $server->exists && $server->hasToken() ? __('mail.fields.token_saved') : __('mail.fields.token_hint') }}</p>
    <input id="mail-api-token" class="field-input" type="password" name="api_token" value="" placeholder="{{ $server->exists && $server->hasToken() ? '••••••••' : __('mail.fields.token_placeholder') }}" autocomplete="new-password" @disabled(! $canWrite) @required($requireToken && ! ($server->exists && $server->hasToken()))>
    @error('api_token') <p class="field-error" role="alert">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="mail-order">{{ __('mail.fields.order_id') }}</label>
    <p class="field-hint">{{ __('mail.fields.order_hint') }}</p>
    <input id="mail-order" class="field-input" type="text" name="hostinger_order_id" value="{{ old('hostinger_order_id', $server->hostinger_order_id) }}" maxlength="64" autocomplete="off" spellcheck="false" @disabled(! $canWrite)>
    @error('hostinger_order_id') <p class="field-error" role="alert">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-check">
        <input type="hidden" name="is_enabled" value="0">
        <input type="checkbox" name="is_enabled" value="1" @checked(old('is_enabled', $server->is_enabled ?? true)) @disabled(! $canWrite)>
        <span>{{ __('mail.fields.is_enabled') }}</span>
    </label>
</div>
