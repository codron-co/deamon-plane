@php
    $agentSecret = $kpis['agent_secret'] ?? ['missing' => 0, 'unverified' => 0, 'ok' => 0];
    $agentOpen = ($agentSecret['missing'] ?? 0) + ($agentSecret['unverified'] ?? 0);
    $kpiTrend = $kpiTrend ?? null;
    $kpiIcons = [
        'sites' => 'M2.5 13.5V5.2L8 2.5l5.5 2.7v8.3H2.5Zm3-.5v-4h5v4',
        'unhealthy' => 'M8 5v3.5M8 11h.01M6.9 2.6 1.8 11.5A1.3 1.3 0 0 0 2.9 13.5h10.2a1.3 1.3 0 0 0 1.1-2L9.1 2.6a1.3 1.3 0 0 0-2.2 0Z',
        'failed' => 'M8 14A6 6 0 1 0 8 2a6 6 0 0 0 0 12ZM6 6l4 4M10 6l-4 4',
        'agent' => 'M10.5 6.5a2.5 2.5 0 1 0-2.4 2.5L4 13.1V14h2v-1h1v-1h1l1.1-1.1',
        'deploying' => 'M8 2.5v7M5 6.5l3 3 3-3M3 13.5h10',
        'branch' => 'M5 3.5v6M5 9.5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3ZM11 4.5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3ZM11 7.5c0 2-2.5 2-6 3',
    ];
@endphp

