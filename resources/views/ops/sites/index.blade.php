@extends('layouts.ops')

@section('title', __('sites.title'))

@section('content_class', 'ops-content-wide')

@section('actions')
    @if ($canWrite ?? false)
        @php
            $appHealthFixCounts = $appHealthFixCounts ?? [];
        @endphp
        {{-- Fleet-wide operations share one menu so the topbar keeps a single primary action. --}}
        <details class="ops-action-menu plane-quick-actions" data-ops-action-menu>
            <summary class="btn btn-secondary btn-sm">
                <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><path d="M8.7 1.5 3.2 9h4l-.9 5.5L11.8 7h-4l.9-5.5Z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>
                <span>{{ __('sites.quick_actions') }}</span>
                @if ($appHealthFixCounts !== [])
                    <span class="plane-count is-warning">{{ array_sum($appHealthFixCounts) }}</span>
                @endif
            </summary>
            <div class="ops-action-popover plane-quick-popover" role="menu">
                <span class="ops-menu-label">{{ __('sites.app_health.bulk') }}</span>
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
                        @include('ops.sites._filter-hidden')
                        <button type="submit" class="ops-menu-button" role="menuitem" data-pending-label="{{ __('ops.actions.working') }}">
                            {{ __('sites.app_health.fix_category', ['label' => __('sites.app_health.fixes.'.$fixKey), 'count' => $fixCount]) }}
                        </button>
                    </form>
                @empty
                    <span class="ops-menu-note">{{ __('sites.app_health.no_issues') }}</span>
                @endforelse
                <a class="ops-menu-button" role="menuitem" href="{{ route('ops.sites', ['app' => 'issues']) }}">{{ __('sites.app_health.see_issues') }}</a>
                @if ($appHealthFixCounts !== [])
                    {{-- These counts come from a cached fleet scan, so say how old they are. --}}
                    @if (($appHealthCountsAt ?? null) !== null)
                        <span class="ops-menu-note">{{ __('sites.app_health.counts_age', ['ago' => $appHealthCountsAt->diffForHumans()]) }}</span>
                    @endif
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
                        @include('ops.sites._filter-hidden')
                        <button type="submit" class="ops-menu-button" role="menuitem" data-pending-label="{{ __('ops.actions.working') }}">
                            {{ __('sites.app_health.fix_all_sites') }}
                        </button>
                    </form>
                @endif
                <div class="ops-action-sep" role="separator"></div>
                <span class="ops-menu-label">{{ __('sites.quick_actions_sync') }}</span>
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
                    <button type="submit" class="ops-menu-button" role="menuitem" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.detail.sync') }}</button>
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
                    <button type="submit" class="ops-menu-button" role="menuitem" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.live.sync') }}</button>
                </form>
                <div class="ops-action-sep" role="separator"></div>
                <a class="ops-menu-button" role="menuitem" href="{{ route('ops.sites.archived') }}" data-sites-archive-link>{{ __('sites.archive.link') }}</a>
            </div>
        </details>
    @else
        <a class="btn btn-ghost btn-sm" href="{{ route('ops.sites.archived') }}" data-sites-archive-link>{{ __('sites.archive.link') }}</a>
    @endif
    @if ($canCreate)
        <a class="btn btn-primary btn-sm" href="{{ route('ops.sites.create') }}">
            <svg viewBox="0 0 16 16" width="13" height="13" aria-hidden="true"><path d="M8 3v10M3 8h10" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            {{ __('sites.new') }}
        </a>
    @endif
@endsection

