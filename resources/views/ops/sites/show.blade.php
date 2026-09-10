@extends('layouts.ops')

@section('title', $site->name)

@section('content_class', 'ops-content-wide')

@section('breadcrumbs')
    <a href="{{ route('ops.sites') }}">{{ __('sites.title') }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ $site->name }}</span>
@endsection

@section('actions')
    @include('ops.sites._header-actions')
@endsection

@section('content')
    @php
        $branch = $site->channel?->value ?? __('ops.none');
        $version = $site->reportedDeamonVersion();
        $themeId = $site->reportedActiveThemeId();
        $latest = $deployments->first();
        $failure = $site->lastFailureMessage();
        $primaryDomain = $site->primary_domain;
        $siteUrl = filled($primaryDomain) ? 'https://'.$primaryDomain : null;
        $adminUrl = $siteUrl ? $siteUrl.'/admin' : null;
        $healthDisplay = $agentHealth->displayStatus($site);
    @endphp

    <header class="site-hero">
        <div class="site-hero-main">
            {{-- Identity mark: fetch favicon from the primary domain origin (`/favicon.ico`, then `/apple-touch-icon.png`). Keep the letter as the no-JS / failure fallback. Do not use a third-party icon CDN. --}}
            <div
                class="site-identity-mark"
                aria-hidden="true"
                @if (filled($primaryDomain))
                    data-favicon-host="{{ $primaryDomain }}"
                    data-favicon-fallback="{{ $site->identityMarkLetter() }}"
                    @if (filled($site->last_live_favicon_url))
                        data-favicon-src="{{ $site->last_live_favicon_url }}"
                    @endif
                @endif
            >{{ $site->identityMarkLetter() }}</div>
            <div class="site-identity-copy">
                <div class="site-title-row">
                    <h2>{{ $site->name }}</h2>
                    <span class="status-chip status-{{ $site->status?->value }}">{{ $site->status?->label() ?? __('ops.unknown') }}</span>
                </div>
                <div class="site-domain-row">
                    @if ($siteUrl)
                        <a href="{{ $siteUrl }}" target="_blank" rel="noopener noreferrer">{{ $primaryDomain }}</a>
                    @else
                        <span>{{ __('ops.none') }}</span>
                    @endif
                    <span aria-hidden="true">·</span>
                    <span>{{ $site->slug }}</span>
                </div>
            </div>
        </div>
        <div class="site-hero-meta" aria-label="{{ __('sites.detail.release') }}">
            <span class="branch-chip">{{ $branch }}</span>
            <span class="version-chip">{{ $version ?: __('sites.detail.version_unknown') }}</span>
        </div>
    </header>

    <nav class="site-section-nav" aria-label="{{ __('sites.detail.sections') }}" role="tablist" data-site-tabs>
        <a id="site-tab-overview" class="is-active" href="#overview" role="tab" aria-selected="true" aria-controls="overview">{{ __('sites.detail.overview') }}</a>
        <a id="site-tab-deployments" href="#deployments" role="tab" aria-selected="false" aria-controls="deployments">{{ __('sites.deployments.title') }}</a>
        <a id="site-tab-theme" href="#theme" role="tab" aria-selected="false" aria-controls="theme">{{ __('sites.themes.title') }}</a>
        <a id="site-tab-infrastructure" href="#infrastructure" role="tab" aria-selected="false" aria-controls="infrastructure">{{ __('sites.detail.infrastructure') }}</a>
        @if (($canDelete ?? false) || ($canForceDelete ?? false))
            <a id="site-tab-danger" href="#danger" role="tab" aria-selected="false" aria-controls="danger">{{ __('sites.detail.danger') }}</a>
        @endif
    </nav>

    @if ($site->hasDockerfileBuildPackWarning())
        <p class="ops-alert ops-alert-warning site-banner" role="status">{{ __('sites.detail.dockerfile') }}</p>
    @endif

    @if ($site->status === \App\Enums\SiteStatus::Error && filled($failure))
        <p class="ops-alert site-banner" role="alert">{{ $failure }}</p>
    @endif

    @if (($canProvision ?? false) === false && ! ($readonly ?? false) && $site->status === \App\Enums\SiteStatus::Provisioning)
        <p class="ops-flash site-banner" role="status">{{ __('sites.provision.in_progress') }}</p>
    @endif

    <section id="overview" class="site-section" role="tabpanel" data-site-panel aria-labelledby="site-tab-overview site-overview-heading">
        <div class="site-section-heading">
            <div>
                <span class="site-section-kicker">{{ __('sites.detail.operation') }}</span>
                <h2 id="site-overview-heading">{{ __('sites.detail.overview') }} @include('ops.dashboard._hint', ['text' => __('sites.detail.overview_lede')])</h2>
            </div>
        </div>

        <div class="site-metric-grid">
            <article class="site-metric">
                <div class="site-metric-icon is-{{ $healthDisplay }}" aria-hidden="true"><span></span></div>
                <div><span>{{ __('sites.agent.title') }}</span><strong>{{ __('ops.health.'.$healthDisplay) }}</strong><small>{{ $site->last_health_at?->diffForHumans() ?? __('ops.never') }}</small></div>
            </article>
            <article class="site-metric">
                <div class="site-metric-icon is-deploy" aria-hidden="true"><span></span></div>
                <div><span>{{ __('sites.detail.latest_deployment') }}</span><strong>{{ $latest?->status?->label() ?? __('ops.none') }}</strong><small>{{ $latest?->started_at?->diffForHumans() ?? __('sites.deployments.empty_short') }}</small></div>
            </article>
            <article class="site-metric">
                <div class="site-metric-icon is-theme" aria-hidden="true"><span></span></div>
                <div><span>{{ __('sites.detail.theme') }} @include('ops.dashboard._hint', ['text' => __('sites.detail.theme_hint')])</span><strong>{{ $themeId ?: __('ops.none') }}</strong></div>
            </article>
            <article class="site-metric">
                <div class="site-metric-icon is-connection" aria-hidden="true"><span></span></div>
                <div>
                    <span>{{ __('sites.detail.connection') }}</span>
                    <strong>@if ($site->coolifyConnection)<a href="{{ route('ops.coolify.show', $site->coolifyConnection) }}">{{ $site->coolifyConnection->name }}</a>@else{{ __('ops.none') }}@endif</strong>
                    <small>{{ filled($site->coolify_app_uuid) ? __('sites.detail.connected') : __('sites.detail.not_connected') }}</small>
                </div>
            </article>
            <article class="site-metric">
                <div class="site-metric-icon is-theme" aria-hidden="true"><span></span></div>
                <div>
                    <span>{{ __('sites.detail.mail') }}</span>
                    <strong>
                        @if ($site->mailServer)
                            <a href="{{ route('ops.mail-servers.show', $site->mailServer) }}">{{ $site->mailServer->name }}</a>
                        @else
                            {{ __('ops.none') }}
                        @endif
                    </strong>
                    <small>
                        @if ($site->hasHostingerMailOrder())
                            {{ $site->mail_domain }}
                        @elseif ($site->mailServer)
                            {{ __('mail.sites.unmatched') }}
                        @else
                            {{ __('sites.detail.mail_none') }}
                        @endif
                    </small>
                    @if ($canEdit ?? false)
                        <a href="#infrastructure">{{ __('mail.orders.change') }}</a>
                    @endif
                </div>
            </article>
        </div>

        <div class="site-overview-grid">
            <article class="site-card site-release-card">
                <div class="site-card-head">
                    <div><span class="site-section-kicker">{{ __('sites.detail.current_release') }}</span><h3>{{ __('sites.detail.release') }}</h3></div>
                    <div class="branch-version"><span class="branch-chip">{{ $branch }}</span><span class="version-chip">{{ $version ?: __('sites.detail.version_unknown') }}</span></div>
                </div>
                <dl class="site-fact-list">
                    <div><dt>{{ __('sites.detail.git') }}</dt><dd><code>{{ $site->git_repository ?: __('ops.none') }}</code></dd></div>
                    <div>
                        <dt>{{ __('sites.detail.domain') }}</dt>
                        <dd>
                            @if ($siteUrl && $adminUrl)
                                <a href="{{ $siteUrl }}" target="_blank" rel="noopener noreferrer">{{ $primaryDomain }}</a>
                                <span aria-hidden="true">·</span>
                                <a href="{{ $adminUrl }}" target="_blank" rel="noopener noreferrer">{{ __('sites.detail.open_admin') }}</a>
                            @else
                                {{ $primaryDomain ?: __('ops.none') }}
                            @endif
                        </dd>
                    </div>
                    <div><dt>{{ __('sites.detail.latest_deployment') }}</dt><dd>{{ $latest?->started_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? __('ops.none') }}</dd></div>
                </dl>
            </article>

            @if ($site->status === \App\Enums\SiteStatus::Error || ($canProvision ?? false) || ($healthDisplay !== 'ok' && ($canCheckHealth ?? false)))
                <aside class="site-card site-next-action">
                    <div>
                        <span class="site-section-kicker">{{ __('sites.detail.next_action') }}</span>
                        @if ($site->status === \App\Enums\SiteStatus::Error)
                            <h3>{{ __('sites.detail.resolve_error') }} @include('ops.dashboard._hint', ['text' => $failure ?: __('sites.detail.resolve_error_hint')])</h3>
                        @elseif ($canProvision ?? false)
                            <h3>{{ __('sites.detail.provision_ready') }} @include('ops.dashboard._hint', ['text' => __('sites.detail.provision_ready_hint')])</h3>
                        @else
                            <h3>{{ __('sites.detail.health_attention') }} @include('ops.dashboard._hint', ['text' => __('sites.detail.health_attention_hint')])</h3>
                        @endif
                    </div>
                    @if ($site->status === \App\Enums\SiteStatus::Error)
                        <a class="btn btn-secondary btn-sm" href="#deployments">{{ __('sites.detail.inspect_deployments') }}</a>
                    @elseif ($canProvision ?? false)
                        <form method="POST" action="{{ route('ops.sites.provision', $site) }}" data-ops-pending data-confirm="{{ __('sites.provision.confirm', ['name' => $site->name]) }}" data-confirm-title="{{ __('sites.provision.confirm_title') }}" data-confirm-label="{{ __('sites.provision.button') }}">@csrf<button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.provision.button') }}</button></form>
                    @else
                        <form method="POST" action="{{ route('ops.sites.health', $site) }}" data-ops-pending>@csrf<button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.agent.check') }}</button></form>
                    @endif
                </aside>
            @endif
        </div>
    </section>

    <section id="deployments" class="site-section site-section-surface" role="tabpanel" data-site-panel aria-labelledby="site-tab-deployments">@include('ops.deployments.index')</section>
    <section id="theme" class="site-section site-section-surface" role="tabpanel" data-site-panel aria-labelledby="site-tab-theme">@include('ops.sites._themes')</section>

    <section id="infrastructure" class="site-section" role="tabpanel" data-site-panel aria-labelledby="site-tab-infrastructure site-infrastructure-heading">
        <div class="site-section-heading">
            <div><span class="site-section-kicker">{{ __('sites.detail.advanced') }}</span><h2 id="site-infrastructure-heading">{{ __('sites.detail.infrastructure') }} @include('ops.dashboard._hint', ['text' => __('sites.detail.infrastructure_lede')])</h2></div>
        </div>
        <div class="site-operations-grid">
            <div class="site-operations-main">
                @include('ops.sites._cloudflare')
                @include('ops.sites._mail')
                @include('ops.sites._channel-switch')
                @include('ops.sites._coolify-ops')
                @include('ops.sites._agent-health')
                @if (($canInjectAgentSecret ?? false) && ! $site->hasAgentSecret())
                    <article class="site-card site-operation" aria-labelledby="agent-secret-heading">
                        <div class="site-card-head">
                            <h3 id="agent-secret-heading">{{ __('sites.agent.inject_title') }} @include('ops.dashboard._hint', ['text' => __('sites.agent.inject_lede', ['name' => 'CONTROL_PLANE_AGENT_SECRET'])])</h3>
                            <form
                                method="POST"
                                action="{{ route('ops.sites.agent-secret', $site) }}"
                                data-ops-pending
                                data-confirm="{{ __('sites.agent.inject_confirm', ['name' => $site->name]) }}"
                                data-confirm-title="{{ __('sites.agent.inject_title') }}"
                                data-confirm-label="{{ __('sites.agent.inject') }}"
                            >@csrf<button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.agent.inject') }}</button></form>
                        </div>
                    </article>
                @endif
            </div>
            <aside class="site-operations-aside">
                <details class="site-technical-card">
                    <summary><span><strong>{{ __('sites.detail.technical_details') }}</strong> @include('ops.dashboard._hint', ['text' => __('sites.detail.technical_details_hint')])</span><span class="site-disclosure-icon" aria-hidden="true"></span></summary>
                    <dl class="site-technical-list">
                        <div><dt>{{ __('sites.detail.app_uuid') }}</dt><dd><code>{{ $site->coolify_app_uuid ?: __('ops.none') }}</code></dd></div>
                        <div><dt>{{ __('sites.detail.server') }}</dt><dd><code>{{ $site->coolify_server_uuid ?: __('ops.none') }}</code></dd></div>
                        <div><dt>{{ __('sites.detail.project') }}</dt><dd><code>{{ $site->coolify_project_uuid ?: __('ops.none') }}</code></dd></div>
                        <div><dt>{{ __('sites.detail.environment') }}</dt><dd><code>{{ $site->coolify_environment_uuid ?: __('ops.none') }}</code></dd></div>
                    </dl>
                </details>
                @if (filled($site->notes))<article class="site-card"><span class="site-section-kicker">{{ __('sites.detail.notes') }}</span><p class="site-note">{{ $site->notes }}</p></article>@endif
            </aside>
        </div>
    </section>

    @if (($canDelete ?? false) || ($canForceDelete ?? false))
        <section id="danger" class="site-section" role="tabpanel" data-site-panel aria-labelledby="site-tab-danger site-danger-heading">
            <article class="site-card danger-zone">
                <div>
                    <span class="site-section-kicker">{{ __('sites.detail.danger') }}</span>
                    <h3 id="site-danger-heading">{{ __('sites.danger.title') }} @include('ops.dashboard._hint', ['text' => __('sites.danger.lede')])</h3>
                </div>
                <div class="danger-zone-actions">
                    @if ($canDelete ?? false)
                        <form method="POST" action="{{ route('ops.sites.destroy', $site) }}" data-confirm="{{ __('sites.danger.confirm', ['name' => $site->name]) }}" data-confirm-title="{{ __('sites.danger.confirm_title') }}" data-confirm-label="{{ __('sites.menu.soft_delete') }}">@csrf @method('DELETE')<button type="submit" class="btn btn-secondary">{{ __('sites.menu.soft_delete') }}</button></form>
                    @endif
                    @if ($canForceDelete ?? false)
                        <form method="POST" action="{{ route('ops.sites.purge', $site) }}" data-confirm="{{ __('sites.danger.hard_confirm', ['name' => $site->name]) }}" data-confirm-title="{{ __('sites.danger.hard_confirm_title') }}" data-confirm-label="{{ __('sites.menu.hard_delete') }}">@csrf @method('DELETE')<button type="submit" class="btn btn-danger">{{ __('sites.menu.hard_delete') }}</button></form>
                    @endif
                </div>
            </article>
        </section>
    @endif
@endsection

@section('scripts')
    <script src="{{ asset('js/sites-cloudflare.js') }}?v={{ filemtime(public_path('js/sites-cloudflare.js')) }}" defer></script>
@endsection
