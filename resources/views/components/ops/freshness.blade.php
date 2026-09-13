@props([
    'at' => null,
    'missing' => null,
    'markStale' => true,
])

@php
    /** @var array{missing: bool, stale: bool, label: string, absolute: ?string} $fresh */
    $fresh = \App\Support\OpsFreshness::describe($at, (bool) $markStale);
    $missingLabel = $missing ?? __('ops.never');
@endphp

@if ($fresh['missing'])
    <span {{ $attributes->class(['ops-freshness', 'is-missing']) }}>{{ $missingLabel }}</span>
@else
    <span
        {{ $attributes->class(['ops-freshness', 'is-stale' => $fresh['stale']]) }}
        title="{{ $fresh['absolute'] }}"
    >
        <span class="ops-freshness-age">{{ $fresh['label'] }}</span>
        @if ($fresh['stale'])
            <span class="ops-freshness-flag">{{ __('ops.freshness.stale') }}</span>
        @endif
    </span>
@endif
