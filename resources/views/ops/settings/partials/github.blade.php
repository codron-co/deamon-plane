<section class="settings-panel" aria-labelledby="github-connection-heading">
    <h2 id="github-connection-heading">{{ __('settings.github.title') }}</h2>
    <p class="field-hint">{{ __('settings.github.lede', ['org' => config('ops.themes.org'), 'prefix' => $themeRepoPrefix]) }}</p>

    <form method="POST" action="{{ route('ops.settings.github.update') }}" class="ops-form settings-form">
        @csrf

        <div class="field">
            <label class="field-label" for="github-org">{{ __('settings.github.org') }}</label>
            <input
                id="github-org"
                class="field-input"
                type="text"
                name="org"
                value="{{ old('org', $githubOrg) }}"
                autocomplete="off"
                @disabled(! $canWrite)
            >
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
                placeholder="{{ $githubHasToken ? '••••••••' : 'github_pat_…' }}"
                autocomplete="new-password"
                @disabled(! $canWrite)
            >
        </div>

        <div class="field">
            <label class="field-label" for="github-app-id">{{ __('settings.github.app_id') }}</label>
            <input
                id="github-app-id"
                class="field-input"
                type="text"
                name="app_id"
                value="{{ old('app_id', $githubAppId) }}"
                autocomplete="off"
                @disabled(! $canWrite)
            >
        </div>

        <div class="field">
            <label class="field-label" for="github-installation-id">{{ __('settings.github.installation') }}</label>
            <input
                id="github-installation-id"
                class="field-input"
                type="text"
                name="installation_id"
                value="{{ old('installation_id', $githubInstallationId) }}"
                autocomplete="off"
                @disabled(! $canWrite)
            >
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
                class="field-input"
                name="private_key"
                rows="4"
                autocomplete="off"
                @disabled(! $canWrite)
            ></textarea>
        </div>

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
                placeholder="{{ $githubHasWebhookSecret ? '••••••••' : 'HMAC secret' }}"
                autocomplete="new-password"
                @disabled(! $canWrite)
            >
        </div>

        @if ($canWrite)
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">{{ __('settings.github.save') }}</button>
                <button type="submit" class="btn btn-secondary" formaction="{{ route('ops.settings.github.test') }}">{{ __('settings.github.test') }}</button>
            </div>
        @else
            <p class="field-hint">{{ __('ops.viewer_readonly') }}</p>
        @endif
    </form>
</section>
