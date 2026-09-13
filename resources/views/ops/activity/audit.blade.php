@extends('layouts.ops')

@section('title', $audit->actionLabel())

@section('content_class', 'ops-content-wide')

@section('breadcrumbs')
    <a href="{{ route('ops.activity') }}">{{ __('ops.activity.title') }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ $audit->actionLabel() }}</span>
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.activity') }}">{{ __('ops.activity.back') }}</a>
@endsection

@section('content')
    <header class="site-hero">
        <div class="site-hero-main">
            <div class="site-identity-copy">
                <div class="site-title-row">
                    <h2>{{ $audit->actionLabel() }}</h2>
                    <span class="status-chip status-{{ $audit->outcome() === 'failed' ? 'failed' : 'ok' }}">{{ __('ops.activity.outcomes.'.$audit->outcome()) }}</span>
                </div>
                <div class="site-domain-row">
                    @if ($subjectUrl)
                        <a href="{{ $subjectUrl }}">{{ $subjectLabel }}</a>
                    @else
                        <span>{{ $subjectLabel }}</span>
                    @endif
                </div>
            </div>
        </div>
    </header>

    <div class="site-overview-grid">
        <article class="site-card">
            <div class="site-card-head">
                <div>
                    <span class="site-section-kicker">{{ __('ops.activity.audit.kicker') }}</span>
                    <h3>{{ __('ops.activity.audit.summary') }}</h3>
                </div>
            </div>
            <dl class="site-fact-list">
                <div>
                    <dt>{{ __('ops.activity.columns.actor') }}</dt>
                    <dd>{{ $audit->actor?->name ?: __('ops.activity.system') }}</dd>
                </div>
                <div>
                    <dt>{{ __('ops.activity.audit.ip') }}</dt>
                    <dd>{{ $audit->ip ?: __('ops.none') }}</dd>
                </div>
                <div>
                    <dt>{{ __('ops.activity.columns.when') }}</dt>
                    <dd><x-ops.freshness :at="$audit->created_at" :mark-stale="false" /></dd>
                </div>
            </dl>
        </article>
    </div>

    @if ($beforeJson !== null)
        <section class="ops-detail-section">
            <h2>{{ __('ops.activity.audit.before') }}</h2>
            <pre class="ops-pre">{{ $beforeJson }}</pre>
        </section>
    @endif

    @if ($afterJson !== null)
        <section class="ops-detail-section">
            <h2>{{ __('ops.activity.audit.after') }}</h2>
            <pre class="ops-pre">{{ $afterJson }}</pre>
        </section>
    @endif
@endsection
