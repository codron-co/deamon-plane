@php
    /** @var \App\Support\Lists\SiteListView $listView */
    $viewIcons = [
        'list' => '<path d="M6 4h8M6 8h8M6 12h8"/><circle cx="2.75" cy="4" r=".75" fill="currentColor" stroke="none"/><circle cx="2.75" cy="8" r=".75" fill="currentColor" stroke="none"/><circle cx="2.75" cy="12" r=".75" fill="currentColor" stroke="none"/>',
        'compact' => '<path d="M2.5 3h11M2.5 6.3h11M2.5 9.7h11M2.5 13h11"/>',
        'cards' => '<rect x="2.25" y="2.25" width="4.75" height="4.75" rx="1"/><rect x="9" y="2.25" width="4.75" height="4.75" rx="1"/><rect x="2.25" y="9" width="4.75" height="4.75" rx="1"/><rect x="9" y="9" width="4.75" height="4.75" rx="1"/>',
    ];
@endphp

{{--
    Stored on the account (users.list_preferences.sites.mode) so the first paint is
    already right on any device; sites-filters.js applies a click at once and saves
    it over fetch. Without JavaScript each button is a plain form submit.
--}}
<form
    method="POST"
    action="{{ route('ops.sites.list-mode') }}"
    class="plane-view-switch"
    role="group"
    aria-label="{{ __('sites.view_mode.label') }}"
    data-sites-view-switch
    data-saved-template="{{ __('sites.view_mode.saved', ['mode' => '__MODE__']) }}"
>
    @csrf
    @foreach (\App\Support\Lists\SiteListView::MODES as $viewMode)
        <button
            type="submit"
            name="mode"
            value="{{ $viewMode }}"
            class="plane-view-switch-btn"
            aria-pressed="{{ $listView->mode === $viewMode ? 'true' : 'false' }}"
            title="{{ __('sites.view_mode.'.$viewMode) }}"
            data-sites-view-option="{{ $viewMode }}"
        >
            <svg viewBox="0 0 16 16" width="15" height="15" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round">{!! $viewIcons[$viewMode] !!}</svg>
            <span class="visually-hidden">{{ __('sites.view_mode.'.$viewMode) }}</span>
        </button>
    @endforeach
    <span class="visually-hidden" aria-live="polite" data-sites-view-status></span>
</form>
