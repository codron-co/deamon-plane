@php
    /** @var \App\Services\Mail\PlatformMailState $platformMailState */
    $withHint = $platformMailHint ?? false;
@endphp

<span class="ops-mail-state">
    <span class="status-chip {{ $platformMailState->chipClass() }}">{{ $platformMailState->label() }}</span>
    @if ($withHint)
        <span class="ops-mail-state-hint">{{ $platformMailState->hint() }}</span>
    @endif
</span>
