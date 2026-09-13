<div
    class="ops-palette"
    data-ops-palette
    data-ops-palette-url="{{ route('ops.palette') }}"
    hidden
>
    <div class="ops-palette-backdrop" data-ops-palette-close></div>
    <div
        class="ops-palette-panel"
        data-ops-palette-panel
        role="dialog"
        aria-modal="true"
        aria-labelledby="ops-palette-title"
    >
        <h2 id="ops-palette-title" class="visually-hidden">{{ __('ops.palette.title') }}</h2>
        <label class="visually-hidden" for="ops-palette-input">{{ __('ops.palette.search') }}</label>
        <input
            id="ops-palette-input"
            class="ops-palette-input"
            type="search"
            name="q"
            autocomplete="off"
            spellcheck="false"
            data-ops-palette-input
            role="combobox"
            aria-autocomplete="list"
            aria-controls="ops-palette-results"
            aria-expanded="true"
            placeholder="{{ __('ops.palette.search') }}"
        >
        <div
            id="ops-palette-results"
            class="ops-palette-results"
            data-ops-palette-results
            role="listbox"
            aria-label="{{ __('ops.palette.title') }}"
        ></div>
        <p class="ops-palette-empty" data-ops-palette-empty hidden>{{ __('ops.palette.empty') }}</p>
        <p class="ops-palette-foot">{{ __('ops.palette.hint') }}</p>
    </div>
</div>
