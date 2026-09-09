<section class="settings-panel" aria-labelledby="coolify-connection-heading">
    <h2 id="coolify-connection-heading">Coolify connection</h2>
    <p class="field-hint">Token is stored encrypted. It is never shown after save. Tests use the HTTP adapter — no token is logged.</p>

    @if (session('error'))
        <p class="ops-alert" role="alert">{{ session('error') }}</p>
    @endif

    <form method="POST" action="{{ route('ops.settings.update') }}" class="ops-form settings-form">
        @csrf

        <div class="field">
            <label class="field-label" for="coolify-base-url">Coolify base URL</label>
            <p class="field-hint">Host only, or with <code>/api/v1</code> — Plane normalizes to <code>{base}/api/v1</code>.</p>
            <input
                id="coolify-base-url"
                class="field-input"
                type="url"
                name="base_url"
                value="{{ old('base_url', $coolifyBaseUrl) }}"
                placeholder="https://coolify.example"
                autocomplete="off"
                @disabled(! $canWrite)
            >
            @error('base_url')
                <p class="field-hint" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div class="field">
            <label class="field-label" for="coolify-api-token">API token</label>
            <p class="field-hint">
                @if ($hasToken)
                    Token configured. Leave blank to keep the current value.
                @else
                    Not configured. Paste a Coolify Keys &amp; Tokens value.
                @endif
            </p>
            <input
                id="coolify-api-token"
                class="field-input"
                type="password"
                name="api_token"
                value=""
                placeholder="{{ $hasToken ? '••••••••' : 'Bearer token' }}"
                autocomplete="new-password"
                @disabled(! $canWrite)
            >
            @error('api_token')
                <p class="field-hint" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div class="field">
            <label class="field-label" for="coolify-project-uuid">Default project UUID</label>
            <input
                id="coolify-project-uuid"
                class="field-input"
                type="text"
                name="default_project_uuid"
                value="{{ old('default_project_uuid', $projectUuid) }}"
                autocomplete="off"
                @disabled(! $canWrite)
            >
        </div>

        <div class="field">
            <label class="field-label" for="coolify-server-uuid">Default server UUID</label>
            <input
                id="coolify-server-uuid"
                class="field-input"
                type="text"
                name="default_server_uuid"
                value="{{ old('default_server_uuid', $serverUuid) }}"
                autocomplete="off"
                @disabled(! $canWrite)
            >
        </div>

        <div class="field">
            <label class="field-label" for="coolify-github-app-uuid">GitHub App UUID</label>
            <p class="field-hint">Required for <code>POST /applications/private-github-app</code> (private <code>codron-co/deamon</code>).</p>
            <input
                id="coolify-github-app-uuid"
                class="field-input"
                type="text"
                name="github_app_uuid"
                value="{{ old('github_app_uuid', $githubAppUuid) }}"
                autocomplete="off"
                @disabled(! $canWrite)
            >
        </div>

        <div class="field">
            <label class="field-label" for="coolify-private-key-uuid">Deploy key UUID</label>
            <p class="field-hint">Alternative to GitHub App: <code>POST /applications/private-deploy-key</code>.</p>
            <input
                id="coolify-private-key-uuid"
                class="field-input"
                type="text"
                name="private_key_uuid"
                value="{{ old('private_key_uuid', $privateKeyUuid) }}"
                autocomplete="off"
                @disabled(! $canWrite)
            >
        </div>

        <div class="field">
            <span class="field-label">Deploy webhook URL</span>
            <p class="field-hint">Paste this in Coolify Notifications → Webhook. Plane verifies HMAC; Coolify’s own notification POSTs are unsigned today — see <code>docs/modules/coolify-webhooks.md</code>.</p>
            <input class="field-input" type="text" value="{{ $webhookUrl }}" readonly>
        </div>

        <div class="field">
            <label class="field-label" for="coolify-webhook-secret">Webhook signing secret</label>
            <p class="field-hint">
                Encrypted. Header <code>X-Coolify-Signature: sha256=&lt;hmac&gt;</code> (also accepts <code>X-Hub-Signature-256</code>).
                @if ($hasWebhookSecret)
                    Secret configured. Leave blank to keep the current value.
                @else
                    Not configured. Env fallback is <code>COOLIFY_WEBHOOK_SECRET</code>.
                @endif
            </p>
            <input
                id="coolify-webhook-secret"
                class="field-input"
                type="password"
                name="webhook_secret"
                value=""
                placeholder="{{ $hasWebhookSecret ? '••••••••' : 'HMAC secret' }}"
                autocomplete="new-password"
                @disabled(! $canWrite)
            >
            @error('webhook_secret')
                <p class="field-hint" role="alert">{{ $message }}</p>
            @enderror
        </div>

        @if ($canWrite)
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save connection</button>
                <button type="submit" class="btn btn-secondary" formaction="{{ route('ops.settings.coolify.test') }}">Test connection</button>
            </div>
        @else
            <p class="field-hint">Viewer role is read-only. Saving and Test connection are disabled.</p>
        @endif
    </form>
</section>
