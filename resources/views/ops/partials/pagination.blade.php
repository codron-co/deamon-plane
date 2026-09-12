@php
    /** @var \Illuminate\Contracts\Pagination\Paginator $paginator */
    /** @var string $label */
@endphp

@if ($paginator->hasPages())
    {{-- Real links: paging works without JS and the async list swaps the region in place. --}}
    <nav class="ops-pagination" aria-label="{{ $label }}">
        @if ($paginator->onFirstPage())
            <span class="btn btn-ghost btn-sm" aria-disabled="true">{{ __('ops.actions.previous') }}</span>
        @else
            <a class="btn btn-ghost btn-sm" href="{{ $paginator->previousPageUrl() }}" data-ops-list-focus="page:previous">{{ __('ops.actions.previous') }}</a>
        @endif
        <span class="ops-page-meta">{{ __('ops.pagination', ['from' => $paginator->firstItem(), 'to' => $paginator->lastItem(), 'total' => $paginator->total()]) }}</span>
        @if ($paginator->hasMorePages())
            <a class="btn btn-ghost btn-sm" href="{{ $paginator->nextPageUrl() }}" data-ops-list-focus="page:next">{{ __('ops.actions.next') }}</a>
        @else
            <span class="btn btn-ghost btn-sm" aria-disabled="true">{{ __('ops.actions.next') }}</span>
        @endif
    </nav>
@endif
