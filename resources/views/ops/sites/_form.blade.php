@php
    /** @var \App\Models\Site $site */
    $readonly = $readonly ?? false;
    $channelLocked = $channelLocked ?? false;
    $slugLocked = $channelLocked;
    $currentChannel = old('channel', $site->channel?->value ?? 'main');
    $currentDomain = old('domain', $site->primary_domain);
    $coolifyConnections = $coolifyConnections ?? collect();
    $coolifyConnection = $coolifyConnection ?? null;
    $coolifyServers = $coolifyServers ?? collect();
    $coolifyProjects = $coolifyProjects ?? collect();
    $coolifyEnvironments = $coolifyEnvironments ?? collect();
    $coolifyGitSources = $coolifyGitSources ?? collect();
    $attachableApps = $attachableApps ?? [];
    $isSuperAdmin = $isSuperAdmin ?? false;
    $optionsUrl = $optionsUrl ?? null;
    $selectedConnectionId = old('coolify_connection_id', $site->coolify_connection_id ?? $coolifyConnection?->id);
    $selectedServer = old('coolify_server_uuid', $site->coolify_server_uuid);
    $selectedProject = old('coolify_project_uuid', $site->coolify_project_uuid);
    $selectedEnvironment = old('coolify_environment_uuid', $site->coolify_environment_uuid);
    $selectedGit = old('coolify_git_source', $site->coolify_git_source_kind && $site->coolify_git_source_uuid
        ? $site->coolify_git_source_kind->value.':'.$site->coolify_git_source_uuid
        : null);
    $placement = old('placement', filled($site->coolify_app_uuid) ? 'attach' : 'provision');
@endphp

@if ($errors->any())
    <p class="ops-alert" role="alert">Fix the highlighted fields. Nothing was saved.</p>
@endif

@if ($site->channel_needs_review)
    <p class="ops-alert ops-alert-warning" role="status">
        Coolify git_branch allowlist dışında. Kanalı <strong>main / beta / alpha</strong> seçin — serbest branch yok.
    </p>
@endif

<div class="field">
    <label class="field-label" for="site_slug">Slug</label>
    <p class="field-hint">Stable identifier. Lowercase letters, numbers, hyphens.</p>
    <input
        id="site_slug"
        class="field-input"
        type="text"
        name="slug"
        value="{{ old('slug', $site->slug) }}"
        autocomplete="off"
        maxlength="64"
        @required(!$readonly && ! $slugLocked)
        @readonly($readonly || $slugLocked)
    >
    @error('slug') <p class="field-error">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="site_name">Name</label>
    <p class="field-hint">Shown in the fleet list and Coolify app name later.</p>
    <input
        id="site_name"
        class="field-input"
        type="text"
        name="name"
        value="{{ old('name', $site->name) }}"
        maxlength="255"
        @required(! $readonly)
        @readonly($readonly)
    >
    @error('name') <p class="field-error">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="site_domain">Domain</label>
    <p class="field-hint">Primary hostname. Bağlama seçilirse Coolify app domain’i yazılır.</p>
    <input
        id="site_domain"
        class="field-input"
        type="text"
        name="domain"
        value="{{ $currentDomain }}"
        autocomplete="off"
        maxlength="255"
        placeholder="shop.example.com"
        @required(! $readonly)
        @readonly($readonly)
    >
    @error('domain') <p class="field-error">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="site_channel">Channel</label>
    <p class="field-hint">Git branch allowlist: main · beta · alpha. Serbest branch yok.</p>
    <select
        id="site_channel"
        class="field-input"
        name="channel"
        @required(! $readonly && ! $channelLocked)
        @disabled($readonly || $channelLocked)
    >
        @foreach ($channels as $channel)
            <option value="{{ $channel }}" @selected($currentChannel === $channel)>{{ $channel }}</option>
        @endforeach
    </select>
    @if ($channelLocked)
        <input type="hidden" name="channel" value="{{ $site->channel?->value }}">
    @endif
    @error('channel') <p class="field-error">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="site_connection">Coolify bağlantısı</label>
    <p class="field-hint">Varsayılan bağlantı yeni sitelerde seçili gelir. <a href="{{ route('ops.coolify.index') }}">Coolify menüsü</a>.</p>
    <select
        id="site_connection"
        class="field-input"
        name="coolify_connection_id"
        data-coolify-connection
        data-options-template="{{ url('/coolify') }}/__id__/options"
        @disabled($readonly)
    >
        <option value="">—</option>
        @foreach ($coolifyConnections as $row)
            <option value="{{ $row->id }}" @selected((string) $selectedConnectionId === (string) $row->id)>
                {{ $row->name }}{{ $row->is_default ? ' (varsayılan)' : '' }}
            </option>
        @endforeach
    </select>
    @error('coolify_connection_id') <p class="field-error">{{ $message }}</p> @enderror
