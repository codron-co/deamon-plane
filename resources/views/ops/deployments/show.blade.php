@extends('layouts.ops')

@section('title', __('sites.deployments.title').' '.($deployment->shortSha() !== '' ? $deployment->shortSha() : ($deployment->status?->value ?? '')))

@section('content_class', 'ops-content-wide')

@section('breadcrumbs')
    <a href="{{ route('ops.sites') }}">{{ __('sites.title') }}</a>
    <span aria-hidden="true">/</span>
    <a href="{{ route('ops.sites.show', $site) }}">{{ $site->name }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ __('sites.deployments.title') }}</span>
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.sites.show', $site) }}">{{ __('sites.back_to_site') }}</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.sites.edit', $site) }}">{{ __('sites.deployments.site_edit') }}</a>
@endsection

@section('content')
    @php
        $statusValue = $deployment->status?->value;
        $statusOk = $statusValue === 'finished';
        $statusFail = in_array($statusValue, ['failed', 'cancelled'], true);
        $statusIcon = $statusOk ? 'is-ok' : ($statusFail ? 'is-error' : 'is-deploy');
        $timezone = config('app.timezone');
        $started = $deployment->started_at?->timezone($timezone)->format('Y-m-d H:i') ?? __('ops.none');
        $finished = $deployment->finished_at?->timezone($timezone)->format('Y-m-d H:i') ?? __('ops.none');
        $commit = $deployment->shortSha() !== '' ? $deployment->shortSha() : __('ops.none');
        $errorText = filled($deployment->error_message) ? $deployment->error_message : __('sites.deployments.no_error');
        $logText = filled($deployment->log_excerpt) ? $deployment->log_excerpt : __('sites.deployments.no_logs');
    @endphp

    <header class="site-hero">
        <div class="site-hero-main">
            <div class="site-identity-copy">
                <div class="site-title-row">
                    <h2>{{ $commit !== __('ops.none') ? $commit : __('sites.deployments.title') }}</h2>
                    <span class="status-chip status-{{ $statusValue }}">{{ $deployment->status ? __('ops.deploy_status.'.$statusValue) : __('ops.unknown') }}</span>
                </div>
                <div class="site-domain-row">
                    <a href="{{ route('ops.sites.show', $site) }}">{{ $site->name }}</a>
                    <span aria-hidden="true">·</span>
                    <span>{{ $site->primary_domain }}</span>
                </div>
            </div>
        </div>
        <div class="site-hero-meta">
            <span class="branch-chip">{{ $deployment->channel?->value ?? __('ops.none') }}</span>
        </div>
    </header>

    <div class="site-section-heading">
        <div>
            <span class="site-section-kicker">{{ __('sites.deployments.kicker') }}</span>
            <h2>{{ __('sites.deployments.summary') }} @include('ops.dashboard._hint', ['text' => __('sites.deployments.lede_show')])</h2>
        </div>
    </div>

    <div class="site-metric-grid">
        <article class="site-metric">
            <div class="site-metric-icon {{ $statusIcon }}" aria-hidden="true"><span></span></div>
            <div>
                <span>{{ __('sites.deployments.status') }}</span>
                <strong>{{ $deployment->status ? __('ops.deploy_status.'.$statusValue) : __('ops.unknown') }}</strong>
                <small>{{ $deployment->trigger ? __('ops.deploy_trigger.'.$deployment->trigger->value) : __('ops.none') }}</small>
            </div>
        </article>
        <article class="site-metric">
            <div class="site-metric-icon is-deploy" aria-hidden="true"><span></span></div>
            <div>
                <span>{{ __('sites.deployments.branch') }}</span>
                <strong>{{ $deployment->channel?->value ?? __('ops.none') }}</strong>
                <small>{{ $deployment->commit_sha ? $deployment->commit_sha : __('ops.none') }}</small>
            </div>
        </article>
        <article class="site-metric">
            <div class="site-metric-icon {{ $statusOk ? 'is-ok' : '' }}" aria-hidden="true"><span></span></div>
            <div>
                <span>{{ __('sites.deployments.duration') }}</span>
                <strong>{{ $deployment->durationLabel() }}</strong>
                <small>{{ $started }}</small>
            </div>
        </article>
        <article class="site-metric">
            <div class="site-metric-icon {{ $statusOk ? 'is-ok' : '' }}" aria-hidden="true"><span></span></div>
            <div>
                <span>{{ __('sites.deployments.finished') }}</span>
                <strong>{{ $finished }}</strong>
                <small>{{ $started }}</small>
            </div>
        </article>
    </div>

    <div class="site-overview-grid">
        <article class="site-card">
            <div class="site-card-head">
                <div>
                    <span class="site-section-kicker">{{ __('sites.deployments.output_kicker') }}</span>
                    <h3>{{ __('sites.deployments.output_title') }}</h3>
                </div>
            </div>
            <div class="ops-copy-row">
                <h2 id="deployment-error-heading">{{ __('sites.deployments.coolify_error') }}</h2>
                <button type="button" class="btn btn-ghost btn-sm" data-copy-target="#deployment-error" data-copied-label="{{ __('sites.deployments.copied') }}">{{ __('sites.deployments.copy_error') }}</button>
            </div>
            <pre id="deployment-error" class="ops-pre">{{ $errorText }}</pre>
            <div class="ops-detail-section">
                <div class="ops-copy-row">
                    <h2 id="deployment-log-heading">{{ __('sites.deployments.logs') }}</h2>
                    <button type="button" class="btn btn-ghost btn-sm" data-copy-target="#deployment-log" data-copied-label="{{ __('sites.deployments.copied') }}">{{ __('sites.deployments.copy_logs') }}</button>
                </div>
                <pre id="deployment-log" class="ops-pre">{{ $logText }}</pre>
            </div>
        </article>

        <aside class="site-card site-next-action">
            <div>
            <span class="site-section-kicker">{{ __('sites.deployments.next_action') }}</span>
            <h3>{{ __('sites.deployments.next_inspect') }}</h3>
            <p>{{ __('sites.deployments.next_inspect_hint') }}</p>
            </div>
            <div class="ops-copy-row">
                <button type="button" class="btn btn-ghost btn-sm" data-copy-target="#deployment-paste" data-copied-label="{{ __('sites.deployments.copied') }}">{{ __('sites.deployments.copy_report') }}</button>
                @if ($coolifyAppUrl)
                    <a class="btn btn-primary btn-sm" href="{{ $coolifyAppUrl }}" target="_blank" rel="noopener noreferrer">{{ __('sites.deployments.open_coolify') }}</a>
                @endif
            </div>
        </aside>
    </div>

    <section class="ops-detail-section" aria-labelledby="deployment-paste-heading">
        <div class="ops-copy-row">
            <h2 id="deployment-paste-heading">{{ __('sites.deployments.pasteable') }}</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-copy-target="#deployment-paste" data-copied-label="{{ __('sites.deployments.copied') }}">{{ __('sites.deployments.copy_report') }}</button>
        </div>
        <pre id="deployment-paste" class="ops-pre">{{ $pasteable }}</pre>
    </section>
@endsection
