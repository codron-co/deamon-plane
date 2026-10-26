@php
    $canWrite = $canWrite ?? false;
    $requireToken = $requireToken ?? false;
    $provider = old('provider', $server->provider?->value ?? 'hostinger');
    $providerLocked = $server->exists;
@endphp

<div class="field">
    <label class="field-label" for="mail-name">{{ __('mail.fields.name') }}</label>
    <input id="mail-name" class="field-input" type="text" name="name" value="{{ old('name', $server->name) }}" maxlength="120" required @disabled(! $canWrite)>
    @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="mail-provider">{{ __('mail.fields.provider') }}</label>
    <p class="field-hint">{{ $providerLocked ? __('mail.fields.provider_locked') : __('mail.fields.provider_hint') }}</p>
    <select id="mail-provider" name="provider" @disabled(! $canWrite || $providerLocked) required>
        <option value="hostinger" @selected($provider === 'hostinger')>{{ __('mail.providers.hostinger') }}</option>
        <option value="mailcow" @selected($provider === 'mailcow')>{{ __('mail.providers.mailcow') }}</option>
    </select>
    @error('provider') <p class="field-error" role="alert">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="mail-api-token">{{ __('mail.fields.api_token') }}</label>
    <p class="field-hint">{{ $server->exists && $server->hasToken() ? __('mail.fields.token_saved') : __('mail.fields.token_hint') }}</p>
    <input id="mail-api-token" class="field-input" type="password" name="api_token" value="" placeholder="{{ $server->exists && $server->hasToken() ? '••••••••' : __('mail.fields.token_placeholder') }}" autocomplete="new-password" @disabled(! $canWrite) @required($requireToken && ! ($server->exists && $server->hasToken()))>
    @error('api_token') <p class="field-error" role="alert">{{ $message }}</p> @enderror
</div>

@if (! $providerLocked || $provider === 'mailcow')
    <fieldset class="field-group" data-mail-provider-fields="mailcow">
        <legend class="field-label">{{ __('mail.fields.comail_legend') }}</legend>
        <div class="field">
            <label class="field-label" for="mail-base-url">{{ __('mail.fields.base_url') }}</label>
            <p class="field-hint">{{ __('mail.fields.base_url_hint') }}</p>
            <input id="mail-base-url" class="field-input" type="url" name="base_url" value="{{ old('base_url', $server->base_url) }}" maxlength="255" placeholder="https://mail.codron.co" autocomplete="off" spellcheck="false" @disabled(! $canWrite)>
            @error('base_url') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label class="field-label" for="mail-hostname">{{ __('mail.fields.mail_hostname') }}</label>
            <p class="field-hint">{{ __('mail.fields.mail_hostname_hint') }}</p>
            <input id="mail-hostname" class="field-input" type="text" name="mail_hostname" value="{{ old('mail_hostname', $server->mail_hostname) }}" maxlength="255" placeholder="mail.codron.co" autocomplete="off" spellcheck="false" @disabled(! $canWrite)>
            @error('mail_hostname') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>
    </fieldset>
@endif

<div class="field">
    <label class="field-check">
        <input type="hidden" name="is_enabled" value="0">
        <input type="checkbox" name="is_enabled" value="1" @checked(old('is_enabled', $server->is_enabled ?? true)) @disabled(! $canWrite)>
        <span>{{ __('mail.fields.is_enabled') }}</span>
    </label>
</div>
