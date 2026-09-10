@extends('layouts.ops')

@section('title', $theme->displayName())

@section('content_class', 'ops-content-wide')

@section('breadcrumbs')
    <a href="{{ route('ops.themes') }}">{{ __('themes.title') }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ $theme->displayName() }}</span>
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.themes') }}">{{ __('themes.back') }}</a>
    @if ($canWrite)
        <form method="POST" action="{{ route('ops.themes.sync') }}" data-ops-pending>
            @csrf
            <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('themes.sync') }}</button>
        </form>
    @endif
@endsection

@section('content')
    @php
        $failedInstallations = $theme->installations->filter(
            static fn ($installation) => $installation->status === \App\Enums\ThemeInstallationStatus::Error || filled($installation->last_error)
        );
        $activeInstallCount = $theme->installations->where('is_active', true)->count();
        $shaShort = $theme->latest_sha ? substr($theme->latest_sha, 0, 7) : null;
        $visibilityValue = old('visibility', $theme->visibility?->value);
        $defaultRefValue = old('default_ref', $theme->default_ref);
        $allowlistEmpty = $theme->allowedSites->isEmpty();
        $needsAllowlist = $theme->visibility === \App\Enums\ThemeVisibility::Allowlist && $allowlistEmpty;
        $syncIcon = $failedInstallations->isNotEmpty() ? 'is-error' : ($theme->last_synced_at ? 'is-ok' : '');
    @endphp

    <header class="site-hero">
        <div class="site-hero-main">
            <div class="site-identity-mark" aria-hidden="true">{{ strtoupper(substr($theme->displayName(), 0, 1)) }}</div>
            <div class="site-identity-copy">
                <div class="site-title-row">
                    <h2>{{ $theme->displayName() }}</h2>
                    <span class="status-chip">{{ $theme->visibility?->label() }}</span>
                </div>
                <div class="site-domain-row">
                    <code>{{ $theme->theme_id }}</code>
                    <span aria-hidden="true">·</span>
                    <span>{{ $theme->repo_full_name }}</span>
                </div>
            </div>
        </div>
        <div class="site-hero-meta">
            <span class="branch-chip">{{ $theme->default_ref ?: __('ops.none') }}</span>
            <span class="version-chip">{{ $shaShort ?: __('ops.unknown') }}</span>
        </div>
    </header>

    <nav class="site-section-nav" aria-label="{{ __('themes.tabs.label') }}" role="tablist" data-ops-tabs>
        <a class="is-active" href="#overview" role="tab" aria-selected="true" aria-controls="overview">{{ __('themes.tabs.overview') }}</a>
        <a href="#sites" role="tab" aria-selected="false" aria-controls="sites">{{ __('themes.tabs.sites') }}</a>
        <a href="#version" role="tab" aria-selected="false" aria-controls="version">{{ __('themes.tabs.version') }}</a>
        <a href="#sync" role="tab" aria-selected="false" aria-controls="sync">{{ __('themes.tabs.sync') }}</a>
        <a href="#operations" role="tab" aria-selected="false" aria-controls="operations">{{ __('themes.tabs.operations') }}</a>
    </nav>

    @if ($failedInstallations->isNotEmpty())
        @foreach ($failedInstallations as $failure)
            <p class="ops-alert site-banner" role="alert">
                <strong>{{ __('themes.show.failure_banner') }}</strong>
                {{ __('themes.show.failure_on', [
                    'site' => $failure->site?->name ?? __('themes.show.failure_unknown_site'),
                    'error' => $failure->last_error ?: ($failure->status?->label() ?? __('themes.show.no_error')),
                ]) }}
            </p>
        @endforeach
    @endif

    <section id="overview" class="site-section" role="tabpanel" data-ops-panel aria-labelledby="theme-overview-heading">
        <div class="site-section-heading">
            <div>
                <span class="site-section-kicker">{{ __('themes.show.overview_kicker') }}</span>
                <h2 id="theme-overview-heading">{{ __('themes.tabs.overview') }} <button class="site-hint" type="button" aria-label="{{ __('themes.show.overview_hint') }}"><span aria-hidden="true">i</span><span role="tooltip">{{ __('themes.show.overview_hint') }}</span></button></h2>
            </div>
        </div>

        <div class="site-metric-grid">
            <article class="site-metric">
                <div class="site-metric-icon is-theme" aria-hidden="true"><span></span></div>
                <div>
                    <span>{{ __('themes.show.visibility') }}</span>
                    <strong>{{ $theme->visibility?->label() ?? __('ops.unknown') }}</strong>
                    <small>{{ $theme->theme_id }}</small>
                </div>
            </article>
            <article class="site-metric">
                <div class="site-metric-icon is-connection" aria-hidden="true"><span></span></div>
                <div>
                    <span>{{ __('themes.show.install_count') }}</span>
                    <strong>{{ $theme->installations->count() }}</strong>
                    <small>{{ __('themes.show.active_count', ['active' => $activeInstallCount]) }}</small>
                </div>
            </article>
            <article class="site-metric">
                <div class="site-metric-icon {{ $syncIcon }}" aria-hidden="true"><span></span></div>
                <div>
                    <span>{{ __('themes.show.synced') }}</span>
                    <strong>{{ $theme->last_synced_at?->diffForHumans() ?? __('themes.sync_state.never') }}</strong>
                    <small>{{ $theme->last_synced_at?->toDateTimeString() ?? __('ops.never') }}</small>
                </div>
            </article>
            <article class="site-metric">
                <div class="site-metric-icon" aria-hidden="true"><span></span></div>
                <div>
                    <span>{{ __('themes.show.compat') }}</span>
                    <strong>{{ $theme->minimum_deamon_version ?: __('themes.show.min_none') }}</strong>
                    <small>{{ __('themes.show.min') }}</small>
                </div>
            </article>
        </div>

        <div class="site-overview-grid">
            <article class="site-card">
                <div class="site-card-head">
                    <div>
                        <span class="site-section-kicker">{{ __('themes.show.current') }}</span>
                        <h3>{{ __('themes.show.manifest') }}</h3>
                    </div>
                    <div class="branch-version">
                        <span class="branch-chip">{{ $theme->default_ref ?: __('ops.none') }}</span>
                        <span class="version-chip">{{ $shaShort ?: __('ops.unknown') }}</span>
                    </div>
                </div>
                <dl class="site-fact-list">
                    <div>
                        <dt>{{ __('themes.show.theme_id') }}</dt>
                        <dd><code>{{ $theme->theme_id }}</code></dd>
                    </div>
                    <div>
                        <dt>{{ __('themes.show.repo') }}</dt>
                        <dd><code>{{ $theme->repo_full_name }}</code></dd>
                    </div>
                    <div>
                        <dt>{{ __('themes.show.default_ref') }}</dt>
                        <dd><code>{{ $theme->default_ref ?: __('ops.none') }}</code></dd>
                    </div>
                    <div>
                        <dt>{{ __('themes.show.sha') }}</dt>
                        <dd><code>{{ $theme->latest_sha ?: __('ops.none') }}</code></dd>
                    </div>
                    @if (filled($theme->latest_tag))
                        <div>
                            <dt>{{ __('themes.show.tag') }}</dt>
                            <dd><code>{{ $theme->latest_tag }}</code></dd>
                        </div>
                    @endif
                    <div>
                        <dt>{{ __('themes.show.min') }}</dt>
                        <dd>{{ $theme->minimum_deamon_version ?: __('themes.show.min_none') }}</dd>
                    </div>
                    @if (filled($theme->description))
                        <div>
                            <dt>{{ __('themes.show.description') }}</dt>
                            <dd>{{ $theme->description }}</dd>
                        </div>
                    @endif
                </dl>
            </article>

            <aside class="site-card site-next-action">
                <span class="site-section-kicker">{{ __('themes.show.next_action') }}</span>
                @if ($failedInstallations->isNotEmpty())
                    <h3>{{ __('themes.show.next_failures') }}</h3>
                    <p>{{ __('themes.show.next_failures_hint') }}</p>
                    <a class="btn btn-secondary btn-sm" href="#sync">{{ __('themes.show.next_review_sync') }}</a>
                @elseif ($theme->last_synced_at === null && $canWrite)
                    <h3>{{ __('themes.show.next_never') }}</h3>
                    <p>{{ __('themes.show.next_never_hint') }}</p>
                    <form method="POST" action="{{ route('ops.themes.sync') }}" data-ops-pending>
                        @csrf
                        <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('themes.sync') }}</button>
                    </form>
                @elseif ($needsAllowlist && $canWrite)
                    <h3>{{ __('themes.show.next_allowlist') }}</h3>
                    <p>{{ __('themes.show.next_allowlist_hint') }}</p>
                    <a class="btn btn-secondary btn-sm" href="#sites">{{ __('themes.show.next_grant') }}</a>
                @else
                    <h3>{{ __('themes.show.next_none') }}</h3>
                    <p>{{ __('themes.show.next_none_hint') }}</p>
                    <a class="btn btn-ghost btn-sm" href="{{ route('ops.sites') }}">{{ __('themes.show.next_open_sites') }}</a>
                @endif
            </aside>
        </div>
    </section>

    <section id="sites" class="site-section" role="tabpanel" data-ops-panel aria-labelledby="theme-sites-heading">
        <div class="site-section-heading">
            <div>
                <span class="site-section-kicker">{{ __('themes.show.sites_kicker') }}</span>
                <h2 id="theme-sites-heading">{{ __('themes.show.sites_heading') }} <button class="site-hint" type="button" aria-label="{{ __('themes.show.sites_hint') }}"><span aria-hidden="true">i</span><span role="tooltip">{{ __('themes.show.sites_hint') }}</span></button></h2>
            </div>
        </div>

        <div class="site-operations-grid">
            <div class="site-operations-main">
                <article class="site-card">
                    <div class="site-card-head">
                        <div>
                            <span class="site-section-kicker">{{ __('themes.show.install_count') }}</span>
                            <h3>{{ __('themes.show.installs') }}</h3>
                        </div>
                    </div>
                    @if ($theme->installations->isEmpty())
                        <p class="field-hint">{{ __('themes.show.installs_empty') }}</p>
                    @else
                        <div class="sites-table-wrap">
                            <table class="ops-table">
                                <thead>
                                    <tr>
                                        <th>{{ __('themes.show.site') }}</th>
                                        <th>{{ __('themes.show.ref') }}</th>
                                        <th>{{ __('themes.show.status') }}</th>
                                        <th>{{ __('themes.show.active') }}</th>
                                        <th>{{ __('themes.show.auto_update') }}</th>
                                        <th>{{ __('themes.show.error') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($theme->installations as $installation)
                                        <tr @if ($installation->site) data-href="{{ route('ops.sites.show', $installation->site) }}" tabindex="0" @endif>
                                            <td>
                                                @if ($installation->site)
                                                    <a href="{{ route('ops.sites.show', $installation->site) }}">{{ $installation->site->name }}</a>
                                                    <div class="site-slug">{{ $installation->site->slug }}</div>
                                                @else
                                                    {{ __('ops.none') }}
                                                @endif
                                            </td>
                                            <td>
                                                <code>{{ $installation->ref }}</code>
                                                @if ($installation->pinned_sha)
                                                    <div class="site-slug">{{ substr($installation->pinned_sha, 0, 7) }}</div>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="status-chip {{ $installation->status === \App\Enums\ThemeInstallationStatus::Error ? 'status-error' : ($installation->is_active ? 'status-active' : '') }}">{{ $installation->status?->label() ?? $installation->status?->value }}</span>
                                            </td>
                                            <td>{{ $installation->is_active ? __('ops.yes') : __('ops.no') }}</td>
                                            <td>{{ $installation->auto_update ? __('ops.on') : __('ops.off') }}</td>
                                            <td>
                                                @if (filled($installation->last_error))
                                                    <span class="status-chip status-error" title="{{ $installation->last_error }}">{{ $installation->last_error }}</span>
                                                @else
                                                    {{ __('ops.none') }}
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </article>
            </div>

            <aside class="site-operations-aside">
                <article class="site-card">
                    <div class="site-card-head">
                        <div>
                            <span class="site-section-kicker">{{ __('themes.show.allowlist_count') }}</span>
                            <h3>{{ __('themes.show.allowlist') }}</h3>
                        </div>
                    </div>
                    @if ($allowlistEmpty)
                        <p class="field-hint">{{ __('themes.show.allowlist_empty') }}</p>
                    @else
                        <table class="ops-table">
                            <thead>
                                <tr>
                                    <th>{{ __('themes.show.site') }}</th>
                                    <th>{{ __('themes.show.slug') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($theme->allowedSites as $allowed)
                                    <tr data-href="{{ route('ops.sites.show', $allowed) }}" tabindex="0">
                                        <td><a href="{{ route('ops.sites.show', $allowed) }}">{{ $allowed->name }}</a></td>
                                        <td><code>{{ $allowed->slug }}</code></td>
                                        <td class="ops-row-actions">
                                            @if ($canWrite)
                                                <form
                                                    method="POST"
                                                    action="{{ route('ops.themes.access.destroy', [$theme, $allowed]) }}"
                                                    data-confirm="{{ __('themes.show.revoke_confirm', ['name' => $allowed->name]) }}"
                                                    data-confirm-title="{{ __('themes.show.revoke_title') }}"
                                                    data-confirm-label="{{ __('themes.show.remove') }}"
                                                >
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-ghost btn-sm btn-danger-text">{{ __('themes.show.remove') }}</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif

                    @if ($canWrite)
                        <form method="POST" action="{{ route('ops.themes.access.store', $theme) }}" class="ops-form settings-form">
                            @csrf
                            <div class="field">
                                <label class="field-label" for="theme-access-site">{{ __('themes.show.add_site') }}</label>
                                <select id="theme-access-site" class="field-input" name="site_id" required>
                                    <option value="">{{ __('themes.show.select_site') }}</option>
                                    @foreach ($sites as $candidate)
                                        <option value="{{ $candidate->id }}">{{ $candidate->name }} ({{ $candidate->slug }})</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-secondary">{{ __('themes.show.grant') }}</button>
                            </div>
                        </form>
                    @endif
                </article>
            </aside>
        </div>
    </section>

    <section id="version" class="site-section" role="tabpanel" data-ops-panel aria-labelledby="theme-version-heading">
        <div class="site-section-heading">
            <div>
                <span class="site-section-kicker">{{ __('themes.show.version_kicker') }}</span>
                <h2 id="theme-version-heading">{{ __('themes.show.version_heading') }} <button class="site-hint" type="button" aria-label="{{ __('themes.show.version_hint') }}"><span aria-hidden="true">i</span><span role="tooltip">{{ __('themes.show.version_hint') }}</span></button></h2>
            </div>
        </div>

        <div class="site-overview-grid">
            <article class="site-card">
                <div class="site-card-head">
                    <div>
                        <span class="site-section-kicker">{{ __('themes.show.current') }}</span>
                        <h3>{{ __('themes.show.default_ref') }}</h3>
                    </div>
                    <div class="branch-version">
                        <span class="branch-chip">{{ $theme->default_ref ?: __('ops.none') }}</span>
                        <span class="version-chip">{{ $shaShort ?: __('ops.unknown') }}</span>
                    </div>
                </div>
                <dl class="site-fact-list">
                    <div>
                        <dt>{{ __('themes.show.default_ref') }}</dt>
                        <dd><code>{{ $theme->default_ref ?: __('ops.none') }}</code></dd>
                    </div>
                    <div>
                        <dt>{{ __('themes.show.sha') }}</dt>
                        <dd><code>{{ $theme->latest_sha ?: __('ops.none') }}</code></dd>
                    </div>
                    <div>
                        <dt>{{ __('themes.show.tag') }}</dt>
                        <dd><code>{{ $theme->latest_tag ?: __('ops.none') }}</code></dd>
                    </div>
                    <div>
                        <dt>{{ __('themes.show.compat') }}</dt>
                        <dd>
                            {{ $theme->minimum_deamon_version ?: __('themes.show.min_none') }}
                            <button class="site-hint" type="button" aria-label="{{ __('themes.show.compat_hint') }}"><span aria-hidden="true">i</span><span role="tooltip">{{ __('themes.show.compat_hint') }}</span></button>
                        </dd>
                    </div>
                </dl>
            </article>

            @if ($canWrite)
                <article class="site-card">
                    <span class="site-section-kicker">{{ __('themes.show.version_kicker') }}</span>
                    <h3>{{ __('themes.show.save_ref') }}</h3>
                    <form method="POST" action="{{ route('ops.themes.update', $theme) }}" class="ops-form settings-form">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="visibility" value="{{ $visibilityValue }}">
                        <div class="field">
                            <label class="field-label" for="theme-default-ref">{{ __('themes.show.default_ref') }}</label>
                            <input id="theme-default-ref" class="field-input" type="text" name="default_ref" value="{{ $defaultRefValue }}">
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">{{ __('themes.show.save_ref') }}</button>
                        </div>
                    </form>
                </article>
            @endif
        </div>
    </section>

    <section id="sync" class="site-section" role="tabpanel" data-ops-panel aria-labelledby="theme-sync-heading">
        <div class="site-section-heading">
            <div>
                <span class="site-section-kicker">{{ __('themes.show.sync_kicker') }}</span>
                <h2 id="theme-sync-heading">{{ __('themes.show.sync_heading') }} <button class="site-hint" type="button" aria-label="{{ __('themes.show.sync_hint') }}"><span aria-hidden="true">i</span><span role="tooltip">{{ __('themes.show.sync_hint') }}</span></button></h2>
            </div>
        </div>

        <div class="site-overview-grid">
            <article class="site-card">
                <div class="site-card-head">
                    <div>
                        <span class="site-section-kicker">{{ __('themes.show.catalog_sync') }}</span>
                        <h3>{{ __('themes.show.synced') }}</h3>
                    </div>
                    <span class="status-chip {{ $theme->last_synced_at ? 'status-active' : '' }}">{{ $theme->last_synced_at?->diffForHumans() ?? __('themes.sync_state.never') }}</span>
                </div>
                <dl class="site-fact-list">
                    <div>
                        <dt>{{ __('themes.show.synced') }}</dt>
                        <dd>{{ $theme->last_synced_at?->toDateTimeString() ?? __('ops.never') }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('themes.show.sha') }}</dt>
                        <dd><code>{{ $theme->latest_sha ?: __('ops.none') }}</code></dd>
                    </div>
                    <div>
                        <dt>{{ __('themes.show.repo') }}</dt>
                        <dd><code>{{ $theme->repo_full_name }}</code></dd>
                    </div>
                </dl>
                @if ($canWrite)
                    <form method="POST" action="{{ route('ops.themes.sync') }}" class="ops-form" data-ops-pending>
                        @csrf
                        <p class="field-hint">{{ __('themes.show.catalog_sync_hint') }}</p>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" data-pending-label="{{ __('ops.actions.working') }}">{{ __('themes.sync') }}</button>
                        </div>
                    </form>
                @endif
            </article>

            <article class="site-card">
                <span class="site-section-kicker">{{ __('themes.show.failure_banner') }}</span>
                <h3>{{ __('themes.show.installs') }}</h3>
                @if ($failedInstallations->isEmpty())
                    <p>{{ __('themes.show.failures_empty') }}</p>
                @else
                    <dl class="site-fact-list">
                        @foreach ($failedInstallations as $failure)
                            <div>
                                <dt>{{ $failure->site?->name ?? __('themes.show.failure_unknown_site') }}</dt>
                                <dd>
                                    <span class="status-chip status-error">{{ $failure->status?->label() }}</span>
                                    {{ $failure->last_error ?: __('themes.show.no_error') }}
                                    @if ($failure->updated_from_webhook_at)
                                        <div class="site-slug">{{ __('themes.show.webhook') }} · {{ $failure->updated_from_webhook_at->toDateTimeString() }}</div>
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </article>
        </div>
    </section>

    <section id="operations" class="site-section" role="tabpanel" data-ops-panel aria-labelledby="theme-operations-heading">
        <div class="site-section-heading">
            <div>
                <span class="site-section-kicker">{{ __('themes.show.operations_kicker') }}</span>
                <h2 id="theme-operations-heading">{{ __('themes.show.operations_heading') }} <button class="site-hint" type="button" aria-label="{{ __('themes.show.operations_hint') }}"><span aria-hidden="true">i</span><span role="tooltip">{{ __('themes.show.operations_hint') }}</span></button></h2>
            </div>
        </div>

        <div class="site-operations-grid">
            <div class="site-operations-main">
                <article class="site-card">
                    <span class="site-section-kicker">{{ __('themes.show.visibility') }}</span>
                    <h3>{{ __('themes.show.catalog_visibility') }} <button class="site-hint" type="button" aria-label="{{ __('themes.show.visibility_hint') }}"><span aria-hidden="true">i</span><span role="tooltip">{{ __('themes.show.visibility_hint') }}</span></button></h3>
                    @if ($canWrite)
                        <form method="POST" action="{{ route('ops.themes.update', $theme) }}" class="ops-form settings-form">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="default_ref" value="{{ $defaultRefValue }}">
                            <div class="field">
                                <label class="field-label" for="theme-visibility">{{ __('themes.show.catalog_visibility') }}</label>
                                <select id="theme-visibility" class="field-input" name="visibility">
                                    @foreach ($visibilities as $option)
                                        <option value="{{ $option->value }}" @selected($visibilityValue === $option->value)>{{ $option->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">{{ __('themes.show.save') }}</button>
                            </div>
                        </form>
                    @else
                        <dl class="site-fact-list">
                            <div>
                                <dt>{{ __('themes.show.visibility') }}</dt>
                                <dd>{{ $theme->visibility?->label() }}</dd>
                            </div>
                        </dl>
                    @endif
                </article>

                <article class="site-card">
                    <span class="site-section-kicker">{{ __('themes.show.next_action') }}</span>
                    <h3>{{ __('themes.show.assign_on_site') }}</h3>
                    <p>{{ __('themes.show.assign_on_site_hint') }}</p>
                    <a class="btn btn-ghost btn-sm" href="{{ route('ops.sites') }}">{{ __('themes.show.next_open_sites') }}</a>
                </article>
            </div>

            <aside class="site-operations-aside">
                <details class="site-technical-card">
                    <summary>
                        <span>
                            <strong>{{ __('themes.show.technical') }}</strong>
                            <small>{{ __('themes.show.technical_hint') }}</small>
                        </span>
                        <span class="site-disclosure-icon" aria-hidden="true"></span>
                    </summary>
                    <dl class="site-technical-list">
                        <div>
                            <dt>{{ __('themes.show.record_id') }}</dt>
                            <dd><code>{{ $theme->id }}</code></dd>
                        </div>
                        <div>
                            <dt>{{ __('themes.show.theme_id') }}</dt>
                            <dd><code>{{ $theme->theme_id }}</code></dd>
                        </div>
                        <div>
                            <dt>{{ __('themes.show.github') }}</dt>
                            <dd><code>{{ $theme->githubHttpsUrl() }}</code></dd>
                        </div>
                    </dl>
                </details>
            </aside>
        </div>
    </section>
@endsection

@section('scripts')
    <script>
        (() => {
            const tabs = [...document.querySelectorAll('[data-ops-tabs] [role="tab"]')];
            const panels = [...document.querySelectorAll('[data-ops-panel]')];
            if (! tabs.length || ! panels.length) return;

            const activate = (id, updateHash = true) => {
                if (! panels.some((panel) => panel.id === id)) id = panels[0].id;
                tabs.forEach((tab) => {
                    const active = tab.getAttribute('aria-controls') === id;
                    tab.classList.toggle('is-active', active);
                    tab.setAttribute('aria-selected', active ? 'true' : 'false');
                    tab.tabIndex = active ? 0 : -1;
                });
                panels.forEach((panel) => { panel.hidden = panel.id !== id; });
                if (updateHash) history.replaceState(null, '', `#${id}`);
            };

            tabs.forEach((tab, index) => {
                tab.addEventListener('click', (event) => {
                    event.preventDefault();
                    activate(tab.getAttribute('aria-controls'));
                });
                tab.addEventListener('keydown', (event) => {
                    if (! ['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
                    event.preventDefault();
                    const target = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
                    tabs[target].focus();
                    activate(tabs[target].getAttribute('aria-controls'));
                });
            });

            document.addEventListener('click', (event) => {
                const link = event.target.closest('a[href^="#"]');
                if (! link || link.closest('[data-ops-tabs]')) {
                    return;
                }
                const id = (link.getAttribute('href') || '').replace(/^#/, '');
                if (! panels.some((panel) => panel.id === id)) {
                    return;
                }
                event.preventDefault();
                activate(id);
            });

            activate(location.hash.slice(1), false);
        })();
    </script>
@endsection
