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
    $advancedErrorKeys = [
        'advanced_server_uuid',
        'advanced_project_uuid',
        'advanced_environment_uuid',
        'advanced_git_source',
    ];
    $advancedOpen = $errors->hasAny($advancedErrorKeys)
        || collect($advancedErrorKeys)->contains(fn (string $key): bool => filled(old($key)));
@endphp

@if ($errors->any())
    <p class="ops-alert" role="alert">{{ __('sites.form.errors') }}</p>
@endif

@if ($site->channel_needs_review)
    <p class="ops-alert ops-alert-warning" role="status">
        {{ __('sites.form.channel_review') }}
    </p>
@endif

<section class="ops-form-section" aria-labelledby="site-identity-heading">
    <h2 id="site-identity-heading">{{ __('sites.form.identity') }}</h2>

    <div class="field">
        <label class="field-label" for="site_slug">{{ __('sites.form.slug') }}</label>
        <p class="field-hint">{{ __('sites.form.slug_hint') }}</p>
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
        <label class="field-label" for="site_name">{{ __('sites.form.name') }}</label>
        <p class="field-hint">{{ __('sites.form.name_hint') }}</p>
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
</section>

<section class="ops-form-section" aria-labelledby="site-domain-heading">
    <h2 id="site-domain-heading">{{ __('sites.form.domain_heading') }}</h2>

    <div class="field">
        <label class="field-label" for="site_domain">{{ __('sites.form.domain') }}</label>
        <p class="field-hint">{{ __('sites.form.domain_hint') }}</p>
        <input
            id="site_domain"
            class="field-input"
            type="text"
            name="domain"
            value="{{ $currentDomain }}"
            autocomplete="off"
            maxlength="255"
            placeholder="{{ __('sites.form.domain_placeholder') }}"
            @required(! $readonly)
            @readonly($readonly)
        >
        @error('domain') <p class="field-error">{{ $message }}</p> @enderror
    </div>
</section>

