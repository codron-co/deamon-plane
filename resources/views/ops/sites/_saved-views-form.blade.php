@php
    /** @var \App\Support\Lists\SiteListView $listView */
    /** @var \App\Support\Lists\SiteSavedViews $savedViews */
@endphp

<details class="ops-action-menu ops-saved-view-picker" data-ops-action-menu>
    <summary class="btn btn-secondary btn-sm">{{ __('sites.saved_views.save') }}</summary>
    <div class="ops-action-popover" role="menu">
        <form
            method="POST"
            action="{{ route('ops.sites.list-views.store') }}"
            data-ops-pending
            data-ops-list-refresh
            data-ops-saved-view-form
        >
            @csrf
            <p class="ops-menu-label">{{ __('sites.saved_views.save_title') }}</p>
            <label class="ops-saved-view-name">
                <span class="visually-hidden">{{ __('sites.saved_views.name') }}</span>
                <input
                    type="text"
                    name="name"
                    maxlength="40"
                    required
                    placeholder="{{ __('sites.saved_views.name_placeholder') }}"
                    autocomplete="off"
                >
            </label>
            <label class="ops-saved-view-default-opt">
                <input type="checkbox" name="default" value="1">
                <span>{{ __('sites.saved_views.make_default') }}</span>
            </label>
            <input type="hidden" name="q" value="{{ $search }}">
            <input type="hidden" name="channel" value="{{ $channel }}">
            <input type="hidden" name="status" value="{{ $status }}">
            <input type="hidden" name="publish" value="{{ $publish }}">
            <input type="hidden" name="deploy" value="{{ $deploy ?? '' }}">
            <input type="hidden" name="agent" value="{{ $agent ?? '' }}">
            <input type="hidden" name="pack" value="{{ $pack ?? '' }}">
            <input type="hidden" name="health" value="{{ $health ?? '' }}">
            <input type="hidden" name="app" value="{{ $app ?? '' }}">
            <input type="hidden" name="theme" value="{{ $theme ?? '' }}">
            <input type="hidden" name="sort_key" value="{{ $listView->sortKey }}">
            <input type="hidden" name="sort_dir" value="{{ $listView->sortDirection }}">
            @foreach ($listView->columns as $columnKey)
                <input type="hidden" name="columns[]" value="{{ $columnKey }}">
            @endforeach
            <p class="ops-menu-hint">{{ $savedViews->atLimit() ? __('sites.saved_views.limit', ['max' => \App\Support\Lists\SiteSavedViews::MAX]) : __('sites.saved_views.hint') }}</p>
            <div class="ops-columns-actions">
                <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">
                    {{ __('sites.saved_views.save') }}
                </button>
            </div>
        </form>
    </div>
</details>
