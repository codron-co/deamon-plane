@extends('layouts.ops')

@section('title', $job->kindLabel())

@section('content_class', 'ops-content-wide')

@section('breadcrumbs')
    <a href="{{ route('ops.activity') }}">{{ __('ops.activity.title') }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ $job->kindLabel() }}</span>
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.activity') }}">{{ __('ops.activity.back') }}</a>
@endsection

@section('content')
    <header class="site-hero">
        <div class="site-hero-main">
            <div class="site-identity-copy">
                <div class="site-title-row">
                    <h2>{{ $job->kindLabel() }}</h2>
                    <span class="status-chip status-{{ $job->status === 'completed' && $job->triggersRemoteWork() ? 'in_progress' : $job->status }}">{{ $job->statusLabel() }}</span>
                </div>
                @if ($job->subjectLabel() !== '')
                    <div class="site-domain-row">
                        <span>{{ $job->subjectLabel() }}</span>
                    </div>
                @endif
            </div>
        </div>
    </header>

    <div class="site-overview-grid">
        <article class="site-card">
            <div class="site-card-head">
                <div>
                    <span class="site-section-kicker">{{ __('ops.activity.job.kicker') }}</span>
                    <h3>{{ __('ops.activity.job.summary') }}</h3>
                </div>
            </div>
            <dl class="site-fact-list">
                <div>
                    <dt>{{ __('ops.activity.columns.actor') }}</dt>
                    <dd>{{ $job->actor?->name ?: __('ops.activity.system') }}</dd>
                </div>
                <div>
                    <dt>{{ __('ops.activity.job.type') }}</dt>
                    <dd>{{ $job->type }}</dd>
                </div>
                <div>
                    <dt>{{ __('ops.activity.columns.when') }}</dt>
                    <dd><x-ops.freshness :at="$job->updated_at" :mark-stale="false" /></dd>
                </div>
            </dl>
            @if ($message !== '')
                <p>{{ $message }}</p>
            @endif
        </article>
    </div>

    @if ($payloadJson !== null)
        <section class="ops-detail-section">
            <h2>{{ __('ops.activity.job.payload') }}</h2>
            <pre class="ops-pre">{{ $payloadJson }}</pre>
        </section>
    @endif

    @if ($resultJson !== null)
        <section class="ops-detail-section">
            <h2>{{ __('ops.activity.job.result') }}</h2>
            <pre class="ops-pre">{{ $resultJson }}</pre>
        </section>
    @endif
@endsection
