@php
    /** @var \App\Models\DatabaseServer $server */
    $hasPassword = $server->exists && filled($server->getRawOriginal('admin_password'));
@endphp

<div class="field">
    <label class="field-label" for="db-name">{{ __('databases.fields.name') }}</label>
    <input id="db-name" class="field-input" type="text" name="name" value="{{ old('name', $server->name) }}" maxlength="120" required>
    @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="db-host">{{ __('databases.fields.host') }}</label>
    <p class="field-hint">{{ __('databases.fields.host_hint') }}</p>
    <input id="db-host" class="field-input" type="text" name="host" value="{{ old('host', $server->host) }}" maxlength="190" required autocomplete="off" spellcheck="false">
    @error('host') <p class="field-error" role="alert">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="db-port">{{ __('databases.fields.port') }}</label>
    <input id="db-port" class="field-input" type="number" name="port" value="{{ old('port', $server->port ?? 3306) }}" min="1" max="65535" required>
    @error('port') <p class="field-error" role="alert">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="db-admin-username">{{ __('databases.fields.admin_username') }}</label>
    <input id="db-admin-username" class="field-input" type="text" name="admin_username" value="{{ old('admin_username', $server->admin_username) }}" maxlength="32" required autocomplete="off" spellcheck="false">
    @error('admin_username') <p class="field-error" role="alert">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="db-admin-password">{{ __('databases.fields.admin_password') }}</label>
    @if ($hasPassword)
        <p class="field-hint">{{ __('databases.fields.admin_password_keep') }}</p>
    @endif
    <input id="db-admin-password" class="field-input" type="password" name="admin_password" value="" placeholder="{{ $hasPassword ? '••••••••' : '' }}" autocomplete="new-password" @required(! $hasPassword)>
    @error('admin_password') <p class="field-error" role="alert">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-check">
        <input type="hidden" name="is_default" value="0">
        <input type="checkbox" name="is_default" value="1" @checked(old('is_default', $server->is_default ?? false))>
        <span>{{ __('databases.fields.is_default') }}</span>
    </label>
</div>

<div class="field">
    <label class="field-label" for="db-notes">{{ __('databases.fields.notes') }}</label>
    <textarea id="db-notes" class="field-input" name="notes" rows="3" maxlength="2000">{{ old('notes', $server->notes) }}</textarea>
    @error('notes') <p class="field-error" role="alert">{{ $message }}</p> @enderror
</div>
