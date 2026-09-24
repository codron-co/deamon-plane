@extends('layouts.ops')

@section('title', __('rollouts.show.title', ['channel' => $rollout->channel->value, 'sha' => $rollout->shortSha()]))

@section('breadcrumbs')
    <a href="{{ route('ops.rollouts') }}">{{ __('rollouts.title') }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ $rollout->label() }}</span>
@endsection

@section('actions')
    @if ($canWrite && $rollout->isOpen())
        @include('ops.rollouts._halt', ['rollout' => $rollout])
    @endif
    @if ($canDanger && $rollout->status === \App\Enums\FleetRolloutStatus::Halted)
        <form
            method="POST"
            action="{{ route('ops.rollouts.resume', $rollout) }}"
            data-ops-pending
            data-confirm="{{ __('rollouts.resume.confirm', ['channel' => $rollout->channel->value, 'sha' => $rollout->shortSha()]) }}"
            data-confirm-title="{{ __('rollouts.resume.confirm_title') }}"
            data-confirm-label="{{ __('rollouts.resume.button') }}"
            data-confirm-danger="true"
        >
            @csrf
            <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('rollouts.resume.button') }}</button>
        </form>
    @endif
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.rollouts') }}">{{ __('rollouts.title') }}</a>
@endsection

@php
    $canary = $rollout->stage('canary');
    $fanout = $rollout->stage('fanout');
    $siteName = fn (string $id): string => (string) ($sites->get($id)?->name ?? $id);
    $rolloutErrors = is_array($rollout->summary['errors'] ?? null) ? $rollout->summary['errors'] : [];
@endphp

