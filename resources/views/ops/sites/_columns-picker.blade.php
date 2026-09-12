@php
    /** @var \App\Support\Lists\SiteListView $listView */
    $catalog = \App\Support\Lists\SiteListColumns::keys();
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
            <ul class="ops-columns-list">
                @foreach ($catalog as $columnKey)
                    @php $locked = \App\Support\Lists\SiteListColumns::isLocked($columnKey); @endphp
                    <li>
                        <label class="ops-columns-option @if ($locked) is-locked @endif">
                            <input
                                type="checkbox"
                                name="columns[]"
                                value="{{ $columnKey }}"
                                @checked($listView->shows($columnKey))
                                @disabled($locked)
                            >
                            <span>{{ \App\Support\Lists\SiteListColumns::label($columnKey) }}</span>
                            @if ($locked)
                                {{-- Locked columns are disabled inputs, so post them explicitly. --}}
                                <input type="hidden" name="columns[]" value="{{ $columnKey }}">
                                <span class="ops-columns-locked">{{ __('sites.columns_picker.locked') }}</span>
                            @endif
                        </label>
                    </li>
                @endforeach
            </ul>
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