<section class="ops-form-section" aria-labelledby="site-placement-heading">
    <h2 id="site-placement-heading">{{ __('sites.form.placement') }}</h2>

    <div class="field">
        <label class="field-label" for="site_connection">{{ __('sites.form.connection') }}</label>
        <p class="field-hint">{!! __('sites.form.connection_hint', ['link' => '<a href="'.route('ops.coolify.index').'">'.e(__('sites.form.connection_link')).'</a>']) !!}</p>
        <select
            id="site_connection"
            class="field-input"
            name="coolify_connection_id"
            data-coolify-connection
            data-options-template="{{ url('/coolify') }}/__id__/options"
            @disabled($readonly)
        >
            <option value="">{{ __('ops.none') }}</option>
            @foreach ($coolifyConnections as $row)
                <option value="{{ $row->id }}" @selected((string) $selectedConnectionId === (string) $row->id)>
                    {{ $row->name }}{{ $row->is_default ? ' ('.__('ops.default').')' : '' }}
                </option>
            @endforeach
        </select>
        @error('coolify_connection_id') <p class="field-error">{{ $message }}</p> @enderror
    </div>

    @if (! $site->exists)
        <fieldset class="coolify-placement" data-coolify-placement>
            <legend class="field-label">{{ __('sites.form.install') }}</legend>
            <p class="field-hint">{{ __('sites.form.install_hint') }}</p>
            <label class="field-check">
                <input type="radio" name="placement" value="provision" @checked($placement === 'provision') @disabled($readonly)>
                {{ __('sites.form.provision_new') }}
            </label>
            <label class="field-check">
                <input type="radio" name="placement" value="attach" @checked($placement === 'attach') @disabled($readonly)>
                {{ __('sites.form.attach_existing') }}
            </label>

            <div class="field" data-attach-field @if ($placement !== 'attach') hidden @endif>
                <label class="field-label" for="attach_app">{{ __('sites.form.attach_app') }}</label>
                <p class="field-hint">{{ __('sites.form.attach_hint', ['repo' => 'codron-co/deamon']) }}</p>
                <select
                    id="attach_app"
                    class="field-input"
                    name="attach_app_uuid"
                    data-attach-apps
                    data-needs-review-label="{{ __('sites.form.needs_review') }}"
                    @disabled($readonly || $placement !== 'attach')
                >
                    <option value="">{{ __('ops.none') }}</option>
                    @foreach ($attachableApps as $app)
                        <option value="{{ $app['uuid'] }}" @selected(old('attach_app_uuid') === $app['uuid'])>
                            {{ $app['name'] }}
                            @if ($app['domain']) — {{ $app['domain'] }} @endif
                            @if ($app['branch']) ({{ $app['branch'] }}) @endif
                            @if ($app['needs_review']) · {{ __('sites.form.needs_review') }} @endif
                        </option>
                    @endforeach
                </select>
                <p class="field-hint" data-attach-empty @if (count($attachableApps) > 0) hidden @endif>
                    {{ __('sites.form.attach_empty') }}
                </p>
                @error('attach_app_uuid') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </fieldset>
    @endif

    <div class="field" data-provision-field>
        <label class="field-label" for="site_server">{{ __('sites.form.server') }}</label>
        <p class="field-hint">{{ __('sites.form.server_hint') }}</p>
        <select id="site_server" class="field-input" name="coolify_server_uuid" data-coolify-servers @disabled($readonly)>
            <option value="">{{ __('ops.none') }}</option>
            @foreach ($coolifyServers as $server)
                <option value="{{ $server->uuid }}" title="{{ $server->uuid }}" @selected($selectedServer === $server->uuid)>{{ $server->label() }}</option>
            @endforeach
        </select>
        @error('coolify_server_uuid') <p class="field-error">{{ $message }}</p> @enderror
    </div>

    <div class="field" data-provision-field>
        <label class="field-label" for="site_project">{{ __('sites.form.project') }}</label>
        <select id="site_project" class="field-input" name="coolify_project_uuid" data-coolify-projects @disabled($readonly)>
            <option value="">{{ __('ops.none') }}</option>
            @foreach ($coolifyProjects as $project)
                <option value="{{ $project->uuid }}" title="{{ $project->uuid }}" @selected($selectedProject === $project->uuid)>{{ $project->label() }}</option>
            @endforeach
        </select>
        @error('coolify_project_uuid') <p class="field-error">{{ $message }}</p> @enderror
    </div>

    <div class="field" data-provision-field>
        <label class="field-label" for="site_environment">{{ __('sites.form.environment') }}</label>
        <p class="field-hint">{{ __('sites.form.environment_hint') }}</p>
        <select
            id="site_environment"
            class="field-input"
            name="coolify_environment_uuid"
            data-coolify-environments
            data-environment-options="{{ Js::from($coolifyEnvironmentOptions ?? []) }}"
            @disabled($readonly)
        >
            <option value="">{{ __('ops.none') }}</option>
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
        <label class="field-label" for="site_git">{{ __('sites.form.git_source') }}</label>
        <p class="field-hint">
            {{ __('sites.form.git_hint') }}
            @if ($githubAppsListAvailable === false)
                {{ __('sites.form.git_hint_no_apps') }}
            @endif
        </p>
        <select id="site_git" class="field-input" name="coolify_git_source" data-coolify-git @disabled($readonly)>
            <option value="">{{ __('sites.form.git_public') }}</option>
            @foreach ($coolifyGitSources as $source)
                <option value="{{ $source->formValue() }}" title="{{ $source->uuid }}" @selected($selectedGit === $source->formValue())>{{ $source->label() }}</option>
            @endforeach
        </select>
        @error('coolify_git_source') <p class="field-error">{{ $message }}</p> @enderror
    </div>
</section>

<section class="ops-form-section" aria-labelledby="site-git-heading">
    <h2 id="site-git-heading">{{ __('sites.form.git') }}</h2>

    <div class="field">
        <label class="field-label" for="site_channel">{{ __('sites.form.repo_branch') }}</label>
        <p class="field-hint">{{ __('sites.form.repo_branch_hint') }}</p>
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
</section>

