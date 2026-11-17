@extends('layouts.ops')

@section('title', __('security.events.title'))

@section('content_class', 'ops-content-wide')

@section('content')
    @include('ops.security._tabs')

    @php
        $extraFilters = ($query->severity !== '' ? 1 : 0) + ($query->siteId !== '' ? 1 : 0) + ($query->rule !== '' ? 1 : 0) + ($query->period !== '24h' ? 1 : 0);
    @endphp

    <div data-ops-list data-security-list>
        <form method="GET" action="{{ route('ops.security.events') }}" class="ops-list-toolbar" data-ops-list-toolbar data-search-delay="200">
            <label class="ops-search">
                <span class="visually-hidden">{{ __('security.events.search') }}</span>
                <input type="search" name="q" value="{{ $query->search }}" placeholder="{{ __('security.events.search_placeholder') }}" autocomplete="off">
            </label>

            <button type="button" class="ops-icon-btn security-filter-toggle" data-security-filter-toggle
                aria-expanded="{{ $extraFilters > 0 ? 'true' : 'false' }}" aria-controls="security-filter-panel"
                aria-label="{{ __('security.events.filters') }}" title="{{ __('security.events.filters') }}">
                <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true"><path d="M2.5 3.5h11L9.5 8.4v3.6l-3 1.5V8.4L2.5 3.5Z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>
                <span class="security-filter-dot" data-security-filter-dot @if ($extraFilters === 0) hidden @endif></span>
            </button>

            <div class="security-filter-panel" id="security-filter-panel" data-security-filter-panel @if ($extraFilters === 0) hidden @endif>
                <select name="severity" class="field-input ops-filter" data-ops-list-filter aria-label="{{ __('security.events.filter_severity') }}">
                    <option value="">{{ __('security.events.all_severities') }}</option>
                    @foreach ($severities as $option)
                        <option value="{{ $option }}" @selected($query->severity === $option)>{{ __('security.severity.'.$option) }}</option>
                    @endforeach
                </select>
                <select name="site" class="field-input ops-filter" data-ops-list-filter aria-label="{{ __('security.events.filter_site') }}">
                    <option value="">{{ __('security.events.all_sites') }}</option>
                    @foreach ($sites as $siteOption)
                        <option value="{{ $siteOption->id }}" @selected($query->siteId === $siteOption->id)>{{ $siteOption->name }}</option>
                    @endforeach
                </select>
                <select name="rule" class="field-input ops-filter" data-ops-list-filter aria-label="{{ __('security.events.filter_rule') }}">
                    <option value="">{{ __('security.events.all_rules') }}</option>
                    @foreach ($rules as $option)
                        <option value="{{ $option }}" @selected($query->rule === $option)>{{ $option }}</option>
                    @endforeach
                </select>
                <select name="period" class="field-input ops-filter" data-ops-list-filter aria-label="{{ __('security.events.filter_period') }}">
                    @foreach ($periods as $option)
                        <option value="{{ $option }}" @selected($query->period === $option)>{{ __('security.period.'.$option) }}</option>
                    @endforeach
                </select>
            </div>

            @if ($query->ip !== '')
                <input type="hidden" name="ip" value="{{ $query->ip }}">
            @endif

            <a class="ops-icon-btn ops-clear-filters" href="{{ route('ops.security.events') }}" data-ops-list-clear{{ $filtersActive ? '' : ' hidden' }}
                aria-label="{{ __('security.events.clear') }}" title="{{ __('security.events.clear') }}">
                <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
            </a>
        </form>

        <div class="ops-list-region" data-ops-list-region>
            @include('ops.security._events_region')
        </div>
    </div>
@endsection

@section('scripts')
    <script src="{{ asset('js/ops-security.js') }}?v={{ filemtime(public_path('js/ops-security.js')) }}" defer></script>
@endsection
