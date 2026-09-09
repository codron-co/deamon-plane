<section class="settings-panel" aria-labelledby="github-connection-heading">
    <h2 id="github-connection-heading">GitHub theme catalog</h2>
    <p class="field-hint">
        Org lock <code>{{ config('ops.themes.org') }}</code>, repo prefix <code>{{ $themeRepoPrefix }}</code>.
        PAT or GitHub App credentials are encrypted. They never appear after save.
        Distinct from Coolify’s GitHub App UUID (used to create customer compose apps).
    </p>

    <form method="POST" action="{{ route('ops.settings.github.update') }}" class="ops-form settings-form">
        @csrf

        <div class="field">
            <label class="field-label" for="github-org">Organization</label>
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
            <label class="field-label" for="github-token">Fine-grained PAT</label>
            <p class="field-hint">
                @if ($githubHasToken)
                    Token configured. Leave blank to keep the current value.
                @else
                    Optional if a GitHub App is configured. Contents + metadata on <code>deamon-theme-*</code>.
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
            <label class="field-label" for="github-app-id">GitHub App id</label>
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
            <label class="field-label" for="github-installation-id">Installation id</label>
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
            <label class="field-label" for="github-private-key">App private key (PEM)</label>
            <p class="field-hint">
                @if ($githubHasApp)
                    Private key configured. Leave blank to keep the current value.
                @else
                    Encrypted. Used only to mint short-lived installation tokens. Never logged.
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
            <span class="field-label">Theme webhook URL</span>
            <p class="field-hint">GitHub org/repo webhook → this URL. Not the Coolify deploy webhook.</p>
            <input class="field-input" type="text" value="{{ $githubWebhookUrl }}" readonly>
        </div>

        <div class="field">
            <label class="field-label" for="github-webhook-secret">GitHub webhook secret</label>
            <p class="field-hint">
                Encrypted. Header <code>X-Hub-Signature-256</code>. Empty secret is rejected.
                @if ($githubHasWebhookSecret)
                    Secret configured. Leave blank to keep the current value.
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
                <button type="submit" class="btn btn-primary">Save GitHub</button>
                <button type="submit" class="btn btn-secondary" formaction="{{ route('ops.settings.github.test') }}">Test GitHub</button>
            </div>
        @else
            <p class="field-hint">Viewer role is read-only.</p>
        @endif
    </form>
</section>
