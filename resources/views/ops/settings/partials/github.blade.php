<form method="POST" action="{{ route('ops.settings.github.update') }}" class="ops-form">
    @csrf

    <section class="settings-panel" aria-labelledby="github-connection-heading">
        <h2 id="github-connection-heading">{{ __('settings.github.title') }}</h2>
        <p class="field-hint">{{ __('settings.github.lede', ['org' => config('ops.themes.org'), 'prefix' => $themeRepoPrefix]) }}</p>

        <div class="ops-form settings-form">
            <div class="field">
                <label class="field-label" for="github-org">{{ __('settings.github.org') }}</label>
                <p class="field-hint">{{ __('settings.github.org_hint') }}</p>
                <input
                    id="github-org"
                    class="field-input"
                    type="text"
                    name="org"
                    value="{{ old('org', $githubOrg) }}"
                    autocomplete="off"
                    @disabled(! $canWrite)
                >
                @error('org') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label class="field-label" for="github-token">{{ __('settings.github.token') }}</label>
                <p class="field-hint">
                    @if ($githubHasToken)
                        {{ __('settings.github.token_configured') }}
                    @else
                        {{ __('settings.github.token_hint') }}
                    @endif
                </p>
                <input
                    id="github-token"
                    class="field-input"
                    type="password"
                    name="token"
                    value=""
                    placeholder="{{ $githubHasToken ? '••••••••' : __('settings.github.token_placeholder') }}"
                    autocomplete="new-password"
                    @disabled(! $canWrite)
                >
                @error('token') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label class="field-label" for="github-app-id">{{ __('settings.github.app_id') }}</label>
                <p class="field-hint">{{ __('settings.github.app_id_hint') }}</p>
                <input
                    id="github-app-id"
                    class="field-input"
                    type="text"
                    name="app_id"
                    value="{{ old('app_id', $githubAppId) }}"
                    autocomplete="off"
                    @disabled(! $canWrite)
                >
                @error('app_id') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label class="field-label" for="github-installation-id">{{ __('settings.github.installation') }}</label>
                <p class="field-hint">{{ __('settings.github.installation_hint') }}</p>
                <input
                    id="github-installation-id"
                    class="field-input"
                    type="text"
                    name="installation_id"
                    value="{{ old('installation_id', $githubInstallationId) }}"
                    autocomplete="off"
                    @disabled(! $canWrite)
                >
                @error('installation_id') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label class="field-label" for="github-private-key">{{ __('settings.github.private_key') }}</label>
                <p class="field-hint">
                    @if ($githubHasApp)
                        {{ __('settings.github.key_configured') }}
                    @else
                        {{ __('settings.github.key_hint') }}
                    @endif
                </p>
                <textarea
                    id="github-private-key"
                    class="field-input field-textarea"
                    name="private_key"
                    rows="4"
                    autocomplete="off"
                    spellcheck="false"
                    @disabled(! $canWrite)
                ></textarea>
                @error('private_key') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>
    </section>

    <section class="settings-panel" aria-labelledby="github-webhook-heading">
        <h2 id="github-webhook-heading">{{ __('settings.github.webhook') }}</h2>
        <p class="field-hint">{{ __('settings.github.webhook_lede') }}</p>

        <div class="ops-form settings-form">
            <div class="field">
                <span class="field-label">{{ __('settings.github.webhook_url') }}</span>
                <p class="field-hint">{{ __('settings.github.webhook_url_hint') }}</p>
                <input class="field-input" type="text" value="{{ $githubWebhookUrl }}" readonly>
            </div>

            <div class="field">
                <label class="field-label" for="github-webhook-secret">{{ __('settings.github.webhook_secret') }}</label>
                <p class="field-hint">
                    {{ __('settings.github.webhook_secret_hint') }}
                    @if ($githubHasWebhookSecret)
                        {{ __('settings.github.secret_configured') }}
                    @endif
                </p>
                <input
                    id="github-webhook-secret"
                    class="field-input"
                    type="password"
                    name="webhook_secret"
                    value=""
                    placeholder="{{ $githubHasWebhookSecret ? '••••••••' : __('settings.github.secret_placeholder') }}"
                    autocomplete="new-password"
                    @disabled(! $canWrite)
                >
                @error('webhook_secret') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            @if ($canWrite)
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">{{ __('settings.github.save') }}</button>
                    <button type="submit" class="btn btn-secondary" formaction="{{ route('ops.settings.github.test') }}">{{ __('settings.github.test') }}</button>
                </div>
            @else
                <p class="field-hint">{{ __('ops.viewer_readonly') }}</p>
            @endif
        </div>
    </section>
</form>
