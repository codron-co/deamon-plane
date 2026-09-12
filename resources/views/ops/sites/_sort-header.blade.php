@php
    /** @var \App\Support\Lists\SiteListView $listView */
    /** @var string $column */
    $label = \App\Support\Lists\SiteListColumns::label($column);
    $sortable = \App\Support\Lists\SiteListColumns::isSortable($column);
    $active = $listView->isSortedBy($column);
    $direction = $active ? $listView->sortDirection : null;
@endphp

@if (! $sortable)
    <th scope="col">{{ $label }}</th>
@else
    <th scope="col" aria-sort="{{ $listView->ariaSort($column) }}" class="ops-th-sortable">
        <a
            class="ops-sort-link @if ($active) is-sorted is-{{ $direction }} @endif"
            href="{{ request()->fullUrlWithQuery(['sort' => $column, 'dir' => $listView->nextDirectionFor($column), 'page' => null]) }}"
            data-ops-list-focus="sort:{{ $column }}"
            aria-label="{{ $active
                ? __('sites.sort.active', ['column' => $label, 'direction' => __('sites.sort.'.$direction)])
                : __('sites.sort.by', ['column' => $label]) }}"
        >
            <span class="ops-sort-label">{{ $label }}</span>
            <svg class="ops-sort-caret" viewBox="0 0 12 12" width="12" height="12" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6">
                @if ($active)
                    <path d="M3 7.5 6 4.5l3 3" stroke-linecap="round" stroke-linejoin="round"/>
                @else
                    <path d="M3.5 5 6 2.5 8.5 5M3.5 7 6 9.5 8.5 7" stroke-linecap="round" stroke-linejoin="round"/>
                @endif
            </svg>
        </a>
    </th>
@endif
