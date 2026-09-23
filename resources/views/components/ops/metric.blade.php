@props([
    'label',
    'value',
    'tone' => 'neutral',
    'href' => null,
    'icon' => null,
    'detail' => null,
    'trendKey' => null,
    'delta' => null,
    'problem' => false,
    'trend' => null,
])

{{--
    One summary tile: label + icon, a big number, an optional "x / y" or short detail line,
    and an optional change since the last fleet snapshot. With an href the whole tile is the
    link into the matching filtered list; without one it is a plain, non-interactive card.
--}}
@php
    $tag = filled($href) ? 'a' : 'div';
@endphp
<{{ $tag }} {{ $attributes->class(['plane-metric', 'is-'.$tone, 'is-static' => $tag === 'div']) }} @if ($tag === 'a') href="{{ $href }}" @endif>
    <span class="plane-metric-top">
        <span>{{ $label }}</span>
        @if (filled($icon))
            <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true"><path d="{{ $icon }}" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
        @endif
    </span>
    <span class="plane-metric-value">
        <strong>{{ $value }}</strong>
        @if (filled($detail))
            <span>{{ $detail }}</span>
        @endif
    </span>
    @if ($delta !== null && is_array($trend))
        <x-ops.metric-trend :metric="$trendKey" :delta="$delta" :problem="$problem" :trend="$trend" />
    @endif
    {{ $slot }}
</{{ $tag }}>