@section('content')
    @php
        $filterValues = [
            'channel' => $channel,
            'status' => $status,
            'publish' => $publish,
            'theme' => $theme ?? '',
            'theme_id' => $themeId ?? '',
            'cms' => $cms ?? '',
            'health' => $health ?? '',
            'stale' => $stale ?? '',
            'app' => $app ?? '',
            'deploy' => $deploy ?? '',
            'agent' => $agent ?? '',
            'pack' => $pack ?? '',
            'auto_deploy' => $autoDeploy ?? '',
            'server' => $server ?? '',
        ];
        $activeFilterCount = count(array_filter($filterValues, static fn (string $value): bool => $value !== ''));

        $statusOptions = [];
        foreach ($statuses as $statusOption) {
            $statusOptions[$statusOption] = __('ops.site_status.'.$statusOption);
        }
        $publishOptions = [];
        foreach ($publishFilters as $publishOption) {
            $publishOptions[$publishOption] = __('sites.publish.states.'.$publishOption);
        }
        $packOptions = [];
        foreach (\App\Models\Site::PACK_FILTERS as $packOption) {
            $packOptions[$packOption] = __('sites.pack_states.'.$packOption);
        }

        // Groups, most-used first, each posted by the toolbar form. Short sets are one radio
        // chip row; open-ended sets (a theme, a server) are a Plane select.
        $filterGroups = [
            'channel' => [__('sites.filter_branch'), array_combine($channels, $channels), true, false],
            'theme' => [__('sites.filter_theme'), $themeFilters ?? [], false, false],
            'theme_id' => [__('sites.filter_theme_id'), $themeIdFilters ?? [], false, true],
            'cms' => [__('sites.filter_cms'), $cmsFilters ?? [], false, false],
            'status' => [__('sites.filter_status'), $statusOptions, false, false],
            'publish' => [__('sites.filter_publish'), $publishOptions, false, false],
            'health' => [__('sites.filter_health'), $healthFilters ?? [], false, false],
            'stale' => [__('sites.filter_stale'), $staleFilters ?? [], false, false],
            'app' => [__('sites.filter_app'), $appFilters ?? [], false, false],
            'deploy' => [__('sites.filter_deploy'), $deployFilters, false, false],
            'auto_deploy' => [__('sites.filter_auto_deploy'), $autoDeployFilters ?? [], false, false],
            'server' => [__('sites.filter_server'), $serverFilters ?? [], false, true],
            'agent' => [__('sites.filter_agent'), $agentFilters ?? [], false, false],
            'pack' => [__('sites.filter_pack'), $packOptions, false, false],
        ];

        $quickFilters = [
            'all' => [__('sites.filter_panel.quick_all'), \App\Support\Lists\SiteSavedViews::clearUrl(), []],
            'unhealthy' => [__('sites.filter_panel.quick_unhealthy'), route('ops.sites', ['health' => 'unhealthy']), ['health' => 'unhealthy']],
            'failed' => [__('sites.filter_panel.quick_failed'), route('ops.sites', ['deploy' => 'failed']), ['deploy' => 'failed']],
            'git' => [__('sites.filter_panel.quick_git'), route('ops.sites', ['theme' => 'git']), ['theme' => 'git']],
        ];
        $currentFilters = array_filter($filterValues + ['q' => $search], static fn (string $value): bool => $value !== '');
    @endphp

    <div class="sites-page" data-ops-list data-sites-list>
        @if (($summary ?? null) !== null)
            @php
                $tiles = [
                    ['key' => 'total', 'tone' => 'neutral', 'url' => \App\Support\Lists\SiteSavedViews::clearUrl(), 'icon' => 'M2.5 13.5V5.2L8 2.5l5.5 2.7v8.3H2.5Zm3-.5v-4h5v4'],
                    ['key' => 'unhealthy', 'tone' => $summary['unhealthy'] > 0 ? 'danger' : 'success', 'url' => route('ops.sites', ['health' => 'unhealthy']), 'icon' => 'M8 5v3.5M8 11h.01M6.9 2.6 1.8 11.5A1.3 1.3 0 0 0 2.9 13.5h10.2a1.3 1.3 0 0 0 1.1-2L9.1 2.6a1.3 1.3 0 0 0-2.2 0Z'],
                    ['key' => 'failed_deploys', 'tone' => $summary['failed_deploys'] > 0 ? 'danger' : 'success', 'url' => route('ops.sites', ['deploy' => 'failed']), 'icon' => 'M8 14A6 6 0 1 0 8 2a6 6 0 0 0 0 12ZM6 6l4 4M10 6l-4 4'],
                    ['key' => 'app_issues', 'tone' => $summary['app_issues'] > 0 ? 'warning' : 'success', 'url' => route('ops.sites', ['app' => 'issues']), 'icon' => 'M2 8h2.5l1.5-4 3 8 1.5-4H14'],
                    ['key' => 'git_themes', 'tone' => 'accent', 'url' => route('ops.sites', ['theme' => 'git']), 'icon' => 'M5 3.5v6M5 9.5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3ZM11 4.5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3ZM11 7.5c0 2-2.5 2-6 3'],
                ];
            @endphp
            <section class="plane-metrics" aria-label="{{ __('sites.summary.label') }}">
                @foreach ($tiles as $tile)
                    <a class="plane-metric is-{{ $tile['tone'] }}" href="{{ $tile['url'] }}" data-ops-list-view="summary-{{ $tile['key'] }}">
                        <span class="plane-metric-top">
                            <span>{{ __('sites.summary.'.$tile['key']) }}</span>
                            <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true"><path d="{{ $tile['icon'] }}" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                        <span class="plane-metric-value">
                            <strong>{{ $summary[$tile['key']] }}</strong>
                            @if ($tile['key'] !== 'total' && $summary['total'] > 0)
                                <span>{{ __('sites.summary.of_total', ['count' => $summary[$tile['key']], 'total' => $summary['total']]) }}</span>
                            @endif
                        </span>
                    </a>
                @endforeach
            </section>
        @endif

        <section class="plane-workspace">
            {{-- The column picker posts its own form, so it sits beside the GET filter form, never inside it. --}}
            <div class="ops-list-toolbar-row plane-toolbar">
                <form method="GET" action="{{ route('ops.sites') }}" id="sites-list-filters" class="ops-list-toolbar" data-ops-list-toolbar>
                    <label class="ops-search plane-search">
                        <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><circle cx="7" cy="7" r="4.5" fill="none" stroke="currentColor" stroke-width="1.4"/><path d="m10.5 10.5 3 3" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                        <span class="visually-hidden">{{ __('sites.search') }}</span>
                        <input type="search" name="q" value="{{ $search }}" placeholder="{{ __('sites.search_placeholder') }}" autocomplete="off">
                    </label>
                </form>

                <nav class="plane-segment" aria-label="{{ __('sites.filter_panel.quick_label') }}" data-sites-quick>
                    @foreach ($quickFilters as $quickKey => [$quickLabel, $quickUrl, $quickParams])
                        @php $quickActive = $currentFilters == $quickParams; @endphp
                        <a
                            class="plane-segment-item @if ($quickActive) is-active @endif"
                            href="{{ $quickUrl }}"
                            data-ops-list-view="quick-{{ $quickKey }}"
                            data-sites-quick-params="{{ json_encode((object) $quickParams) }}"
                            @if ($quickActive) aria-current="true" @endif
                        >{{ $quickLabel }}</a>
                    @endforeach
                </nav>

                <span class="plane-toolbar-sep" aria-hidden="true"></span>

                <button
                    type="button"
                    class="btn btn-secondary btn-sm plane-filter-toggle @if ($activeFilterCount > 0) is-active @endif"
                    aria-expanded="false"
                    aria-controls="sites-filter-panel"
                    data-sites-filter-toggle
                >
                    <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><path d="M2.5 4h11M4.5 8h7M6.5 12h3" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                    <span>{{ __('sites.filter_panel.toggle') }}</span>
                    <span class="plane-count" data-sites-filter-count @if ($activeFilterCount === 0) hidden @endif>{{ $activeFilterCount }}</span>
                </button>
                @include('ops.sites._columns-picker')
                @include('ops.sites._saved-views-form')
            </div>

            <div class="plane-filter-panel" id="sites-filter-panel" data-sites-filter-panel hidden>
                @foreach ($filterGroups as $filterName => [$groupLabel, $groupOptions, $mono, $asSelect])
                    @continue($groupOptions === [])
                    @if ($asSelect)
                        @php $selectId = 'sites-filter-'.str_replace('_', '-', $filterName); @endphp
                        <div class="plane-filter-group">
                            <label class="plane-filter-label" for="{{ $selectId }}">{{ $groupLabel }}</label>
                            <select
                                id="{{ $selectId }}"
                                name="{{ $filterName }}"
                                class="field-input ops-filter plane-filter-select"
                                form="sites-list-filters"
                                data-ops-list-filter
                                @if (count($groupOptions) > 8)
                                    data-ops-select-search
                                    data-ops-select-search-placeholder="{{ __('sites.filter_panel.search_options') }}"
                                    data-ops-select-empty="{{ __('sites.filter_panel.no_options') }}"
                                @endif
                            >
                                <option value="" @selected($filterValues[$filterName] === '')>{{ __('sites.filter_panel.all') }}</option>
                                @foreach ($groupOptions as $optionValue => $optionLabel)
                                    <option value="{{ $optionValue }}" @selected($filterValues[$filterName] === (string) $optionValue)>{{ $optionLabel }}</option>
                                @endforeach
                            </select>
                        </div>
                        @continue
                    @endif
                    <fieldset class="plane-filter-group">
                        <legend class="plane-filter-label">{{ $groupLabel }}</legend>
                        <div class="plane-filter-options">
                            <label class="plane-filter-option">
                                <input type="radio" name="{{ $filterName }}" value="" form="sites-list-filters" data-ops-list-filter @checked($filterValues[$filterName] === '')>
                                <span>{{ __('sites.filter_panel.all') }}</span>
                            </label>
                            @foreach ($groupOptions as $optionValue => $optionLabel)
                                <label class="plane-filter-option @if ($mono) is-mono @endif">
                                    <input type="radio" name="{{ $filterName }}" value="{{ $optionValue }}" form="sites-list-filters" data-ops-list-filter @checked($filterValues[$filterName] === (string) $optionValue)>
                                    @if ($filterName === 'theme' && in_array($optionValue, ['git', 'outdated'], true))
                                        @include('ops.sites._git-icon')
                                    @endif
                                    <span>{{ $optionLabel }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach
                <div class="plane-filter-actions">
                    <a class="btn btn-ghost btn-sm ops-clear-filters" href="{{ \App\Support\Lists\SiteSavedViews::clearUrl() }}" data-ops-list-clear{{ $filtersActive ? '' : ' hidden' }}>
                        <svg viewBox="0 0 16 16" width="11" height="11" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                        {{ __('ops.actions.clear_filters') }}
                    </a>
                </div>
            </div>

            <div class="ops-list-region" data-ops-list-region>
                @include('ops.sites._region')
            </div>
        </section>
    </div>
@endsection

@section('scripts')
    <script src="{{ asset('js/sites-filters.js') }}?v={{ filemtime(public_path('js/sites-filters.js')) }}" defer></script>
@endsection
