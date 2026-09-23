@php
    // Targets live in the markup so ops-shortcuts.js never builds a URL or a label itself.
    $shortcutTargets = [
        ['key' => 'f', 'url' => route('ops.fleet'), 'label' => __('ops.nav.fleet')],
        ['key' => 's', 'url' => route('ops.sites'), 'label' => __('ops.nav.sites')],
        ['key' => 'd', 'url' => route('ops.domains'), 'label' => __('ops.nav.domains')],
        ['key' => 't', 'url' => route('ops.themes'), 'label' => __('ops.nav.themes')],
    ];
@endphp

<div class="ops-shortcuts" data-ops-shortcuts hidden>
    <div class="ops-shortcuts-backdrop" data-ops-shortcuts-close></div>
    <div
        class="ops-shortcuts-panel"
        data-ops-shortcuts-panel
        role="dialog"
        aria-modal="true"
        aria-labelledby="ops-shortcuts-title"
        tabindex="-1"
    >
        <div class="ops-shortcuts-head">
            <h2 id="ops-shortcuts-title">{{ __('ops.shortcuts.title') }}</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-ops-shortcuts-close>{{ __('ops.shortcuts.close') }}</button>
        </div>

        <p class="ops-shortcuts-hint">{{ __('ops.shortcuts.hint') }}</p>

        <ul class="ops-shortcuts-list">
            <li>
                <span class="ops-shortcuts-keys"><kbd>/</kbd></span>
                <span>{{ __('ops.shortcuts.search') }}</span>
            </li>
            <li>
                <span class="ops-shortcuts-keys"><kbd>Ctrl</kbd><kbd>K</kbd></span>
                <span>{{ __('ops.shortcuts.palette') }}</span>
            </li>
            @foreach ($shortcutTargets as $target)
                <li data-ops-shortcuts-go="{{ $target['key'] }}" data-ops-shortcuts-url="{{ $target['url'] }}">
                    <span class="ops-shortcuts-keys"><kbd>g</kbd><kbd>{{ $target['key'] }}</kbd></span>
                    <span>{{ __('ops.shortcuts.go_to', ['page' => $target['label']]) }}</span>
                </li>
            @endforeach
            <li>
                <span class="ops-shortcuts-keys"><kbd>?</kbd></span>
                <span>{{ __('ops.shortcuts.help') }}</span>
            </li>
            <li>
                <span class="ops-shortcuts-keys"><kbd>Esc</kbd></span>
                <span>{{ __('ops.shortcuts.dismiss') }}</span>
            </li>
        </ul>

        {{-- Only listed where they act: the overlay never promises a key that does nothing. --}}
        @if (request()->routeIs('ops.sites'))
            <h3 class="ops-shortcuts-group">{{ __('ops.shortcuts.list_group') }}</h3>
            <ul class="ops-shortcuts-list" data-ops-shortcuts-list="sites">
                <li data-ops-shortcuts-key="f">
                    <span class="ops-shortcuts-keys"><kbd>f</kbd></span>
                    <span>{{ __('ops.shortcuts.filters') }}</span>
                </li>
                <li data-ops-shortcuts-key="Escape">
                    <span class="ops-shortcuts-keys"><kbd>Esc</kbd></span>
                    <span>{{ __('ops.shortcuts.clear_search') }}</span>
                </li>
            </ul>
        @endif

        <p class="ops-shortcuts-foot">{{ __('ops.shortcuts.typing_note') }}</p>
    </div>
</div>
