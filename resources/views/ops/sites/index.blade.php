@extends('layouts.ops')

@section('title', __('sites.title'))

@section('content_class', 'ops-content-wide')

@section('actions')
    @if ($canWrite ?? false)
        @php
            $appHealthFixCounts = $appHealthFixCounts ?? [];
        @endphp
            <details class="ops-action-menu" data-ops-action-menu>
                <summary class="btn btn-secondary btn-sm">{{ __('sites.app_health.bulk') }}</summary>
                <div class="ops-action-popover" role="menu">
                    @forelse ($appHealthFixCounts as $fixKey => $fixCount)
                        <form
                            method="POST"
                            action="{{ route('ops.sites.bulk.app-health-fix') }}"
                            data-ops-pending
                            data-confirm="{{ __('sites.app_health.confirm_bulk_category', ['label' => __('sites.app_health.fixes.'.$fixKey), 'count' => $fixCount]) }}"
                            data-confirm-title="{{ __('sites.app_health.bulk') }}"
                            data-confirm-label="{{ __('sites.app_health.fixes.'.$fixKey) }}"
                            data-confirm-danger="{{ in_array($fixKey, ['redeploy', 'inject_secret'], true) ? 'true' : 'false' }}"
                        >
                            @csrf
                            <input type="hidden" name="all" value="1">
                            <input type="hidden" name="fix" value="{{ $fixKey }}">
                            <input type="hidden" name="filter_q" value="{{ $search }}">
                            <input type="hidden" name="filter_channel" value="{{ $channel }}">
                            <input type="hidden" name="filter_status" value="{{ $status }}">
                            <input type="hidden" name="filter_publish" value="{{ $publish }}">
                            <input type="hidden" name="filter_deploy" value="{{ $deploy ?? '' }}">
                            <input type="hidden" name="filter_agent" value="{{ $agent ?? '' }}">
                            <input type="hidden" name="filter_pack" value="{{ $pack ?? '' }}">
                            <input type="hidden" name="filter_health" value="{{ $health ?? '' }}">
                            <input type="hidden" name="filter_app" value="{{ $app ?? '' }}">
                            <button type="submit" class="ops-menu-button" role="menuitem" data-pending-label="{{ __('ops.actions.working') }}">
                                {{ __('sites.app_health.fix_category', ['label' => __('sites.app_health.fixes.'.$fixKey), 'count' => $fixCount]) }}
                            </button>
                        </form>
                    @empty
                        <span class="ops-menu-label">{{ __('sites.app_health.no_issues') }}</span>
                    @endforelse
                    <a class="ops-menu-button" role="menuitem" href="{{ route('ops.sites', ['app' => 'issues']) }}">{{ __('sites.app_health.see_issues') }}</a>
                    @if ($appHealthFixCounts !== [])
                        {{-- These counts come from a cached fleet scan, so say how old they are. --}}
                        @if (($appHealthCountsAt ?? null) !== null)
                            <span class="ops-menu-note">{{ __('sites.app_health.counts_age', ['ago' => $appHealthCountsAt->diffForHumans()]) }}</span>
                        @endif
                        <div class="ops-action-sep" role="separator"></div>
                        <form
                            method="POST"
                            action="{{ route('ops.sites.bulk.app-health-fix') }}"
                            data-ops-pending
                            data-confirm="{{ __('sites.app_health.confirm_bulk_all') }}"
                            data-confirm-title="{{ __('sites.app_health.bulk') }}"
                            data-confirm-label="{{ __('sites.app_health.fix_all_sites') }}"
                            data-confirm-danger="true"
                        >
                            @csrf
                            <input type="hidden" name="all" value="1">
                            <input type="hidden" name="fix" value="all">
                            <input type="hidden" name="filter_q" value="{{ $search }}">
                            <input type="hidden" name="filter_channel" value="{{ $channel }}">
                            <input type="hidden" name="filter_status" value="{{ $status }}">
                            <input type="hidden" name="filter_publish" value="{{ $publish }}">
                            <input type="hidden" name="filter_deploy" value="{{ $deploy ?? '' }}">
                            <input type="hidden" name="filter_agent" value="{{ $agent ?? '' }}">
                            <input type="hidden" name="filter_pack" value="{{ $pack ?? '' }}">
                            <input type="hidden" name="filter_health" value="{{ $health ?? '' }}">
                            <input type="hidden" name="filter_app" value="{{ $app ?? '' }}">
                            <button type="submit" class="ops-menu-button" role="menuitem" data-pending-label="{{ __('ops.actions.working') }}">
                                {{ __('sites.app_health.fix_all_sites') }}
                            </button>
                        </form>
                    @endif
                </div>
            </details>
        <form
            method="POST"
            action="{{ route('ops.sites.bulk.sync') }}"
            data-ops-pending
            data-confirm="{{ __('sites.detail.sync_confirm_all') }}"
            data-confirm-title="{{ __('sites.detail.sync_title') }}"
            data-confirm-label="{{ __('sites.detail.sync') }}"
            data-confirm-danger="false"
        >
            @csrf
            <input type="hidden" name="all" value="1">
            <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.detail.sync') }}</button>
        </form>
        <form
            method="POST"
            action="{{ route('ops.sites.live-sync') }}"
            data-ops-pending
            data-confirm="{{ __('sites.live.confirm') }}"
            data-confirm-title="{{ __('sites.live.confirm_title') }}"
            data-confirm-label="{{ __('sites.live.sync') }}"
            data-confirm-danger="false"
        >
            @csrf
            <input type="hidden" name="all" value="1">
            <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.live.sync') }}</button>
        </form>
    @endif
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.sites.archived') }}" data-sites-archive-link>{{ __('sites.archive.link') }}</a>
    @if ($canCreate)
        <a class="btn btn-primary btn-sm" href="{{ route('ops.sites.create') }}">{{ __('sites.new') }}</a>
    @endif