</div>

@if (! $site->exists)
    <fieldset class="coolify-placement" data-coolify-placement>
        <legend class="field-label">Kurulum</legend>
        <label class="field-check">
            <input type="radio" name="placement" value="provision" @checked($placement === 'provision') @disabled($readonly)>
            Yeni compose stack (provision)
        </label>
        <label class="field-check">
            <input type="radio" name="placement" value="attach" @checked($placement === 'attach') @disabled($readonly)>
            Mevcut Coolify app bağla (ikinci create yok)
        </label>
    </fieldset>

    <div class="field" data-attach-field hidden>
        <label class="field-label" for="attach_app">Mevcut Deamon app</label>
        <p class="field-hint">Yalnızca <code>codron-co/deamon</code> (import classifier). Plane / tema repo yok.</p>
        <select id="attach_app" class="field-input" name="attach_app_uuid" data-attach-apps @disabled($readonly)>
            <option value="">—</option>
            @foreach ($attachableApps as $app)
                <option value="{{ $app['uuid'] }}" @selected(old('attach_app_uuid') === $app['uuid'])>
                    {{ $app['name'] }}
                    @if ($app['domain']) — {{ $app['domain'] }} @endif
                    @if ($app['branch']) ({{ $app['branch'] }}) @endif
                    @if ($app['needs_review']) · needs_review @endif
                </option>
            @endforeach
        </select>
        @error('attach_app_uuid') <p class="field-error">{{ $message }}</p> @enderror
    </div>
@endif

<div class="field" data-provision-field>
    <label class="field-label" for="site_server">Sunucu</label>
    <p class="field-hint">Aktif sunucular. UUID yazılmaz.</p>
    <select id="site_server" class="field-input" name="coolify_server_uuid" data-coolify-servers @disabled($readonly)>
        <option value="">—</option>
        @foreach ($coolifyServers as $server)
            <option value="{{ $server->uuid }}" title="{{ $server->uuid }}" @selected($selectedServer === $server->uuid)>{{ $server->label() }}</option>
        @endforeach
    </select>
    @error('coolify_server_uuid') <p class="field-error">{{ $message }}</p> @enderror
</div>

<div class="field" data-provision-field>
    <label class="field-label" for="site_project">Proje</label>
    <select id="site_project" class="field-input" name="coolify_project_uuid" data-coolify-projects @disabled($readonly)>
        <option value="">—</option>
        @foreach ($coolifyProjects as $project)
            <option value="{{ $project->uuid }}" title="{{ $project->uuid }}" @selected($selectedProject === $project->uuid)>{{ $project->label() }}</option>
        @endforeach
    </select>
    @error('coolify_project_uuid') <p class="field-error">{{ $message }}</p> @enderror
</div>

