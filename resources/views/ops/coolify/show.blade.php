@extends('layouts.ops')

@section('title', $connection->name)

@section('content_class', 'ops-content-wide')

@section('breadcrumbs')
    <a href="{{ route('ops.coolify.index') }}">{{ __('coolify.title') }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ $connection->name }}</span>
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.coolify.index') }}">{{ __('coolify.back') }}</a>
    @if ($canWrite)
        <form method="POST" action="{{ route('ops.coolify.sync', $connection) }}" data-ops-pending>
            @csrf
            <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('coolify.show.sync') }}</button>
        </form>
    @endif
@endsection

@section('content')
    @php
        $hasToken = $connection->hasToken();
        $syncedAt = $connection->last_synced_at;
        $serverCount = $connection->servers->count();
        $activeServers = $connection->servers->where('is_active', true)->count();
        $projectCount = $connection->projects->count();
        $linkedSitesCount = $connection->sites()->count();
        if ($connection->is_default) {
            $linkedSitesCount += \App\Models\Site::query()->whereNull('coolify_connection_id')->count();
        }
        $health = ! $connection->is_enabled
            ? 'disabled'
            : (! $hasToken ? 'no_token' : ($syncedAt ? 'ok' : 'never_synced'));
        $healthIcon = $health === 'ok' ? 'ok' : ($health === 'never_synced' ? 'connection' : 'error');
        $defaultServer = $connection->servers->firstWhere('uuid', $connection->default_server_uuid);
        $defaultProject = $connection->projects->firstWhere('uuid', $connection->default_project_uuid);
        $defaultEnv = $connection->environments->firstWhere('uuid', $connection->default_environment_uuid);
        $defaultGit = $connection->gitSources->firstWhere('uuid', $connection->default_git_source_uuid);
        $configErrors = $errors->hasAny([
            'name', 'base_url', 'api_token', 'webhook_secret', 'is_enabled', 'is_default',
            'default_server_uuid', 'default_project_uuid', 'default_environment_uuid', 'default_git_source',
        ]);
        $initialTab = $configErrors ? 'configuration' : '';
    @endphp

    <header class="site-hero">
        <div class="site-hero-main">
            <div class="site-identity-mark" aria-hidden="true">{{ strtoupper(substr($connection->name, 0, 1)) }}</div>
            <div class="site-identity-copy">
                <div class="site-title-row">
                    <h2>{{ $connection->name }}</h2>
                    <span class="status-chip status-{{ $connection->is_enabled ? 'active' : 'error' }}">
                        {{ $connection->is_enabled ? __('coolify.enabled') : __('coolify.disabled') }}
                    </span>
                    @if ($connection->is_default)
                        <span class="ops-chip">{{ __('ops.default') }}</span>
                    @endif
                </div>
                <div class="site-domain-row">
                    @if (filled($connection->base_url))
                        <a href="{{ $connection->base_url }}" target="_blank" rel="noopener noreferrer">{{ $connection->base_url }}</a>
                    @else
                        <span>{{ __('ops.none') }}</span>
                    @endif
                </div>
            </div>
        </div>
    </header>

    <nav class="site-section-nav" aria-label="{{ __('coolify.tabs.aria') }}" role="tablist" data-site-tabs data-initial-tab="{{ $initialTab }}">
        <a class="is-active" href="#overview" role="tab" aria-selected="true" aria-controls="overview">{{ __('coolify.tabs.overview') }}</a>
        <a href="#inventory" role="tab" aria-selected="false" aria-controls="inventory">{{ __('coolify.tabs.inventory') }}</a>
        <a href="#configuration" role="tab" aria-selected="false" aria-controls="configuration">{{ __('coolify.tabs.configuration') }}</a>
        @if ($canWrite)
            <a href="#danger" role="tab" aria-selected="false" aria-controls="danger">{{ __('coolify.tabs.danger') }}</a>
        @endif
    </nav>

    @if ($connection->github_apps_list_available === false)
        <p class="ops-alert ops-alert-warning site-banner" role="status">
            {{ __('coolify.show.github_apps_missing') }}
        </p>
    @endif

    <section id="overview" class="site-section" role="tabpanel" data-site-panel aria-labelledby="coolify-overview-heading">
        <div class="site-section-heading">
            <div>
                <span class="site-section-kicker">{{ __('coolify.title') }}</span>
                <h2 id="coolify-overview-heading">{{ __('coolify.tabs.overview') }} @include('ops.coolify._hint', ['text' => __('coolify.show.lede')])</h2>
            </div>
        </div>

        <div class="site-metric-grid">
            <article class="site-metric">
                <div class="site-metric-icon is-{{ $healthIcon }}" aria-hidden="true"><span></span></div>
                <div>
                    <span>{{ __('coolify.metrics.health') }}</span>
                    <strong>{{ __('coolify.health.'.$health) }}</strong>
                    <small>{{ $connection->is_enabled ? __('coolify.enabled') : __('coolify.disabled') }}</small>
                </div>
            </article>
            <article class="site-metric">
                <div class="site-metric-icon is-deploy" aria-hidden="true"><span></span></div>
                <div>
                    <span>{{ __('coolify.metrics.last_sync') }}</span>
                    <strong>{{ $syncedAt?->diffForHumans() ?? __('ops.never') }}</strong>
                    <small>{{ $syncedAt?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? __('ops.none') }}</small>
                </div>
            </article>
            <article class="site-metric">
                <div class="site-metric-icon is-theme" aria-hidden="true"><span></span></div>
                <div>
                    <span>{{ __('coolify.metrics.inventory') }}</span>
                    <strong>{{ __('coolify.servers_active', ['active' => $activeServers, 'total' => $serverCount]) }}</strong>
                    <small>{{ __('coolify.metrics.inventory_note', ['servers' => $serverCount, 'projects' => $projectCount]) }}</small>
                </div>
            </article>
            <article class="site-metric">
                <div class="site-metric-icon is-connection" aria-hidden="true"><span></span></div>
                <div>
                    <span>{{ __('coolify.metrics.sites') }}</span>
                    <strong>{{ $linkedSitesCount }}</strong>
                    <small>{{ __('coolify.detail.linked_sites') }}</small>
                </div>
            </article>
        </div>

        <div class="site-overview-grid">
            <article class="site-card site-release-card">
                <div class="site-card-head">
                    <div>
                        <span class="site-section-kicker">{{ __('coolify.show.connection') }}</span>
                        <h3>{{ $connection->name }}</h3>
                    </div>
                </div>
                <dl class="site-fact-list">
                    <div>
                        <dt>{{ __('coolify.facts.url') }}</dt>
                        <dd>{{ $connection->base_url ?: __('ops.none') }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('coolify.facts.token') }}</dt>
                        <dd>{{ $hasToken ? __('coolify.facts.token_present') : __('coolify.facts.token_missing') }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('coolify.facts.webhook') }}</dt>
                        <dd><code>{{ $webhookUrl }}</code></dd>
                    </div>
                    <div>
                        <dt>{{ __('coolify.facts.default_server') }}</dt>
                        <dd>{{ $defaultServer?->label() ?: __('ops.none') }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('coolify.facts.default_project') }}</dt>
                        <dd>{{ $defaultProject?->label() ?: __('ops.none') }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('coolify.facts.default_env') }}</dt>
                        <dd>{{ $defaultEnv?->label() ?: __('ops.none') }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('coolify.facts.default_git') }}</dt>
                        <dd>{{ $defaultGit?->label() ?: __('ops.none') }}</dd>
                    </div>
                </dl>
            </article>

            <aside class="site-card site-next-action">
                <span class="site-section-kicker">{{ __('coolify.next.kicker') }}</span>
                @if (! $hasToken)
                    <h3>{{ __('coolify.next.token_title') }}</h3>
                    <p>{{ __('coolify.next.token_hint') }}</p>
                    @if ($canWrite)
                        <a class="btn btn-primary btn-sm" href="#configuration">{{ __('coolify.next.configure') }}</a>
                    @endif
                @elseif (! $connection->is_enabled)
                    <h3>{{ __('coolify.next.enable_title') }}</h3>
                    <p>{{ __('coolify.next.enable_hint') }}</p>
                    @if ($canWrite)
                        <a class="btn btn-primary btn-sm" href="#configuration">{{ __('coolify.next.configure') }}</a>
                    @endif
                @elseif (! $syncedAt)
                    <h3>{{ __('coolify.next.sync_title') }}</h3>
                    <p>{{ __('coolify.next.sync_hint') }}</p>
                    @if ($canWrite)
                        <form method="POST" action="{{ route('ops.coolify.sync', $connection) }}" data-ops-pending>
                            @csrf
                            <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('coolify.show.sync') }}</button>
                        </form>
                    @endif
                @elseif ($canWrite)
                    <h3>{{ __('coolify.next.test_title') }}</h3>
                    <p>{{ __('coolify.next.test_hint') }}</p>
                    <form method="POST" action="{{ route('ops.coolify.test', $connection) }}" data-ops-pending>
                        @csrf
                        <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('coolify.show.test') }}</button>
                    </form>
                @else
                    <h3>{{ __('coolify.next.none_title') }}</h3>
                    <p>{{ __('coolify.next.none_hint') }}</p>
                    <a class="btn btn-ghost btn-sm" href="#inventory">{{ __('coolify.next.inventory') }}</a>
                @endif
            </aside>
        </div>
    </section>

    <section id="inventory" class="site-section" role="tabpanel" data-site-panel aria-labelledby="coolify-inventory-heading">
        <div class="site-section-heading">
            <div>
                <span class="site-section-kicker">{{ __('coolify.tabs.inventory') }}</span>
                <h2 id="coolify-inventory-heading">{{ __('coolify.tabs.inventory') }} @include('ops.coolify._hint', ['text' => __('coolify.show.defaults_lede')])</h2>
            </div>
        </div>

        @include('ops.coolify._allowlist', [
            'title' => __('coolify.allowlist.servers'),
            'hint' => __('coolify.allowlist.servers_hint'),
            'rows' => $connection->servers,
            'toggleRoute' => 'ops.coolify.servers.toggle',
            'param' => 'server',
            'extra' => 'ip',
            'canWrite' => $canWrite,
            'connection' => $connection,
        ])

        @include('ops.coolify._allowlist', [
            'title' => __('coolify.allowlist.projects'),
            'hint' => __('coolify.allowlist.projects_hint'),
            'rows' => $connection->projects,
            'toggleRoute' => 'ops.coolify.projects.toggle',
            'param' => 'project',
            'canWrite' => $canWrite,
            'connection' => $connection,
        ])

        @include('ops.coolify._allowlist', [
            'title' => __('coolify.allowlist.environments'),
            'hint' => __('coolify.allowlist.environments_hint'),
            'rows' => $connection->environments,
            'toggleRoute' => 'ops.coolify.environments.toggle',
            'param' => 'environment',
            'extra' => 'project_uuid',
            'canWrite' => $canWrite,
            'connection' => $connection,
        ])

        @include('ops.coolify._allowlist', [
            'title' => __('coolify.allowlist.git'),
            'hint' => __('coolify.allowlist.git_hint'),
            'rows' => $connection->gitSources,
            'toggleRoute' => 'ops.coolify.git-sources.toggle',
            'param' => 'source',
            'extra' => 'kind',
            'canWrite' => $canWrite,
            'connection' => $connection,
        ])
    </section>

    <section id="configuration" class="site-section" role="tabpanel" data-site-panel aria-labelledby="coolify-configuration-heading">
        <div class="site-section-heading">
            <div>
                <span class="site-section-kicker">{{ __('coolify.tabs.configuration') }}</span>
                <h2 id="coolify-configuration-heading">{{ __('coolify.tabs.configuration') }} @include('ops.coolify._hint', ['text' => __('coolify.show.lede')])</h2>
            </div>
        </div>

        <div class="site-operations-grid">
            <div class="site-operations-main">
                <section class="settings-panel" aria-labelledby="coolify-creds-heading">
                    <h2 id="coolify-creds-heading">{{ __('coolify.show.connection') }}</h2>
                    <form method="POST" action="{{ route('ops.coolify.update', $connection) }}" class="ops-form settings-form">
                        @csrf
                        @method('PUT')
                        @include('ops.coolify._connection-fields', ['connection' => $connection, 'canWrite' => $canWrite, 'requireToken' => false])

                        <div class="field">
                            <span class="field-label">{{ __('coolify.show.webhook') }}</span>
                            <input class="field-input" type="text" value="{{ $webhookUrl }}" readonly>
                        </div>

                        @if ($canWrite)
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">{{ __('coolify.show.save') }}</button>
                            </div>
                        @else
                            <p class="field-hint">{{ __('ops.viewer_readonly') }}</p>
                        @endif
                    </form>
                    @if ($canWrite)
                        <div class="form-actions">
                            <form method="POST" action="{{ route('ops.coolify.test', $connection) }}" data-ops-pending>
                                @csrf
                                <button type="submit" class="btn btn-secondary" data-pending-label="{{ __('ops.actions.working') }}">{{ __('coolify.show.test') }}</button>
                            </form>
                            <form method="POST" action="{{ route('ops.coolify.sync', $connection) }}" data-ops-pending>
                                @csrf
                                <button type="submit" class="btn btn-secondary" data-pending-label="{{ __('ops.actions.working') }}">{{ __('coolify.show.sync') }}</button>
                            </form>
                            @unless ($connection->is_default)
                                <form method="POST" action="{{ route('ops.coolify.default', $connection) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-ghost">{{ __('coolify.show.make_default') }}</button>
                                </form>
                            @endunless
                        </div>
                    @endif
                </section>

                <section class="ops-panel" aria-labelledby="coolify-defaults-heading">
                    <h2 id="coolify-defaults-heading">{{ __('coolify.show.defaults') }} @include('ops.coolify._hint', ['text' => __('coolify.show.defaults_lede')])</h2>
                    <form method="POST" action="{{ route('ops.coolify.update', $connection) }}" class="ops-form settings-form">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="name" value="{{ $connection->name }}">
                        <input type="hidden" name="base_url" value="{{ $connection->base_url }}">
                        <input type="hidden" name="is_enabled" value="{{ $connection->is_enabled ? '1' : '0' }}">
                        <input type="hidden" name="is_default" value="{{ $connection->is_default ? '1' : '0' }}">

                        <div class="field">
                            <label class="field-label" for="default-server">{{ __('coolify.show.default_server') }}</label>
                            <select id="default-server" class="field-input" name="default_server_uuid" data-coolify-servers @disabled(! $canWrite)>
                                <option value="">{{ __('ops.none') }}</option>
                                @foreach ($connection->servers->where('is_active', true) as $server)
                                    <option value="{{ $server->uuid }}" title="{{ $server->uuid }}" @selected($connection->default_server_uuid === $server->uuid)>{{ $server->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label class="field-label" for="default-project">{{ __('coolify.show.default_project') }}</label>
                            <select id="default-project" class="field-input" name="default_project_uuid" data-coolify-projects @disabled(! $canWrite)>
                                <option value="">{{ __('ops.none') }}</option>
                                @foreach ($connection->projects->where('is_active', true) as $project)
                                    <option value="{{ $project->uuid }}" title="{{ $project->uuid }}" @selected($connection->default_project_uuid === $project->uuid)>{{ $project->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label class="field-label" for="default-env">{{ __('coolify.show.default_env') }}</label>
                            <select
                                id="default-env"
                                class="field-input"
                                name="default_environment_uuid"
                                data-coolify-environments
                                data-environment-options="{{ Js::from($environmentOptions ?? []) }}"
                                @disabled(! $canWrite)
                            >
                                <option value="">{{ __('ops.none') }}</option>
                                @foreach ($connection->environmentsForProject($connection->default_project_uuid) as $environment)
                                    <option
                                        value="{{ $environment->uuid }}"
                                        data-project="{{ $environment->project_uuid }}"
                                        title="{{ $environment->uuid }}"
                                        @selected($connection->default_environment_uuid === $environment->uuid)
                                    >{{ $environment->label() }}</option>
                                @endforeach
                            </select>
                            <input type="hidden" name="default_environment_name" value="{{ $connection->default_environment_name }}">
                        </div>
                        <div class="field">
                            <label class="field-label" for="default-git">{{ __('coolify.show.default_git') }} @include('ops.coolify._hint', ['text' => __('coolify.show.default_git_hint')])</label>
                            <select id="default-git" class="field-input" name="default_git_source" @disabled(! $canWrite)>
                                <option value="">{{ __('coolify.show.git_none') }}</option>
                                @foreach ($connection->gitSources->where('is_active', true) as $source)
                                    <option value="{{ $source->formValue() }}" @selected($connection->default_git_source_uuid === $source->uuid && (string) $connection->default_git_source_kind?->value === $source->kind->value)>{{ $source->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if ($canWrite)
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">{{ __('coolify.show.save_defaults') }}</button>
                            </div>
                        @endif
                    </form>
                </section>
            </div>

            <aside class="site-operations-aside">
                <details class="site-technical-card">
                    <summary>
                        <span>
                            <strong>{{ __('coolify.technical.title') }}</strong>
                            <small>{{ __('coolify.technical.hint') }}</small>
                        </span>
                        <span class="site-disclosure-icon" aria-hidden="true"></span>
                    </summary>
                    <dl class="site-technical-list">
                        <div><dt>{{ __('coolify.technical.connection_id') }}</dt><dd><code>{{ $connection->id }}</code></dd></div>
                        <div><dt>{{ __('coolify.technical.default_server') }}</dt><dd><code>{{ $connection->default_server_uuid ?: __('ops.none') }}</code></dd></div>
                        <div><dt>{{ __('coolify.technical.default_project') }}</dt><dd><code>{{ $connection->default_project_uuid ?: __('ops.none') }}</code></dd></div>
                        <div><dt>{{ __('coolify.technical.default_env') }}</dt><dd><code>{{ $connection->default_environment_uuid ?: __('ops.none') }}</code></dd></div>
                        <div><dt>{{ __('coolify.technical.default_git') }}</dt><dd><code>{{ $connection->default_git_source_uuid ?: __('ops.none') }}</code></dd></div>
                    </dl>
                </details>
            </aside>
        </div>
    </section>

    @if ($canWrite)
        <section id="danger" class="site-section" role="tabpanel" data-site-panel>
            <div class="danger-zone">
                <div>
                    <h2>{{ __('coolify.danger.title') }}</h2>
                    <p>{{ __('coolify.danger.lede') }}</p>
                </div>
                <form
                    method="POST"
                    action="{{ route('ops.coolify.destroy', $connection) }}"
                    data-confirm="{{ __('coolify.danger.confirm', ['name' => $connection->name]) }}"
                    data-confirm-title="{{ __('coolify.danger.confirm_title') }}"
                    data-confirm-label="{{ __('coolify.danger.label') }}"
                >
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger">{{ __('coolify.danger.button') }}</button>
                </form>
            </div>
        </section>
    @endif
@endsection

@section('scripts')
    <script src="{{ asset('js/ops-coolify-form.js') }}" defer></script>
    @include('ops.coolify._resource-tabs')
@endsection
