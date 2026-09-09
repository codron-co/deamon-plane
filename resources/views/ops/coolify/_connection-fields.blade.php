@php
    $requireToken = $requireToken ?? false;
    $canWrite = $canWrite ?? false;
@endphp

<div class="field">
    <label class="field-label" for="coolify-name">Ad</label>
    <input id="coolify-name" class="field-input" type="text" name="name" value="{{ old('name', $connection->name) }}" required @disabled(! $canWrite) maxlength="120">
    @error('name') <p class="field-hint" role="alert">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="coolify-base-url">Coolify base URL</label>
    <p class="field-hint">Host veya <code>/api/v1</code> — Plane <code>{base}/api/v1</code> yapar.</p>
    <input id="coolify-base-url" class="field-input" type="url" name="base_url" value="{{ old('base_url', $connection->base_url) }}" required @disabled(! $canWrite) placeholder="https://coolify.example">
    @error('base_url') <p class="field-hint" role="alert">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="coolify-api-token">API token</label>
    <p class="field-hint">
        @if ($connection->exists && $connection->hasToken())
            Token kayıtlı. Değiştirmek için yeni değer yapıştırın.
        @else
            Coolify Keys &amp; Tokens. Kaydettikten sonra gösterilmez.
        @endif
    </p>
    <input id="coolify-api-token" class="field-input" type="password" name="api_token" value="" autocomplete="new-password" @disabled(! $canWrite) @required($requireToken && ! ($connection->exists && $connection->hasToken()))>
    @error('api_token') <p class="field-hint" role="alert">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="coolify-webhook-secret">Webhook imza secret</label>
    <p class="field-hint">Encrypted. Env yedek: <code>COOLIFY_WEBHOOK_SECRET</code>.</p>
    <input id="coolify-webhook-secret" class="field-input" type="password" name="webhook_secret" value="" autocomplete="new-password" @disabled(! $canWrite)>
</div>

<div class="field">
    <label class="field-check">
        <input type="hidden" name="is_enabled" value="0">
        <input type="checkbox" name="is_enabled" value="1" @checked(old('is_enabled', $connection->is_enabled ?? true)) @disabled(! $canWrite)>
        Bağlantı açık
    </label>
</div>

<div class="field">
    <label class="field-check">
        <input type="hidden" name="is_default" value="0">
        <input type="checkbox" name="is_default" value="1" @checked(old('is_default', $connection->is_default ?? false)) @disabled(! $canWrite)>
        Yeni siteler için varsayılan
    </label>
</div>
