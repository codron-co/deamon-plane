@props([
    'metric',
    'delta',
    'problem' => false,
    'trend',
])

{{--
    Change of one metric against the latest earlier fleet snapshot (SiteListSummary::trend()).
    Arrow + words carry the meaning; the bad/good tone only applies to problem metrics.
--}}
@php
    $direction = $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'same');
    $trendTone = match (true) {
        $direction === 'same' || ! $problem => 'neutral',
        $direction === 'up' => 'bad',
        default => 'good',
    };
    $suffix = ($trend['yesterday'] ?? false) ? '' : '_since';
    $trendArgs = [
        'count' => abs($delta),
        'date' => \Carbon\CarbonImmutable::parse($trend['date'])->format(__('sites.summary.trend.date_format')),
    ];
@endphp
<span class="plane-metric-trend is-{{ $direction }} is-{{ $trendTone }}" data-summary-trend="{{ $metric }}">
    <span aria-hidden="true">{{ ['up' => '▲', 'down' => '▼', 'same' => '='][$direction] }} {{ __('sites.summary.trend.'.$direction.$suffix, $trendArgs) }}</span>
    <span class="visually-hidden">{{ __('sites.summary.trend.sr_'.$direction.$suffix, $trendArgs) }}</span>
</span>
