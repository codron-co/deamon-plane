@extends('layouts.ops')

@section('title', $site->name)

@section('content_class', 'ops-content-wide')

@section('breadcrumbs')
    <a href="{{ route('ops.sites') }}">{{ __('sites.title') }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ $site->name }}</span>
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.sites') }}">{{ __('sites.back_to_sites') }}</a>
    @if ($canEdit)
        <a class="btn btn-primary btn-sm" href="{{ route('ops.sites.edit', $site) }}">{{ __('sites.edit_site') }}</a>
    @endif
    @if ($canProvision ?? false)
        <form method="POST" action="{{ route('ops.sites.provision', $site) }}" data-ops-pending>
            @csrf
            <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.provision.button') }}</button>
        </form>
    @endif
    @if ($canCheckHealth ?? false)
        <form method="POST" action="{{ route('ops.sites.health', $site) }}" data-ops-pending>
            @csrf
            <button type="submit" class="btn btn-ghost btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.agent.check') }}</button>
        </form>
    @endif
@endsection

@section('content')
    @php
        $branch = $site->channel?->value ?? __('ops.none');
        $version = $site->reportedDeamonVersion();
        $themeId = $site->activeThemeInstallation?->theme?->theme_id;
        $latest = $deployments->first();
    @endphp

    <p class="page-lede">
        {{ __('sites.detail.lede', ['slug' => $site->slug]) }}
    </p>

    @if ($site->hasDockerfileBuildPackWarning())
        <p class="ops-alert ops-alert-warning" role="status">
            {{ __('sites.detail.dockerfile') }}
        </p>
    @endif

    @if (($canProvision ?? false) === false && ! ($readonly ?? false) && $site->status === \App\Enums\SiteStatus::Provisioning)
        <p class="ops-flash" role="status">{{ __('sites.provision.in_progress') }}</p>
    @endif

    <div class="ops-detail-grid">
        <section class="ops-detail-card">
            <span class="ops-detail-label">{{ __('sites.detail.status') }}</span>
            <div class="ops-detail-value">
                <span class="status-chip status-{{ $site->status?->value }}">{{ $site->status?->label() ?? __('ops.unknown') }}</span>
            </div>
        </section>

        <section class="ops-detail-card">
            <span class="ops-detail-label">{{ __('sites.detail.branch_version') }}</span>
            <div class="ops-detail-value branch-version">
                <span class="branch-chip">{{ $branch }}</span>
                <span class="version-chip">{{ $version ?: __('sites.detail.version_unknown') }}</span>
            </div>
        </section>

        <section class="ops-detail-card">
            <span class="ops-detail-label">{{ __('sites.detail.domain') }}</span>
            <div class="ops-detail-value"><code>{{ $site->primary_domain ?: __('ops.none') }}</code></div>
        </section>

        <section class="ops-detail-card">
            <span class="ops-detail-label">{{ __('sites.detail.theme') }}</span>
            <div class="ops-detail-value">{{ $themeId ?: __('ops.none') }}</div>
        </section>

        <section class="ops-detail-card">
            <span class="ops-detail-label">{{ __('sites.detail.connection') }}</span>
            <div class="ops-detail-value">
                @if ($site->coolifyConnection)
                    <a href="{{ route('ops.coolify.show', $site->coolifyConnection) }}">{{ $site->coolifyConnection->name }}</a>
                @else
                    {{ __('ops.none') }}
                @endif
            </div>
        </section>

        <section class="ops-detail-card">
            <span class="ops-detail-label">{{ __('sites.detail.last_health') }}</span>
            <div class="ops-detail-value">
                @if ($site->last_health_at)
                    <time datetime="{{ $site->last_health_at->toIso8601String() }}" title="{{ $site->last_health_at->toDateTimeString() }}">
                        {{ $site->last_health_at->diffForHumans() }}
                    </time>
                @else
                    {{ __('ops.none') }}
                @endif
            </div>
        </section>
    </div>

    <section class="ops-detail-section" aria-labelledby="site-runtime-heading">
        <h2 id="site-runtime-heading">{{ __('sites.detail.runtime') }}</h2>
        <dl class="spec-list">
            <div>
                <dt>{{ __('sites.detail.git') }}</dt>
                <dd><code>{{ $site->git_repository ?: __('ops.none') }}</code></dd>
            </div>
            <div>
                <dt>{{ __('sites.detail.app_uuid') }}</dt>
                <dd><code>{{ $site->coolify_app_uuid ?: __('ops.none') }}</code></dd>
            </div>
            <div>
                <dt>{{ __('sites.detail.server') }}</dt>
                <dd><code>{{ $site->coolify_server_uuid ?: __('ops.none') }}</code></dd>
            </div>
            <div>
                <dt>{{ __('sites.detail.project') }}</dt>
                <dd><code>{{ $site->coolify_project_uuid ?: __('ops.none') }}</code></dd>
            </div>
            <div>
                <dt>{{ __('sites.detail.environment') }}</dt>
                <dd><code>{{ $site->coolify_environment_uuid ?: __('ops.none') }}</code></dd>
            </div>
            @if ($latest)
                <div>
                    <dt>{{ __('sites.deployments.title') }}</dt>
                    <dd>
                        <span class="status-chip status-{{ $latest->status->value }}">{{ $latest->status->label() }}</span>
                        <span class="muted">{{ $latest->started_at?->diffForHumans() ?? __('ops.none') }}</span>
                    </dd>
                </div>
            @endif
        </dl>
    </section>

    @if (filled($site->notes))
        <section class="ops-detail-section" aria-labelledby="site-notes-heading">
            <h2 id="site-notes-heading">{{ __('sites.detail.notes') }}</h2>
            <p class="page-lede">{{ $site->notes }}</p>
        </section>
    @endif

    @include('ops.sites._channel-switch')

    @include('ops.sites._agent-health')

    @if ($canInjectAgentSecret ?? false)
        <section class="ops-panel" aria-labelledby="agent-secret-heading">
            <h2 id="agent-secret-heading">{{ __('sites.agent.inject_title') }}</h2>
            <p>{{ __('sites.agent.inject_lede', ['name' => 'CONTROL_PLANE_AGENT_SECRET']) }}</p>
            <form method="POST" action="{{ route('ops.sites.agent-secret', $site) }}" class="ops-form" data-ops-pending>
                @csrf
                <div class="form-actions">
                    <button type="submit" class="btn btn-secondary" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.agent.inject') }}</button>
                </div>
            </form>
        </section>
    @endif

    @include('ops.sites._themes')

    @include('ops.deployments.index')

    @if ($canDelete ?? false)
        <div class="danger-zone">
            <h2>{{ __('sites.danger.title') }}</h2>
            <p>{{ __('sites.danger.lede') }}</p>
            <form
                method="POST"
                action="{{ route('ops.sites.destroy', $site) }}"
                data-confirm="{{ __('sites.danger.confirm', ['name' => $site->name]) }}"
                data-confirm-title="{{ __('sites.danger.confirm_title') }}"
                data-confirm-label="{{ __('ops.actions.delete') }}"
            >
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">{{ __('sites.danger.button') }}</button>
            </form>
        </div>
    @endif
@endsection
