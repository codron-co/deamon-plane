@php
    /** @var \App\Support\Lists\SiteSavedViews $savedViews */
    $chips = $savedViews->chips();
@endphp

<div class="ops-saved-views" data-ops-saved-views aria-label="{{ __('sites.saved_views.label') }}">
    <ul class="ops-saved-view-list">
        @foreach ($chips as $chip)
            <li class="ops-saved-view-item">
                <a
                    class="ops-saved-view @if ($chip['active']) is-active @endif"
                    href="{{ $chip['url'] }}"
                    data-ops-list-view="{{ $chip['id'] }}"
                    @if ($chip['active']) aria-current="page" @endif
                >
                    <span>{{ $chip['name'] }}</span>
                    @if ($chip['default'])
                        <span class="ops-saved-view-default">{{ __('sites.saved_views.default_badge') }}</span>
                    @endif
                </a>
                @if ($chip['saved'])
                    <form
                        method="POST"
                        action="{{ route('ops.sites.list-views.destroy', $chip['id']) }}"
                        class="ops-saved-view-delete"
                        data-ops-pending
                        data-ops-list-refresh
                    >
                        @csrf
                        @method('DELETE')
                        <button
                            type="submit"
                            class="ops-saved-view-delete-btn"
                            data-pending-label="…"
                            aria-label="{{ __('sites.saved_views.delete') }}: {{ $chip['name'] }}"
                        >
                            <svg viewBox="0 0 16 16" width="10" height="10" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                        </button>
                    </form>
                @endif
            </li>
        @endforeach
    </ul>
</div>