<div class="field" data-provision-field>
    <label class="field-label" for="site_environment">Ortam</label>
    <p class="field-hint">Coolify environment. Kanal (main/beta/alpha) değil.</p>
    <select
        id="site_environment"
        class="field-input"
        name="coolify_environment_uuid"
        data-coolify-environments
        data-environment-options="{{ Js::from($coolifyEnvironmentOptions ?? []) }}"
        @disabled($readonly)
    >
        <option value="">—</option>
        @foreach ($coolifyEnvironments as $environment)
            <option
                value="{{ $environment->uuid }}"
                data-project="{{ $environment->project_uuid }}"
                title="{{ $environment->uuid }}"
                @selected($selectedEnvironment === $environment->uuid)
            >{{ $environment->label() }}</option>
        @endforeach
    </select>
    @error('coolify_environment_uuid') <p class="field-error">{{ $message }}</p> @enderror
</div>

<div class="field" data-provision-field>
    <label class="field-label" for="site_git">Git kaynağı (Coolify)</label>
    <p class="field-hint">
        Coolify GitHub App veya deploy key.
        @if ($githubAppsListAvailable === false)
            Bu instance GitHub App listesi vermiyor — deploy key veya Super Admin gelişmiş alan.
        @endif
        Tema kataloğu GitHub App’i değil.
    </p>
    <select id="site_git" class="field-input" name="coolify_git_source" data-coolify-git @disabled($readonly)>
        <option value="">— public</option>
        @foreach ($coolifyGitSources as $source)
            <option value="{{ $source->formValue() }}" title="{{ $source->uuid }}" @selected($selectedGit === $source->formValue())>{{ $source->label() }}</option>
        @endforeach
    </select>
    @error('coolify_git_source') <p class="field-error">{{ $message }}</p> @enderror
</div>

@if ($isSuperAdmin && ! $readonly)
    <details class="coolify-advanced">
        <summary>Gelişmiş — UUID yapıştır (yalnız Super Admin)</summary>
        <p class="ops-alert ops-alert-warning">Yanlış UUID provision’ı kırar (<code>skaaaw</code> / <code>sk4o4w</code>). Mümkünse select kullanın.</p>
        <div class="field">
            <label class="field-label" for="advanced_server">Server UUID</label>
            <input id="advanced_server" class="field-input" type="text" name="advanced_server_uuid" value="{{ old('advanced_server_uuid') }}" maxlength="64" autocomplete="off" spellcheck="false">
        </div>
        <div class="field">
            <label class="field-label" for="advanced_project">Project UUID</label>
            <input id="advanced_project" class="field-input" type="text" name="advanced_project_uuid" value="{{ old('advanced_project_uuid') }}" maxlength="64" autocomplete="off" spellcheck="false">
        </div>
        <div class="field">
            <label class="field-label" for="advanced_environment">Environment UUID</label>
            <input id="advanced_environment" class="field-input" type="text" name="advanced_environment_uuid" value="{{ old('advanced_environment_uuid') }}" maxlength="64" autocomplete="off" spellcheck="false">
        </div>
        <div class="field">
            <label class="field-label" for="advanced_git">Git <code>kind:uuid</code></label>
            <input id="advanced_git" class="field-input" type="text" name="advanced_git_source" value="{{ old('advanced_git_source') }}" placeholder="github_app:… veya deploy_key:…" maxlength="96" autocomplete="off" spellcheck="false">
        </div>
    </details>
@endif

<div class="field">
    <label class="field-label" for="site_notes">Notes</label>
    <p class="field-hint">Internal ops notes. Never put secrets here. Compose dosyası sabit <code>/docker-compose.coolify.yml</code>.</p>
    <textarea
        id="site_notes"
        class="field-input field-textarea"
        name="notes"
        rows="4"
        maxlength="5000"
        @readonly($readonly)
    >{{ old('notes', $site->notes) }}</textarea>
    @error('notes') <p class="field-error">{{ $message }}</p> @enderror
</div>

@if ($site->exists)
    <div class="field">
        <span class="field-label">Status</span>
        <p class="field-hint">Draft and error sites can be provisioned. Not editable here.</p>
        <p class="status-chip status-{{ $site->status?->value }}">{{ $site->status?->value }}</p>
        @if ($site->coolify_app_uuid)
            <p class="field-hint">Coolify app <code>{{ $site->coolify_app_uuid }}</code> — yeniden create yok.</p>
        @endif
    </div>
@endif
