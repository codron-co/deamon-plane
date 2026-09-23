@php
    /** @var \App\Support\Lists\SiteListView $listView */
    // Visible columns in the operator's order, then the hidden ones. Posting the
    // form as rendered keeps the current order even without JavaScript.
    $catalog = \App\Support\Lists\SiteListColumns::pickerOrder($listView->columns);
@endphp

<details class="ops-action-menu ops-columns-picker" data-ops-action-menu data-ops-columns-picker>
    <summary class="btn btn-secondary btn-sm">
        <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.4">
            <rect x="2.25" y="2.75" width="11.5" height="10.5" rx="1.5"/>
            <path d="M6.5 2.75v10.5M10 2.75v10.5"/>
        </svg>
        {{ __('sites.columns_picker.trigger') }}
    </summary>
    <div class="ops-action-popover ops-columns-popover" role="menu">
        {{-- data-ops-list-refresh: the saved layout only shows up once the table region is re-rendered. --}}
        <form method="POST" action="{{ route('ops.sites.list-preferences') }}" data-ops-pending data-ops-list-refresh>
            @csrf
            <p class="ops-menu-label">{{ __('sites.columns_picker.title') }}</p>
            <ul
                class="ops-columns-list"
                data-ops-columns-list
                data-moved-template="{{ __('sites.columns_picker.moved', ['column' => '__COLUMN__', 'position' => '__POSITION__']) }}"
            >
                @foreach ($catalog as $columnKey)
                    @php
                        $locked = \App\Support\Lists\SiteListColumns::isLocked($columnKey);
                        $columnLabel = \App\Support\Lists\SiteListColumns::label($columnKey);
                    @endphp
                    <li
                        class="ops-columns-item"
                        data-ops-column-key="{{ $columnKey }}"
                        data-ops-column-label="{{ $columnLabel }}"
                        @if ($locked) data-ops-column-locked @endif
                    >
                        <label class="ops-columns-option @if ($locked) is-locked @endif">
                            <input
                                type="checkbox"
                                name="columns[]"
                                value="{{ $columnKey }}"
                                @checked($listView->shows($columnKey))
                                @disabled($locked)
                            >
                            <span>{{ $columnLabel }}</span>
                            @if ($locked)
                                {{-- Locked columns are disabled inputs, so post them explicitly. --}}
                                <input type="hidden" name="columns[]" value="{{ $columnKey }}">
                                <span class="ops-columns-locked">{{ __('sites.columns_picker.locked') }}</span>
                            @endif
                        </label>
                        @unless ($locked)
                            {{-- Revealed by ops-list.js: without it the buttons could not move anything. --}}
                            <span class="ops-columns-move" data-ops-column-move-group hidden>
                                <button
                                    type="button"
                                    class="ops-columns-move-btn"
                                    data-ops-column-move="-1"
                                    aria-label="{{ __('sites.columns_picker.move_up', ['column' => $columnLabel]) }}"
                                >
                                    <svg viewBox="0 0 12 12" width="12" height="12" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 7.5 6 4.5l3 3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                                <button
                                    type="button"
                                    class="ops-columns-move-btn"
                                    data-ops-column-move="1"
                                    aria-label="{{ __('sites.columns_picker.move_down', ['column' => $columnLabel]) }}"
                                >
                                    <svg viewBox="0 0 12 12" width="12" height="12" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 4.5 6 7.5l3-3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                            </span>
                        @endunless
                    </li>
                @endforeach
            </ul>
            <p class="visually-hidden" aria-live="polite" data-ops-columns-status></p>
            <p class="ops-menu-hint" data-ops-columns-order-hint hidden>{{ __('sites.columns_picker.order_hint') }}</p>
            <p class="ops-menu-hint">{{ __('sites.columns_picker.hint') }}</p>
            <div class="ops-columns-actions">
                <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">
                    {{ __('sites.columns_picker.save') }}
                </button>
            </div>
        </form>
        <div class="ops-action-sep" role="separator"></div>
        <form method="POST" action="{{ route('ops.sites.list-preferences.reset') }}" data-ops-pending data-ops-list-refresh>
            @csrf
            @method('DELETE')
            <button type="submit" class="ops-menu-button" role="menuitem" data-pending-label="{{ __('ops.actions.working') }}">
                {{ __('sites.columns_picker.reset') }}
            </button>
        </form>
    </div>
</details>
