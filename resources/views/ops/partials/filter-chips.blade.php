@php
    /** @var list<array{key: string, label: string, value: string, url: string}> $chips */
    /** @var string $label */
@endphp

@if (($chips ?? []) !== [])
    {{-- Each remove link is a real GET link into the same list, so the async region swaps in place and no-JS still navigates. --}}
    <ul class="ops-filter-chips" aria-label="{{ $label ?? __('ops.filter.active') }}">
        @foreach ($chips as $chip)
            <li class="ops-filter-chip">
                <span class="ops-filter-chip-label">{{ $chip['label'] }}</span>
                <span class="ops-filter-chip-value">{{ $chip['value'] }}</span>
                <a
                    class="ops-filter-chip-remove"
                    href="{{ $chip['url'] }}"
                    aria-label="{{ __('ops.filter.remove', ['label' => $chip['label']]) }}"
                >
                    <svg viewBox="0 0 16 16" width="10" height="10" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                </a>
            </li>
        @endforeach
    </ul>
@endif