@section('content')
    <p class="page-lede">{{ __('rollouts.show.lede') }}</p>

    <section class="ops-panel" aria-labelledby="rollout-summary-heading">
        <h2 id="rollout-summary-heading">{{ __('rollouts.show.summary') }}</h2>
        <dl class="site-fact-list">
            <div><dt>{{ __('rollouts.columns.channel') }}</dt><dd><span class="branch-chip">{{ $rollout->channel->value }}</span></dd></div>
            <div>
                <dt>{{ __('rollouts.columns.sha') }}</dt>
                <dd>
                    <code>{{ $rollout->sha }}</code>
                    <button type="button" class="btn btn-ghost btn-sm" data-copy-value="{{ $rollout->sha }}">{{ __('ops.actions.copy') }}</button>
                </dd>
            </div>
            <div><dt>{{ __('rollouts.columns.status') }}</dt><dd><span class="status-chip {{ $rollout->status->chipClass() }}">{{ $rollout->status->label() }}</span></dd></div>
            <div><dt>{{ __('rollouts.columns.canary') }}</dt><dd>@include('ops.rollouts._stage-counts', ['stage' => 'canary'])</dd></div>
            <div><dt>{{ __('rollouts.columns.fanout') }}</dt><dd>@include('ops.rollouts._stage-counts', ['stage' => 'fanout'])</dd></div>
            <div><dt>{{ __('rollouts.columns.started') }}</dt><dd>{{ $rollout->started_at?->toDateTimeString() ?? __('ops.none') }}</dd></div>
            <div><dt>{{ __('rollouts.columns.finished') }}</dt><dd>{{ $rollout->finished_at?->toDateTimeString() ?? __('ops.none') }}</dd></div>
            @if ($rollout->halted_reason)
                <div>
                    <dt>{{ __('rollouts.columns.reason') }}</dt>
                    <dd>
                        {{ $rollout->halted_reason }}
                        @if ($rollout->haltedBy)
                            <span class="muted">· {{ $rollout->haltedBy->name }}</span>
                        @endif
                    </dd>
                </div>
            @endif
        </dl>
        @if ($rollout->status === \App\Enums\FleetRolloutStatus::Halted && ! $canDanger)
            <p class="field-hint">{{ __('rollouts.resume.super_admin_only') }}</p>
        @endif
    </section>

    @if ($rolloutErrors !== [])
        <section class="ops-panel" aria-labelledby="rollout-errors-heading">
            <h2 id="rollout-errors-heading">{{ __('rollouts.show.errors') }}</h2>
            <ul>
                @foreach ($rolloutErrors as $rolloutError)
                    <li>{{ $rolloutError }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="ops-panel" aria-labelledby="rollout-canary-heading">
        <h2 id="rollout-canary-heading">{{ __('rollouts.show.canary') }}</h2>
        @if (($canary['site_ids'] ?? []) === [])
            <p class="muted">{{ array_key_exists('site_ids', $canary) ? __('rollouts.show.no_canary') : __('rollouts.show.not_started') }}</p>
        @else
            <div class="sites-table-wrap">
                <table class="ops-table">
                    <thead>
                        <tr>
                            <th>{{ __('rollouts.show.site') }}</th>
                            <th>{{ __('rollouts.show.state') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($canary['site_ids'] as $siteId)
                            @php
                                $siteId = (string) $siteId;
                                $state = match (true) {
                                    in_array($siteId, $canary['healthy'] ?? [], true) => 'healthy',
                                    in_array($siteId, $canary['failed'] ?? [], true) => 'failed',
                                    array_key_exists($siteId, $canary['deployments'] ?? []) => 'building',
                                    default => 'pending',
                                };
                                $deploymentId = $canary['deployments'][$siteId] ?? null;
                            @endphp
                            <tr>
                                <td>
                                    @if ($sites->has($siteId))
                                        <a href="{{ route('ops.sites.show', $siteId) }}">{{ $siteName($siteId) }}</a>
                                    @else
                                        {{ $siteId }}
                                    @endif
                                </td>
                                <td>
                                    <span class="status-chip {{ $state === 'healthy' ? 'status-active' : ($state === 'failed' ? 'status-error' : 'status-deploying') }}">{{ __('rollouts.state.'.$state) }}</span>
                                    @if ($deploymentId && $sites->has($siteId))
                                        <a class="muted" href="{{ route('ops.sites.deployments.show', [$siteId, $deploymentId]) }}">{{ __('rollouts.show.deployment') }}</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="ops-panel" aria-labelledby="rollout-fanout-heading">
        <h2 id="rollout-fanout-heading">{{ __('rollouts.show.fanout') }}</h2>
        @if (($fanout['site_ids'] ?? []) === [])
            <p class="muted">{{ array_key_exists('site_ids', $fanout) ? __('rollouts.show.no_fanout') : __('rollouts.show.not_started') }}</p>
        @else
            <div class="sites-table-wrap">
                <table class="ops-table">
                    <thead>
                        <tr>
                            <th>{{ __('rollouts.show.site') }}</th>
                            <th>{{ __('rollouts.show.state') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($fanout['site_ids'] as $siteId)
                            @php
                                $siteId = (string) $siteId;
                                $failedMessage = $fanout['failed'][$siteId] ?? null;
                                $state = match (true) {
                                    $failedMessage !== null => 'failed',
                                    in_array($siteId, $fanout['deployed'] ?? [], true) => 'deployed',
                                    in_array($siteId, $fanout['skipped'] ?? [], true) => 'skipped',
                                    default => 'pending',
                                };
                            @endphp
                            <tr>
                                <td>
                                    @if ($sites->has($siteId))
                                        <a href="{{ route('ops.sites.show', $siteId) }}">{{ $siteName($siteId) }}</a>
                                    @else
                                        {{ $siteId }}
                                    @endif
                                </td>
                                <td>
                                    <span class="status-chip {{ $state === 'deployed' ? 'status-active' : ($state === 'failed' ? 'status-error' : '') }}">{{ __('rollouts.state.'.$state) }}</span>
                                    @if ($failedMessage)
                                        <span class="muted">{{ $failedMessage }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
