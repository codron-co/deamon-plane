@extends('layouts.ops')

@section('title', $site->name)

@section('content_class', 'ops-content-wide')

@section('breadcrumbs')
    <a href="{{ route('ops.sites') }}">{{ __('sites.title') }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ $site->name }}</span>
@endsection

@section('actions')
    @if ($canSyncCoolify ?? false)
        <form
            method="POST"
            action="{{ route('ops.sites.sync', $site) }}"
            data-ops-pending
            data-confirm="{{ __('sites.detail.sync_confirm', ['name' => $site->name]) }}"
            data-confirm-title="{{ __('sites.detail.sync_title') }}"
            data-confirm-label="{{ __('sites.detail.sync') }}"
            data-confirm-danger="false"
        >
            @csrf
            <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.detail.sync') }}</button>
        </form>
    @endif
    @if ($coolifyAppUrl)
        <a class="btn btn-ghost btn-sm" href="{{ $coolifyAppUrl }}" target="_blank" rel="noopener noreferrer">{{ __('sites.deployments.open_coolify') }}</a>
    @endif
    @if ($canEdit)
        <a class="btn btn-secondary btn-sm" href="{{ route('ops.sites.edit', $site) }}">{{ __('sites.edit_site') }}</a>
    @endif
    @if ($canCheckHealth ?? false)
        <form method="POST" action="{{ route('ops.sites.health', $site) }}" data-ops-pending>
            @csrf
            <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.agent.check') }}</button>
        </form>
    @elseif ($canProvision ?? false)
        <form
            method="POST"
            action="{{ route('ops.sites.provision', $site) }}"
            data-ops-pending
            data-confirm="{{ __('sites.provision.confirm', ['name' => $site->name]) }}"
            data-confirm-title="{{ __('sites.provision.confirm_title') }}"
            data-confirm-label="{{ __('sites.provision.button') }}"
        >
            @csrf
            <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.provision.button') }}</button>
        </form>
    @endif
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
        $healthDisplay = $agentHealth->displayStatus($site);
        $nameservers = is_array($site->cloudflare_nameservers) ? $site->cloudflare_nameservers : [];
    @endphp

    <header class="site-hero">
        <div class="site-hero-main">
            {{-- Identity mark: fetch favicon from the primary domain origin (`/favicon.ico`, then `/apple-touch-icon.png`). Keep the letter as the no-JS / failure fallback. Do not use a third-party icon CDN. --}}
            <div
                class="site-identity-mark"
                aria-hidden="true"
                @if (filled($primaryDomain))
                    data-favicon-host="{{ $primaryDomain }}"
                    data-favicon-fallback="{{ strtoupper(substr($site->name, 0, 1)) }}"
                @endif
            >{{ strtoupper(substr($site->name, 0, 1)) }}</div>
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
        @if ($canDelete ?? false)
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
                <h2 id="site-overview-heading">{{ __('sites.detail.overview') }} <button class="site-hint" type="button" aria-label="{{ __('sites.detail.overview_lede') }}"><span aria-hidden="true">i</span><span role="tooltip">{{ __('sites.detail.overview_lede') }}</span></button></h2>
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
                <div><span>{{ __('sites.detail.theme') }}</span><strong>{{ $themeId ?: __('ops.none') }}</strong><small>{{ __('sites.detail.theme_hint') }}</small></div>
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
                    <div><dt>{{ __('sites.detail.domain') }}</dt><dd>{{ $primaryDomain ?: __('ops.none') }}</dd></div>
                    <div><dt>{{ __('sites.detail.latest_deployment') }}</dt><dd>{{ $latest?->started_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? __('ops.none') }}</dd></div>
                </dl>
            </article>

            <aside class="site-card site-next-action">
                <div>
                    <span class="site-section-kicker">{{ __('sites.detail.next_action') }}</span>
                    @if ($site->status === \App\Enums\SiteStatus::Error)
                        <h3>{{ __('sites.detail.resolve_error') }}</h3>
                        <p>{{ $failure ?: __('sites.detail.resolve_error_hint') }}</p>
                    @elseif ($canProvision ?? false)
                        <h3>{{ __('sites.detail.provision_ready') }}</h3>
                        <p>{{ __('sites.detail.provision_ready_hint') }}</p>
                    @elseif ($healthDisplay !== 'ok' && ($canCheckHealth ?? false))
                        <h3>{{ __('sites.detail.health_attention') }}</h3>
                        <p>{{ __('sites.detail.health_attention_hint') }}</p>
                    @else
                        <h3>{{ __('sites.detail.no_action') }}</h3>
                        <p>{{ __('sites.detail.no_action_hint') }}</p>
                    @endif
                </div>
                @if ($site->status === \App\Enums\SiteStatus::Error)
                    <a class="btn btn-secondary btn-sm" href="#deployments">{{ __('sites.detail.inspect_deployments') }}</a>
                @elseif ($canProvision ?? false)
                    <form method="POST" action="{{ route('ops.sites.provision', $site) }}" data-ops-pending data-confirm="{{ __('sites.provision.confirm', ['name' => $site->name]) }}" data-confirm-title="{{ __('sites.provision.confirm_title') }}" data-confirm-label="{{ __('sites.provision.button') }}">@csrf<button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.provision.button') }}</button></form>
                @elseif ($healthDisplay !== 'ok' && ($canCheckHealth ?? false))
                    <form method="POST" action="{{ route('ops.sites.health', $site) }}" data-ops-pending>@csrf<button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.agent.check') }}</button></form>
                @elseif ($canEdit)
                    <a class="btn btn-ghost btn-sm" href="{{ route('ops.sites.edit', $site) }}">{{ __('sites.edit_site') }}</a>
                @endif
            </aside>
        </div>
    </section>

    <section id="deployments" class="site-section site-section-surface" role="tabpanel" data-site-panel aria-labelledby="site-tab-deployments">@include('ops.deployments.index')</section>
    <section id="theme" class="site-section site-section-surface" role="tabpanel" data-site-panel aria-labelledby="site-tab-theme">@include('ops.sites._themes')</section>

    <section id="infrastructure" class="site-section" role="tabpanel" data-site-panel aria-labelledby="site-tab-infrastructure site-infrastructure-heading">
        <div class="site-section-heading">
            <div><span class="site-section-kicker">{{ __('sites.detail.advanced') }}</span><h2 id="site-infrastructure-heading">{{ __('sites.detail.infrastructure') }} <button class="site-hint" type="button" aria-label="{{ __('sites.detail.infrastructure_lede') }}"><span aria-hidden="true">i</span><span role="tooltip">{{ __('sites.detail.infrastructure_lede') }}</span></button></h2></div>
        </div>
        <div class="site-operations-grid">
            <div class="site-operations-main">
                @include('ops.sites._mail')
                @include('ops.sites._channel-switch')
                @include('ops.sites._coolify-ops')
                @include('ops.sites._agent-health')
                @if (($canInjectAgentSecret ?? false) && ! $site->hasAgentSecret())
                    <article class="site-card site-operation" aria-labelledby="agent-secret-heading">
                        <div class="site-card-head">
                            <h3 id="agent-secret-heading">{{ __('sites.agent.inject_title') }} <button class="site-hint" type="button" aria-label="{{ __('sites.agent.inject_lede', ['name' => 'CONTROL_PLANE_AGENT_SECRET']) }}"><span aria-hidden="true">i</span><span role="tooltip">{{ __('sites.agent.inject_lede', ['name' => 'CONTROL_PLANE_AGENT_SECRET']) }}</span></button></h3>
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
                    <summary><span><strong>{{ __('sites.detail.technical_details') }}</strong><small>{{ __('sites.detail.technical_details_hint') }}</small></span><span class="site-disclosure-icon" aria-hidden="true"></span></summary>
                    <dl class="site-technical-list">
                        <div><dt>{{ __('sites.detail.app_uuid') }}</dt><dd><code>{{ $site->coolify_app_uuid ?: __('ops.none') }}</code></dd></div>
                        <div><dt>{{ __('sites.detail.server') }}</dt><dd><code>{{ $site->coolify_server_uuid ?: __('ops.none') }}</code></dd></div>
                        <div><dt>{{ __('sites.detail.project') }}</dt><dd><code>{{ $site->coolify_project_uuid ?: __('ops.none') }}</code></dd></div>
                        <div><dt>{{ __('sites.detail.environment') }}</dt><dd><code>{{ $site->coolify_environment_uuid ?: __('ops.none') }}</code></dd></div>
                    </dl>
                </details>
                @if (filled($site->notes))<article class="site-card"><span class="site-section-kicker">{{ __('sites.detail.notes') }}</span><p class="site-note">{{ $site->notes }}</p></article>@endif
                <article class="site-card">
                    <span class="site-section-kicker">{{ __('sites.detail.cloudflare') }}</span>
                    <dl class="site-fact-list is-compact">
                        <div><dt>{{ __('sites.detail.zone') }}</dt><dd>@if (filled($site->cloudflare_zone_id))<code>{{ $site->cloudflare_zone_id }}</code>@else{{ __('sites.detail.not_linked') }}@endif</dd></div>
                        <div><dt>{{ __('sites.detail.nameservers') }}</dt><dd>{{ $nameservers === [] ? __('ops.none') : implode(', ', $nameservers) }}</dd></div>
                        <div><dt>{{ __('sites.detail.dns_applied') }}</dt><dd>{{ $site->dns_applied_at?->toDateTimeString() ?? __('ops.none') }}</dd></div>
                    </dl>
                </article>
            </aside>
        </div>
    </section>

    @if ($canDelete ?? false)
        <section id="danger" class="site-section" role="tabpanel" data-site-panel aria-labelledby="site-tab-danger site-danger-heading">
            <article class="site-card danger-zone">
                <div>
                    <span class="site-section-kicker">{{ __('sites.detail.danger') }}</span>
                    <h3 id="site-danger-heading">{{ __('sites.danger.title') }}</h3>
                    <p>{{ __('sites.danger.lede') }}</p>
                </div>
                <form method="POST" action="{{ route('ops.sites.destroy', $site) }}" data-confirm="{{ __('sites.danger.confirm', ['name' => $site->name]) }}" data-confirm-title="{{ __('sites.danger.confirm_title') }}" data-confirm-label="{{ __('ops.actions.delete') }}">@csrf @method('DELETE')<button type="submit" class="btn btn-danger">{{ __('sites.danger.button') }}</button></form>
            </article>
        </section>
    @endif
@endsection