@if ($isSuperAdmin && ! $readonly)
    <details class="coolify-advanced ops-form-section" @if ($advancedOpen) open @endif>
        <summary>{{ __('sites.form.advanced_summary') }}</summary>
        <p class="ops-alert ops-alert-warning">{{ __('sites.form.advanced_warning') }}</p>
        <div class="field">
            <label class="field-label" for="advanced_server">{{ __('sites.form.advanced_server') }}</label>
            <p class="field-hint">{{ __('sites.form.advanced_hint') }}</p>
            <input id="advanced_server" class="field-input" type="text" name="advanced_server_uuid" value="{{ old('advanced_server_uuid') }}" maxlength="64" autocomplete="off" spellcheck="false">
            @error('advanced_server_uuid') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label class="field-label" for="advanced_project">{{ __('sites.form.advanced_project') }}</label>
            <p class="field-hint">{{ __('sites.form.advanced_hint') }}</p>
            <input id="advanced_project" class="field-input" type="text" name="advanced_project_uuid" value="{{ old('advanced_project_uuid') }}" maxlength="64" autocomplete="off" spellcheck="false">
            @error('advanced_project_uuid') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label class="field-label" for="advanced_environment">{{ __('sites.form.advanced_environment') }}</label>
            <p class="field-hint">{{ __('sites.form.advanced_hint') }}</p>
            <input id="advanced_environment" class="field-input" type="text" name="advanced_environment_uuid" value="{{ old('advanced_environment_uuid') }}" maxlength="64" autocomplete="off" spellcheck="false">
            @error('advanced_environment_uuid') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label class="field-label" for="advanced_git">{{ __('sites.form.advanced_git') }}</label>
            <p class="field-hint">{{ __('sites.form.advanced_hint') }}</p>
            <input id="advanced_git" class="field-input" type="text" name="advanced_git_source" value="{{ old('advanced_git_source') }}" placeholder="github_app:… / deploy_key:…" maxlength="96" autocomplete="off" spellcheck="false">
            @error('advanced_git_source') <p class="field-error">{{ $message }}</p> @enderror
        </div>
    </details>
@endif

<section class="ops-form-section" aria-labelledby="site-mail-heading">
    <h2 id="site-mail-heading">{{ __('sites.form.mail') }}</h2>
    <div class="field">
        <label class="field-label" for="site_mail_server">{{ __('sites.form.mail_server') }}</label>
        <p class="field-hint">{{ __('mail.select_hint') }}</p>
        <select id="site_mail_server" name="mail_server_id" @disabled($readonly)>
            <option value="">{{ __('mail.none') }}</option>
            @foreach ($mailServers ?? [] as $mailServer)
                <option value="{{ $mailServer->id }}" @selected((string) old('mail_server_id', $site->mail_server_id) === (string) $mailServer->id)>
                    {{ $mailServer->name }}
                </option>
            @endforeach
        </select>
        @if ($site->exists && $site->hasHostingerMailOrder())
            <p class="field-hint">{{ __('mail.fields.site_order', ['order' => $site->hostinger_order_id, 'domain' => $site->mail_domain]) }}</p>
        @elseif ($site->exists && filled($site->mail_server_id))
            <p class="field-hint">{{ __('mail.sites.unmatched') }}</p>
        @endif
        @error('mail_server_id') <p class="field-error">{{ $message }}</p> @enderror
    </div>
</section>

<section class="ops-form-section" aria-labelledby="site-notes-heading">
    <h2 id="site-notes-heading">{{ __('sites.form.notes') }}</h2>
    <div class="field">
        <label class="field-label" for="site_notes">{{ __('sites.form.notes_label') }}</label>
        <p class="field-hint">{{ __('sites.form.notes_hint', ['compose' => '/docker-compose.coolify.yml']) }}</p>
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
            <span class="field-label">{{ __('sites.form.status') }}</span>
            <p class="field-hint">{{ __('sites.form.status_hint') }}</p>
            <p class="status-chip status-{{ $site->status?->value }}">{{ $site->status?->label() }}</p>
            @if ($site->coolify_app_uuid)
                <p class="field-hint">{{ __('sites.form.coolify_app', ['uuid' => $site->coolify_app_uuid]) }}</p>
            @endif
        </div>
    @endif
</section>
