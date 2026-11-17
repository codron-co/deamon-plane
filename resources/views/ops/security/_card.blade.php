@php
    /** @var array<string, mixed> $card */
    /** @var bool $canWrite */
@endphp
<article class="security-card">
    <header>
        <a href="{{ route('ops.security.events', ['ip' => $card['ip'], 'period' => '7d']) }}"><code>{{ $card['ip'] }}</code></a>
        <span class="status-chip status-error">{{ __('security.correlation.sites_count', ['count' => $card['sites_count']]) }}</span>
    </header>
    <p class="muted">{{ __('security.correlation.events_count', ['count' => number_format($card['events']), 'critical' => number_format($card['critical'])]) }}</p>
    <ul class="security-card-sites">
        @foreach (array_slice($card['sites'], 0, 6) as $row)
            <li><a href="{{ route('ops.security.site', $row['id']) }}">{{ $row['name'] }}</a> <span class="muted">{{ __('security.events.count_note', ['count' => number_format($row['events'])]) }}</span></li>
        @endforeach
    </ul>
    @if (count($card['rules']) > 0)
        <p class="muted">{{ __('security.correlation.rules') }}: {{ implode(', ', $card['rules']) }}</p>
    @endif
    <footer>
        @if ($card['blocked'])
            <span class="status-chip status-error">{{ __('security.events.blocked') }}</span>
        @elseif ($canWrite)
            @include('ops.security._block_form', ['ip' => $card['ip']])
        @endif
    </footer>
</article>
