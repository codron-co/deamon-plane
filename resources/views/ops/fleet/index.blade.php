@extends('layouts.ops')

@section('title', __('fleet.title'))

@section('content_class', 'ops-content-wide')

@section('content')
    <div class="site-section-heading">
        <div>
            <span class="site-section-kicker">{{ __('fleet.kicker') }}</span>
            <h2>{{ __('fleet.heading') }}</h2>
        </div>
    </div>

    <div data-ops-list>
        <form method="GET" action="{{ route('ops.fleet') }}" class="ops-list-toolbar" data-ops-list-toolbar>
            <label class="ops-search">
                <span class="visually-hidden">{{ __('fleet.search') }}</span>
                <input type="search" name="q" value="{{ $search }}" placeholder="{{ __('fleet.search_placeholder') }}" autocomplete="off">
            </label>
            <select name="kind" class="field-input ops-filter" data-ops-list-filter aria-label="{{ __('fleet.filter_kind') }}">
                <option value="">{{ __('fleet.all_kinds') }}</option>
                <option value="unhealthy" @selected($kind === 'unhealthy')>{{ __('fleet.kinds.unhealthy') }}</option>
                <option value="failed" @selected($kind === 'failed')>{{ __('fleet.kinds.failed') }}</option>
                <option value="agent" @selected($kind === 'agent')>{{ __('fleet.kinds.agent') }}</option>
                <option value="dockerfile" @selected($kind === 'dockerfile')>{{ __('fleet.kinds.dockerfile') }}</option>
            </select>
            <a class="btn btn-ghost btn-sm" href="{{ route('ops.fleet') }}" data-ops-list-clear{{ $filtersActive ? '' : ' hidden' }}>{{ __('ops.actions.clear') }}</a>
        </form>

        <div class="ops-list-region" data-ops-list-region>
            @include('ops.dashboard.attention')
        </div>
    </div>

    @include('ops.dashboard.kpis')
@endsection
