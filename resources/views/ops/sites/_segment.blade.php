@php
    /**
     * One segmented row for every preset: the quick filters, the built-in views and
     * the operator's saved views. Every item is a plain GET link, so it works without
     * JavaScript; the server marks the active one. After an in-place region swap the
     * region carries a fresh copy in a <template> that sites-filters.js puts back here.
     *
     * @var \App\Support\Lists\SiteSavedViews|null $savedViews
     */
    $segmentFilters = isset($savedViews) ? $savedViews->filters : [];
    $segmentActiveId = isset($savedViews) ? $savedViews->activeId : null;
    $segmentNoNamedView = in_array($segmentActiveId, [null, \App\Support\Lists\SiteSavedViews::ALL], true);

    $segmentQuick = [
        'unhealthy' => [__('sites.filter_panel.quick_unhealthy'), ['health' => 'unhealthy']],
        'failed' => [__('sites.filter_panel.quick_failed'), ['deploy' => 'failed']],
        'git' => [__('sites.filter_panel.quick_git'), ['theme' => 'git']],
    ];

    $segmentChips = isset($savedViews) ? $savedViews->chips() : [];
    $segmentBuiltins = array_values(array_filter(
        $segmentChips,
        static fn (array $chip): bool => ! $chip['saved'] && $chip['id'] !== \App\Support\Lists\SiteSavedViews::ALL,
    ));
    $segmentSaved = array_values(array_filter($segmentChips, static fn (array $chip): bool => $chip['saved']));
    $segmentAllActive = $segmentFilters === [] && $segmentNoNamedView;
@endphp

<div class="plane-segment-wrap" data-sites-segment-wrap>
    <nav class="plane-segment" aria-label="{{ __('sites.filter_panel.segment_label') }}" data-sites-segment>
        <a
            class="plane-segment-item @if ($segmentAllActive) is-active @endif"
            href="{{ \App\Support\Lists\SiteSavedViews::clearUrl() }}"
            data-ops-list-view="all"
            @if ($segmentAllActive) aria-current="page" @endif
        >{{ __('sites.saved_views.all') }}</a>

        @foreach ($segmentQuick as $quickKey => [$quickLabel, $quickParams])
            @php $quickActive = $segmentNoNamedView && $segmentFilters == $quickParams; @endphp
            <a
                class="plane-segment-item @if ($quickActive) is-active @endif"
                href="{{ route('ops.sites', $quickParams) }}"
                data-ops-list-view="quick-{{ $quickKey }}"
                @if ($quickActive) aria-current="page" @endif
            >{{ $quickLabel }}</a>
        @endforeach

        @foreach ($segmentBuiltins as $chip)
            <a
                class="plane-segment-item @if ($chip['active']) is-active @endif"
                href="{{ $chip['url'] }}"
                data-ops-list-view="{{ $chip['id'] }}"
                @if ($chip['active']) aria-current="page" @endif
            >{{ $chip['name'] }}</a>
        @endforeach

        @if ($segmentSaved !== [])
            <span class="plane-segment-sep" role="separator" aria-label="{{ __('sites.saved_views.label') }}"></span>
            @foreach ($segmentSaved as $chip)
                <span class="plane-segment-group @if ($chip['active']) is-active @endif" data-sites-saved-view="{{ $chip['id'] }}">
                    <a
                        class="plane-segment-item @if ($chip['active']) is-active @endif"
                        href="{{ $chip['url'] }}"
                        data-ops-list-view="{{ $chip['id'] }}"
                        @if ($chip['active']) aria-current="page" @endif
                    >
                        <span>{{ $chip['name'] }}</span>
                        @if ($chip['default'])
                            <span class="plane-segment-badge">{{ __('sites.saved_views.default_badge') }}</span>
                        @endif
                    </a>
                    <form
                        method="POST"
                        action="{{ route('ops.sites.list-views.destroy', $chip['id']) }}"
                        class="plane-segment-delete"
                        data-ops-pending
                        data-ops-list-refresh
                        data-confirm="{{ __('sites.saved_views.delete_confirm', ['name' => $chip['name']]) }}"
                        data-confirm-title="{{ __('sites.saved_views.delete') }}"
                        data-confirm-label="{{ __('sites.saved_views.delete') }}"
                        data-confirm-danger="true"
                    >
                        @csrf
                        @method('DELETE')
                        <button
                            type="submit"
                            class="plane-segment-delete-btn"
                            data-pending-label="…"
                            aria-label="{{ __('sites.saved_views.delete') }}: {{ $chip['name'] }}"
                            title="{{ __('sites.saved_views.delete') }}"
                        >
                            <svg viewBox="0 0 16 16" width="10" height="10" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                        </button>
                    </form>
                </span>
            @endforeach
        @endif
    </nav>
</div>
