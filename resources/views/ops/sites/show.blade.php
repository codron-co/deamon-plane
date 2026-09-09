@extends('layouts.ops')

@section('title', $site->name)

@section('content_class', 'ops-content-wide')

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.sites') }}">Back to sites</a>
    @if ($canEdit)
        <a class="btn btn-primary btn-sm" href="{{ route('ops.sites.edit', $site) }}">Edit site</a>
    @endif
@endsection

@section('content')
    @php
        $branch = $site->channel?->value ?? '—';
        $version = $site->reportedDeamonVersion();
        $themeId = $site->activeThemeInstallation?->theme?->theme_id;
    @endphp

    <p class="page-lede">
        Operational overview for <code>{{ $site->slug }}</code>. Edit is a separate action; list rows always land on this detail page.
    </p>

    @if ($site->hasDockerfileBuildPackWarning())
        <p class="ops-alert ops-alert-warning" role="status">
            Coolify is still using the legacy Dockerfile build pack. Migrate this app to the Docker Compose path when scheduled.
        </p>
    @endif

    <div class="ops-detail-grid">
        <section class="ops-detail-card">
            <span class="ops-detail-label">Status</span>
            <div class="ops-detail-value">
                <span class="status-chip status-{{ $site->status?->value }}">{{ $site->status?->value ?? 'unknown' }}</span>
            </div>
        </section>

        <section class="ops-detail-card">
            <span class="ops-detail-label">Repo branch · version</span>
            <div class="ops-detail-value branch-version">
                <span class="branch-chip">{{ $branch }}</span>
                <span class="version-chip">{{ $version ?: 'Unknown version' }}</span>
            </div>
        </section>

        <section class="ops-detail-card">
            <span class="ops-detail-label">Primary domain</span>
            <div class="ops-detail-value"><code>{{ $site->primary_domain ?: '—' }}</code></div>
        </section>

        <section class="ops-detail-card">
            <span class="ops-detail-label">Active theme</span>
            <div class="ops-detail-value">{{ $themeId ?: '—' }}</div>
        </section>

        <section class="ops-detail-card">
            <span class="ops-detail-label">Coolify connection</span>
            <div class="ops-detail-value">{{ $site->coolifyConnection?->name ?: '—' }}</div>
        </section>

        <section class="ops-detail-card">
            <span class="ops-detail-label">Last health check</span>
            <div class="ops-detail-value">
                @if ($site->last_health_at)
                    <time datetime="{{ $site->last_health_at->toIso8601String() }}" title="{{ $site->last_health_at->toDateTimeString() }}">
                        {{ $site->last_health_at->diffForHumans() }}
                    </time>
                @else
                    —
                @endif
            </div>
        </section>
    </div>

    <section class="ops-detail-section" aria-labelledby="site-runtime-heading">
        <h2 id="site-runtime-heading">Runtime / deployment target</h2>
        <dl class="spec-list">
            <div>
                <dt>Git repository</dt>
                <dd><code>{{ $site->git_repository ?: '—' }}</code></dd>
            </div>
            <div>
                <dt>Coolify app UUID</dt>
                <dd><code>{{ $site->coolify_app_uuid ?: '—' }}</code></dd>
            </div>
            <div>
                <dt>Server UUID</dt>
                <dd><code>{{ $site->coolify_server_uuid ?: '—' }}</code></dd>
            </div>
            <div>
                <dt>Project UUID</dt>
                <dd><code>{{ $site->coolify_project_uuid ?: '—' }}</code></dd>
            </div>
            <div>
                <dt>Environment UUID</dt>
                <dd><code>{{ $site->coolify_environment_uuid ?: '—' }}</code></dd>
            </div>
        </dl>
    </section>

    @if (filled($site->notes))
        <section class="ops-detail-section" aria-labelledby="site-notes-heading">
            <h2 id="site-notes-heading">Notes</h2>
            <p class="page-lede">{{ $site->notes }}</p>
        </section>
    @endif
@endsection
