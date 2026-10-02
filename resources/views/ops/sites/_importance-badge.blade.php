@php
    /** @var \App\Enums\SiteImportance $level */
@endphp
{{-- Normal is the absence of a badge; the label is spelled out so the level never rests on colour alone. --}}
@if ($level->isFlagged())
    <span class="site-importance is-{{ $level->key() }}" title="{{ __('sites.importance.title', ['level' => $level->label()]) }}">
        <svg viewBox="0 0 16 16" width="10" height="10" aria-hidden="true"><path d="M8 1.8l1.9 3.9 4.3.6-3.1 3 .7 4.3L8 11.6l-3.8 2 .7-4.3-3.1-3 4.3-.6L8 1.8Z" fill="currentColor"/></svg>
        {{ $level->label() }}
    </span>
@endif