@endsection

@section('content')
    <div class="sites-page" data-ops-list>
        {{-- The column picker posts its own form, so it sits beside the GET filter form, never inside it. --}}
        <div class="ops-list-toolbar-row">
        <form method="GET" action="{{ route('ops.sites') }}" class="ops-list-toolbar" data-ops-list-toolbar>
            <label class="ops-search">
                <span class="visually-hidden">{{ __('sites.search') }}</span>
                <input type="search" name="q" value="{{ $search }}" placeholder="{{ __('sites.search_placeholder') }}" autocomplete="off">
            </label>
            <select name="channel" class="field-input ops-filter" data-ops-list-filter aria-label="{{ __('sites.filter_branch') }}">
                <option value="">{{ __('sites.all_branches') }}</option>
                @foreach ($channels as $channelOption)
                    <option value="{{ $channelOption }}" @selected($channel === $channelOption)>{{ $channelOption }}</option>
                @endforeach
            </select>
            <select name="status" class="field-input ops-filter" data-ops-list-filter aria-label="{{ __('sites.filter_status') }}">
                <option value="">{{ __('sites.all_statuses') }}</option>
                @foreach ($statuses as $statusOption)
                    <option value="{{ $statusOption }}" @selected($status === $statusOption)>{{ __('ops.site_status.'.$statusOption) }}</option>
                @endforeach
            </select>
            <select name="publish" class="field-input ops-filter" data-ops-list-filter aria-label="{{ __('sites.filter_publish') }}">
                <option value="">{{ __('sites.all_publish') }}</option>
                @foreach ($publishFilters as $publishOption)
                    <option value="{{ $publishOption }}" @selected($publish === $publishOption)>{{ __('sites.publish.states.'.$publishOption) }}</option>
                @endforeach
            </select>
            <select name="deploy" class="field-input ops-filter" data-ops-list-filter aria-label="{{ __('sites.filter_deploy') }}">
                <option value="">{{ __('sites.all_deploys') }}</option>
                @foreach ($deployFilters as $deployOption => $deployLabel)
                    <option value="{{ $deployOption }}" @selected(($deploy ?? '') === $deployOption)>{{ $deployLabel }}</option>
                @endforeach
            </select>
            <select name="agent" class="field-input ops-filter" data-ops-list-filter aria-label="{{ __('sites.filter_agent') }}">
                <option value="">{{ __('sites.all_agent_secrets') }}</option>
                @foreach (\App\Models\Site::AGENT_FILTERS as $agentOption)
                    <option value="{{ $agentOption }}" @selected(($agent ?? '') === $agentOption)>{{ __('sites.agent_states.'.$agentOption) }}</option>
                @endforeach
            </select>
            <select name="health" class="field-input ops-filter" data-ops-list-filter aria-label="{{ __('sites.filter_health') }}">
                <option value="">{{ __('sites.all_health') }}</option>
                @foreach ($healthFilters ?? [] as $healthOption => $healthLabel)
                    <option value="{{ $healthOption }}" @selected(($health ?? '') === $healthOption)>{{ $healthLabel }}</option>
                @endforeach
            </select>
            <select name="app" class="field-input ops-filter" data-ops-list-filter aria-label="{{ __('sites.filter_app') }}">
                <option value="">{{ __('sites.all_app') }}</option>
                @foreach ($appFilters ?? [] as $appOption => $appLabel)
                    <option value="{{ $appOption }}" @selected(($app ?? '') === $appOption)>{{ $appLabel }}</option>
                @endforeach
            </select>
            {{-- Rendered even when idle so the async toolbar can reveal it without a round trip. --}}
            <input type="hidden" name="pack" value="{{ $pack ?? '' }}">
            <a class="btn btn-secondary btn-sm ops-clear-filters" href="{{ \App\Support\Lists\SiteSavedViews::clearUrl() }}" data-ops-list-clear{{ $filtersActive ? '' : ' hidden' }}>
                <svg viewBox="0 0 16 16" width="11" height="11" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                {{ __('ops.actions.clear_filters') }}
            </a>
        </form>
            @include('ops.sites._columns-picker')
            @include('ops.sites._saved-views-form')
        </div>

        <div class="ops-list-region" data-ops-list-region>
            @include('ops.sites._region')
        </div>
    </div>
@endsection

