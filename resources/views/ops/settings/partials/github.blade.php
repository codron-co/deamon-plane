<section class="settings-panel" aria-labelledby="github-connection-heading">
    <h2 id="github-connection-heading">{{ __('settings.github.title') }}</h2>
    <p class="field-hint">{{ __('settings.github.pointer') }}</p>

    <div class="ops-form settings-form">
        <div class="field">
            <span class="field-label">{{ __('settings.github.webhook_url') }}</span>
            <p class="field-hint">{{ __('settings.github.webhook_url_hint') }}</p>
            <input class="field-input" type="text" value="{{ $githubWebhookUrl }}" readonly>
        </div>
        <div class="form-actions">
            <a class="btn btn-primary" href="{{ route('ops.themes') }}">{{ __('settings.github.open_themes') }}</a>
        </div>
    </div>
</section>