{{--
    Unfiltered fleet snapshot in the shared tile look (plane-metric). Tiles hold hints and
    several drill-down links, so they are cards with links inside, not one big link.
--}}
<section class="plane-metrics fleet-metrics" aria-label="{{ __('fleet.kpis.aria') }}" style="--plane-metric-columns: 6">
    <article class="kpi-card plane-metric is-neutral">
        <div class="plane-metric-top">
            <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true"><path d="{{ $kpiIcons['sites'] }}" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <p class="kpi-label">{{ __('fleet.kpis.sites') }} @include('ops.dashboard._hint', ['text' => __('fleet.kpis.sites_hint')])</p>
        </div>
        <p class="kpi-value">{{ $kpis['total_sites'] }}</p>
        @if (isset($kpiTrend['deltas']['total']))
            <x-ops.metric-trend metric="total" :delta="$kpiTrend['deltas']['total']" :trend="$kpiTrend" />
        @endif
        <a class="kpi-link" href="{{ route('ops.sites') }}">{{ __('fleet.empty_action') }}</a>
    </article>
    <article class="kpi-card plane-metric{{ $kpis['unhealthy'] > 0 ? ' is-alert is-danger' : ' is-quiet is-success' }}">
        <div class="plane-metric-top">
            <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true"><path d="{{ $kpiIcons['unhealthy'] }}" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <p class="kpi-label">{{ __('fleet.kpis.unhealthy') }} @include('ops.dashboard._hint', ['text' => __('fleet.kpis.unhealthy_hint')])</p>
        </div>
        <p class="kpi-value{{ $kpis['unhealthy'] === 0 ? ' muted' : '' }}">{{ $kpis['unhealthy'] }}</p>
        @if (isset($kpiTrend['deltas']['unhealthy']))
            <x-ops.metric-trend metric="unhealthy" :delta="$kpiTrend['deltas']['unhealthy']" :problem="true" :trend="$kpiTrend" />
        @endif
        @if ($kpis['unhealthy'] > 0)
            {{-- Same SQL verdict as the list: the page resolves exactly this number. --}}
            <a class="kpi-link" href="{{ route('ops.sites', ['health' => 'unhealthy']) }}">{{ __('fleet.attention.see_all') }}</a>
        @endif
    </article>
    <article class="kpi-card plane-metric{{ $kpis['failed_deploys'] > 0 ? ' is-alert is-danger' : ' is-quiet is-success' }}">
        <div class="plane-metric-top">
            <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true"><path d="{{ $kpiIcons['failed'] }}" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <p class="kpi-label">{{ __('fleet.kpis.failed', ['hours' => $kpis['failed_deploy_window_hours']]) }} @include('ops.dashboard._hint', ['text' => __('fleet.kpis.failed_hint', ['hours' => $kpis['failed_deploy_window_hours']])])</p>
        </div>
        <p class="kpi-value{{ $kpis['failed_deploys'] === 0 ? ' muted' : '' }}">{{ $kpis['failed_deploys'] }}</p>
        @if (isset($kpiTrend['deltas']['failed_deploys']))
            <x-ops.metric-trend metric="failed_deploys" :delta="$kpiTrend['deltas']['failed_deploys']" :problem="true" :trend="$kpiTrend" />
        @endif
        @if ($kpis['failed_deploys'] > 0)
            {{-- Same window, same per-site counting: the list resolves exactly this number. --}}
            <a class="kpi-link" href="{{ route('ops.sites', ['deploy' => 'failed']) }}">{{ __('fleet.attention.see_all') }}</a>
        @endif
    </article>
    <article class="kpi-card plane-metric{{ $agentOpen > 0 ? ' is-alert is-warning' : ' is-quiet is-success' }}">
        <div class="plane-metric-top">
            <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true"><path d="{{ $kpiIcons['agent'] }}" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <p class="kpi-label">{{ __('fleet.kpis.agent_secret') }} @include('ops.dashboard._hint', ['text' => __('fleet.kpis.agent_secret_hint')])</p>
        </div>
        <p class="kpi-value kpi-value-sm{{ $agentOpen === 0 ? ' muted' : '' }}">
            @if (($agentSecret['missing'] ?? 0) > 0)
                <a class="kpi-link" href="{{ route('ops.sites', ['agent' => 'missing']) }}">{{ __('fleet.kpis.agent_missing', ['count' => $agentSecret['missing'] ?? 0]) }}</a>
            @else
                <span>{{ __('fleet.kpis.agent_missing', ['count' => $agentSecret['missing'] ?? 0]) }}</span>
            @endif
            <span>·</span>
            @if (($agentSecret['unverified'] ?? 0) > 0)
                <a class="kpi-link" href="{{ route('ops.sites', ['agent' => 'unverified']) }}">{{ __('fleet.kpis.agent_unverified', ['count' => $agentSecret['unverified'] ?? 0]) }}</a>
            @else
                <span>{{ __('fleet.kpis.agent_unverified', ['count' => $agentSecret['unverified'] ?? 0]) }}</span>
            @endif
            <span>·</span>
            <span>{{ __('fleet.kpis.agent_ok', ['count' => $agentSecret['ok'] ?? 0]) }}</span>
        </p>
    </article>
    <article class="kpi-card plane-metric{{ $kpis['deploying'] > 0 ? ' is-accent' : ' is-neutral' }}">
        <div class="plane-metric-top">
            <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true"><path d="{{ $kpiIcons['deploying'] }}" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <p class="kpi-label">{{ __('fleet.kpis.deploying') }} @include('ops.dashboard._hint', ['text' => __('fleet.kpis.deploying_hint')])</p>
        </div>
        <p class="kpi-value{{ $kpis['deploying'] === 0 ? ' muted' : '' }}">{{ $kpis['deploying'] }}</p>
    </article>
    <article class="kpi-card plane-metric is-neutral">
        <div class="plane-metric-top">
            <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true"><path d="{{ $kpiIcons['branch'] }}" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <p class="kpi-label">{{ __('fleet.kpis.by_channel') }} @include('ops.dashboard._hint', ['text' => __('fleet.kpis.by_channel_hint')])</p>
        </div>
        <p class="kpi-value kpi-value-sm fleet-branch-counts">
            @foreach ($kpis['by_channel'] as $channel => $count)
                <a class="branch-chip" href="{{ route('ops.sites', ['channel' => $channel]) }}">{{ $channel }} {{ $count }}</a>
            @endforeach
        </p>
    </article>
</section>
