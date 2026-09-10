@php
    $requireToken = $requireToken ?? false;
    $canWrite = $canWrite ?? false;
@endphp

<div class="field">
    <label class="field-label" for="coolify-name">{{ __('coolify.fields.name') }}</label>
    <input id="coolify-name" class="field-input" type="text" name="name" value="{{ old('name', $connection->name) }}" required @disabled(! $canWrite) maxlength="120">
    @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="coolify-base-url">{{ __('coolify.fields.base_url') }}</label>
    <p class="field-hint">{{ __('coolify.fields.base_url_hint') }}</p>
    <input id="coolify-base-url" class="field-input" type="url" name="base_url" value="{{ old('base_url', $connection->base_url) }}" required @disabled(! $canWrite) placeholder="{{ __('coolify.fields.base_url_placeholder') }}">
    @error('base_url') <p class="field-error" role="alert">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="coolify-api-token">{{ __('coolify.fields.token') }}</label>
    <p class="field-hint">
        @if ($connection->exists && $connection->hasToken())
            {{ __('coolify.fields.token_saved') }}
        @else
            {{ __('coolify.fields.token_hint') }}
        @endif
    </p>
    <input id="coolify-api-token" class="field-input" type="password" name="api_token" value="" autocomplete="new-password" @disabled(! $canWrite) @required($requireToken && ! ($connection->exists && $connection->hasToken()))>
    @error('api_token') <p class="field-error" role="alert">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="coolify-webhook-secret">{{ __('coolify.fields.webhook_secret') }}</label>
    <p class="field-hint">{{ __('coolify.fields.webhook_secret_hint') }}</p>
    <input id="coolify-webhook-secret" class="field-input" type="password" name="webhook_secret" value="" autocomplete="new-password" @disabled(! $canWrite)>
</div>

<div class="field">
    <label class="field-check">
        <input type="hidden" name="is_enabled" value="0">
        <input type="checkbox" name="is_enabled" value="1" @checked(old('is_enabled', $connection->is_enabled ?? true)) @disabled(! $canWrite)>
        {{ __('coolify.fields.is_enabled') }}
    </label>
</div>

<div class="field">
    <label class="field-check">
        <input type="hidden" name="is_default" value="0">
        <input type="checkbox" name="is_default" value="1" @checked(old('is_default', $connection->is_default ?? false)) @disabled(! $canWrite)>
        {{ __('coolify.fields.is_default') }}
    </label>
</div>
