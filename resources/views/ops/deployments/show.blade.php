@extends('layouts.ops')

@section('title', __('sites.deployments.title').' '.($deployment->shortSha() !== '' ? $deployment->shortSha() : ($deployment->status?->value ?? '')))

@section('content_class', 'ops-content-wide')

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.sites.show', $site) }}">{{ __('sites.back_to_site') }}</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.sites.edit', $site) }}">{{ __('sites.deployments.site_edit') }}</a>
    @if ($coolifyAppUrl)
        <a class="btn btn-ghost btn-sm" href="{{ $coolifyAppUrl }}" target="_blank" rel="noopener noreferrer">{{ __('sites.deployments.open_coolify') }}</a>
    @endif
    <button type="button" class="btn btn-primary btn-sm" data-copy-target="#deployment-paste" data-copied-label="{{ __('sites.deployments.copied') }}">{{ __('sites.deployments.copy_report') }}</button>
@endsection

@section('content')
    <p class="page-lede">{{ __('sites.deployments.lede_show') }}</p>

    <div class="ops-detail-grid">
        <section class="ops-detail-card">
            <span class="ops-detail-label">{{ __('sites.deployments.status') }}</span>
            <div class="ops-detail-value">
                <span class="status-chip status-{{ $deployment->status?->value }}">{{ $deployment->status ? __('ops.deploy_status.'.$deployment->status->value) : __('ops.unknown') }}</span>
            </div>
        </section>
        <section class="ops-detail-card">
            <span class="ops-detail-label">{{ __('sites.deployments.channel') }}</span>
            <div class="ops-detail-value"><span class="channel-chip">{{ $deployment->channel?->value ?? __('ops.none') }}</span></div>
        </section>
        <section class="ops-detail-card">
            <span class="ops-detail-label">{{ __('sites.deployments.trigger') }}</span>
            <div class="ops-detail-value">{{ $deployment->trigger ? __('ops.deploy_trigger.'.$deployment->trigger->value) : __('ops.none') }}</div>
        </section>
        <section class="ops-detail-card">
            <span class="ops-detail-label">{{ __('sites.deployments.commit') }}</span>
            <div class="ops-detail-value">
                @if ($deployment->commit_sha)
                    <code>{{ $deployment->commit_sha }}</code>
                @else
                    {{ __('ops.none') }}
                @endif
            </div>
        </section>
        <section class="ops-detail-card">
            <span class="ops-detail-label">{{ __('sites.deployments.duration') }}</span>
            <div class="ops-detail-value">{{ $deployment->durationLabel() }}</div>
        </section>
        <section class="ops-detail-card">
            <span class="ops-detail-label">{{ __('sites.deployments.times') }}</span>
            <div class="ops-detail-value">
                {{ $deployment->started_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? __('ops.none') }}
                ·
                {{ $deployment->finished_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? __('ops.none') }}
            </div>
        </section>
    </div>

    <section class="ops-detail-section" aria-labelledby="deployment-error-heading">
        <div class="ops-copy-row">
            <h2 id="deployment-error-heading">{{ __('sites.deployments.coolify_error') }}</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-copy-target="#deployment-error" data-copied-label="{{ __('sites.deployments.copied') }}">{{ __('sites.deployments.copy_error') }}</button>
        </div>
        @if (filled($deployment->error_message))
            <pre id="deployment-error" class="ops-pre">{{ $deployment->error_message }}</pre>
        @else
            <p class="muted">{{ __('sites.deployments.no_error') }}</p>
        @endif
    </section>

    <section class="ops-detail-section" aria-labelledby="deployment-log-heading">
        <div class="ops-copy-row">
            <h2 id="deployment-log-heading">{{ __('sites.deployments.logs') }}</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-copy-target="#deployment-log" data-copied-label="{{ __('sites.deployments.copied') }}">{{ __('sites.deployments.copy_logs') }}</button>
        </div>
        @if (filled($deployment->log_excerpt))
            <pre id="deployment-log" class="ops-pre">{{ $deployment->log_excerpt }}</pre>
        @else
            <p class="muted">{{ __('sites.deployments.no_logs') }}</p>
        @endif
    </section>

    <section class="ops-detail-section" aria-labelledby="deployment-paste-heading">
        <div class="ops-copy-row">
            <h2 id="deployment-paste-heading">{{ __('sites.deployments.pasteable') }}</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-copy-target="#deployment-paste" data-copied-label="{{ __('sites.deployments.copied') }}">{{ __('sites.deployments.copy_report') }}</button>
        </div>
        <pre id="deployment-paste" class="ops-pre">{{ $pasteable }}</pre>
    </section>
@endsection
