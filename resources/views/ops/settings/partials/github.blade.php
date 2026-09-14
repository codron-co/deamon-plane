@php
    $github = $githubSetting ?? \App\Models\GithubSetting::current();
    $publicUrl = \App\Support\PublicAppUrl::isPublic();
    $connected = $github->hasDeamonGitInstallation() || $github->hasToken();
@endphp
<section
    class="settings-panel"
    aria-labelledby="deamon-git-heading"
    data-settings-section
    data-settings-haystack="{{ \App\Support\Ops\SettingsJump::haystackFor('deamon_git') }}"
>
    <h2 id="deamon-git-heading">{{ __('settings.deamon_git.title') }}</h2>
    <p class="field-hint">{{ __('settings.deamon_git.lede') }}</p>

    <div class="ops-form settings-form">
        <div class="field">
            <span class="field-label">{{ __('settings.deamon_git.repo') }}</span>
            <input class="field-input" type="text" value="{{ $envRepo ?? __('settings.deamon_git.repo_missing') }}" readonly>
        </div>

        @if ($connected)
            <div class="field">
                <span class="field-label">{{ __('settings.deamon_git.status') }}</span>
                <p class="field-hint">
                    @if ($github->hasDeamonGitInstallation())
                        {{ __('settings.deamon_git.connected_app', [
                            'account' => $github->deamonGitAccountLabel() ?? __('settings.deamon_git.unknown_account'),
                        ]) }}
                    @endif
                    @if ($github->hasToken())
                        {{ __('settings.deamon_git.connected_pat') }}
                    @endif
                </p>
            </div>
        @else
            <p class="field-hint">{{ __('settings.deamon_git.empty') }}</p>
        @endif

        @if ($canWrite ?? false)
            <div class="form-actions">
                <form method="POST" action="{{ route('ops.settings.deamon-git.connect') }}" data-ops-native>
                    @csrf
                    <button type="submit" class="btn btn-primary" @if (! $publicUrl && ! $github->hasManifestApp()) disabled @endif>
                        {{ $github->hasManifestApp() ? __('settings.deamon_git.connect') : __('settings.deamon_git.create_app') }}
                    </button>
                </form>
                @if ($github->hasDeamonGitInstallation())
                    <form
                        method="POST"
                        action="{{ route('ops.settings.deamon-git.disconnect') }}"
                        data-confirm="{{ __('settings.deamon_git.disconnect_confirm') }}"
                        data-confirm-title="{{ __('settings.deamon_git.disconnect_title') }}"
                        data-confirm-danger
                    >
                        @csrf
                        <button type="submit" class="btn btn-danger">{{ __('settings.deamon_git.disconnect') }}</button>
                    </form>
                @endif
            </div>

            @if (! $publicUrl)
                <p class="field-hint">{{ __('settings.deamon_git.public_url_hint') }}</p>
            @endif

            <details class="settings-advanced">
                <summary>{{ __('settings.deamon_git.pat_summary') }}</summary>
                <form method="POST" action="{{ route('ops.settings.deamon-git.pat') }}" class="ops-form">
                    @csrf
                    <div class="field">
                        <label class="field-label" for="deamon-git-pat">{{ __('settings.deamon_git.pat_label') }}</label>
                        <input id="deamon-git-pat" class="field-input" type="password" name="token" autocomplete="off" required>
                        <p class="field-hint">{{ __('settings.deamon_git.pat_hint') }}</p>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-secondary">{{ __('settings.deamon_git.pat_save') }}</button>
                    </div>
                </form>
                @if ($github->hasToken())
                    <form
                        method="POST"
                        action="{{ route('ops.settings.deamon-git.pat.clear') }}"
                        class="form-actions"
                        data-confirm="{{ __('settings.deamon_git.pat_clear_confirm') }}"
                    >
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-ghost">{{ __('settings.deamon_git.pat_clear') }}</button>
                    </form>
                @endif
            </details>
        @endif
    </div>
</section>

<section
    class="settings-panel"
    aria-labelledby="github-connection-heading"
    data-settings-section
    data-settings-haystack="{{ \App\Support\Ops\SettingsJump::haystackFor('github') }}"
>
    <h2 id="github-connection-heading">{{ __('settings.github.title') }}</h2>
    <p class="field-hint">{{ __('settings.github.pointer') }}</p>

    <div class="ops-form settings-form">
        <div class="field">
            <span class="field-label">{{ __('settings.github.webhook_url') }}</span>
            <p class="field-hint">{{ __('settings.github.webhook_url_hint') }}</p>
            <input class="field-input" type="text" value="{{ $githubWebhookUrl }}" readonly>
        </div>
        <div class="form-actions">
            <a class="btn btn-secondary" href="{{ route('ops.themes') }}">{{ __('settings.github.open_themes') }}</a>
        </div>
    </div>
</section>
