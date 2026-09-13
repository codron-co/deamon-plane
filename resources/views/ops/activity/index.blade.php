@extends('layouts.ops')

@section('title', __('ops.activity.title'))

@section('content_class', 'ops-content-wide')

@section('content')
    <p class="page-lede">{{ __('ops.activity.lede') }}</p>

    <div data-ops-list>
        <form method="GET" action="{{ route('ops.activity') }}" class="ops-list-toolbar" data-ops-list-toolbar>
            <label class="ops-search">
                <span class="visually-hidden">{{ __('ops.activity.search') }}</span>
                <input type="search" name="q" value="{{ $search }}" placeholder="{{ __('ops.activity.search_placeholder') }}" autocomplete="off">
            </label>
            <select name="kind" class="field-input ops-filter" data-ops-list-filter aria-label="{{ __('ops.activity.filter_kind') }}">
                <option value="">{{ __('ops.activity.all_kinds') }}</option>
                @foreach ($kinds as $kindOption)
                    <option value="{{ $kindOption }}" @selected($kind === $kindOption)>{{ __('ops.activity.kinds.'.$kindOption) }}</option>
                @endforeach
            </select>
            <select name="outcome" class="field-input ops-filter" data-ops-list-filter aria-label="{{ __('ops.activity.filter_outcome') }}">
                <option value="">{{ __('ops.activity.all_outcomes') }}</option>
                @foreach ($outcomes as $outcomeOption)
                    <option value="{{ $outcomeOption }}" @selected($outcome === $outcomeOption)>{{ __('ops.activity.outcomes.'.$outcomeOption) }}</option>
                @endforeach
            </select>
            <select name="actor" class="field-input ops-filter" data-ops-list-filter aria-label="{{ __('ops.activity.filter_actor') }}">
                <option value="">{{ __('ops.activity.all_actors') }}</option>
                @foreach ($actors as $actor)
                    <option value="{{ $actor->id }}" @selected($actorId === (string) $actor->id)>{{ $actor->name }}</option>
                @endforeach
            </select>
            <select name="site" class="field-input ops-filter" data-ops-list-filter aria-label="{{ __('ops.activity.filter_site') }}">
                <option value="">{{ __('ops.activity.all_sites') }}</option>
                @foreach ($sites as $siteOption)
                    <option value="{{ $siteOption->id }}" @selected($siteId === $siteOption->id)>{{ $siteOption->name }}</option>
                @endforeach
            </select>
            <a class="btn btn-secondary btn-sm ops-clear-filters" href="{{ route('ops.activity') }}" data-ops-list-clear{{ $filtersActive ? '' : ' hidden' }}>
                <svg viewBox="0 0 16 16" width="11" height="11" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                {{ __('ops.actions.clear_filters') }}
            </a>
            <a class="btn btn-ghost btn-sm" href="{{ route('ops.activity.export', $filters->query()) }}">{{ __('ops.activity.export_csv') }}</a>
        </form>

        <div class="ops-list-region" data-ops-list-region>
            @include('ops.activity._region')
        </div>
    </div>
@endsection
