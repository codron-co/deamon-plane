@extends('layouts.ops')

@section('title', __('rollouts.title'))

@section('content_class', 'ops-content-wide')

@section('content')
    <p class="page-lede">{{ __('rollouts.lede') }}</p>

    <section class="ops-panel" aria-labelledby="rollouts-heads-heading">
        <h2 id="rollouts-heads-heading">{{ __('rollouts.heads.title') }} @include('ops.dashboard._hint', ['text' => __('rollouts.heads.hint')])</h2>
        <dl class="site-fact-list">
            <div>
                <dt>{{ __('rollouts.heads.gated_sites') }}</dt>
                <dd>{{ __('rollouts.heads.gated_count', ['count' => $gatedSites, 'canaries' => $canarySites]) }}</dd>
            </div>
            @forelse ($heads as $head)
                <div>
                    <dt><span class="branch-chip">{{ $head->branch }}</span></dt>
                    <dd>
                        <code>{{ $head->head_sha ? substr($head->head_sha, 0, 7) : __('ops.none') }}</code>
                        ·
                        @include('ops.rollouts._ci-status', ['head' => $head])
                    </dd>
                </div>
            @empty
                <div>
                    <dt>{{ __('rollouts.heads.branches') }}</dt>
                    <dd class="muted">{{ __('rollouts.heads.none') }}</dd>
                </div>
            @endforelse
        </dl>
    </section>

    @if ($rollouts->isEmpty())
        <div class="empty-panel">
            <h2>{{ __('rollouts.empty.title') }}</h2>
            <p>{{ __('rollouts.empty.hint') }}</p>
            <div class="empty-panel-actions">
                <a class="btn btn-secondary" href="{{ route('ops.sites') }}">{{ __('rollouts.empty.sites') }}</a>
            </div>
        </div>
    @else
        <div class="sites-table-wrap">
            <table class="ops-table">
                <thead>
                    <tr>
                        <th>{{ __('rollouts.columns.channel') }}</th>
                        <th>{{ __('rollouts.columns.sha') }}</th>
                        <th>{{ __('rollouts.columns.status') }}</th>
                        <th>{{ __('rollouts.columns.canary') }}</th>
                        <th>{{ __('rollouts.columns.fanout') }}</th>
                        <th>{{ __('rollouts.columns.started') }}</th>
                        <th>{{ __('rollouts.columns.finished') }}</th>
                        <th>{{ __('rollouts.columns.reason') }}</th>
                        <th><span class="visually-hidden">{{ __('rollouts.columns.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rollouts as $rollout)
                        <tr data-href="{{ route('ops.rollouts.show', $rollout) }}" tabindex="0">
                            <td><span class="branch-chip">{{ $rollout->channel->value }}</span></td>
                            <td><a class="site-name" href="{{ route('ops.rollouts.show', $rollout) }}"><code>{{ $rollout->shortSha() }}</code></a></td>
                            <td><span class="status-chip {{ $rollout->status->chipClass() }}">{{ $rollout->status->label() }}</span></td>
                            <td>@include('ops.rollouts._stage-counts', ['stage' => 'canary'])</td>
                            <td>@include('ops.rollouts._stage-counts', ['stage' => 'fanout'])</td>
                            <td class="muted">
                                @if ($rollout->started_at)
                                    <time datetime="{{ $rollout->started_at->toIso8601String() }}" title="{{ $rollout->started_at->toDateTimeString() }}">{{ $rollout->started_at->diffForHumans() }}</time>
                                @else
                                    {{ __('ops.none') }}
                                @endif
                            </td>
                            <td class="muted">
                                @if ($rollout->finished_at)
                                    <time datetime="{{ $rollout->finished_at->toIso8601String() }}" title="{{ $rollout->finished_at->toDateTimeString() }}">{{ $rollout->finished_at->diffForHumans() }}</time>
                                @else
                                    {{ __('ops.none') }}
                                @endif
                            </td>
                            <td>{{ $rollout->halted_reason ?: __('ops.none') }}</td>
                            <td class="ops-row-actions">
                                @if ($canWrite && $rollout->isOpen())
                                    @include('ops.rollouts._halt', ['rollout' => $rollout])
                                @endif
                                <a class="btn btn-ghost btn-sm" href="{{ route('ops.rollouts.show', $rollout) }}">{{ __('ops.actions.open') }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @include('ops.partials.pagination', ['paginator' => $rollouts, 'label' => __('rollouts.pagination')])
    @endif
@endsection
