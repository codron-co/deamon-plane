<div class="site-section-heading">
    <div>
        <span class="site-section-kicker">{{ __('fleet.snapshot_kicker') }}</span>
        <h2>{{ __('fleet.kpis.aria') }} @include('ops.dashboard._hint', ['text' => __('fleet.lede')])</h2>
    </div>
</div>

<section class="kpi-grid" aria-label="{{ __('fleet.kpis.aria') }}">
    <article class="kpi-card{{ $kpis['unhealthy'] > 0 ? ' is-alert' : ' is-quiet' }}">
        <p class="kpi-label">{{ __('fleet.kpis.unhealthy') }} @include('ops.dashboard._hint', ['text' => __('fleet.kpis.unhealthy_hint')])</p>
        <p class="kpi-value{{ $kpis['unhealthy'] === 0 ? ' muted' : '' }}">{{ $kpis['unhealthy'] }}</p>
        @if ($kpis['unhealthy'] > 0)
            {{-- Same SQL verdict as the list: the page resolves exactly this number. --}}
            <a class="kpi-link" href="{{ route('ops.sites', ['health' => 'unhealthy']) }}">{{ __('fleet.attention.see_all') }}</a>
        @endif
    </article>
    <article class="kpi-card{{ $kpis['failed_deploys'] > 0 ? ' is-alert' : ' is-quiet' }}">
        <p class="kpi-label">{{ __('fleet.kpis.failed', ['hours' => $kpis['failed_deploy_window_hours']]) }} @include('ops.dashboard._hint', ['text' => __('fleet.kpis.failed_hint', ['hours' => $kpis['failed_deploy_window_hours']])])</p>
        <p class="kpi-value{{ $kpis['failed_deploys'] === 0 ? ' muted' : '' }}">{{ $kpis['failed_deploys'] }}</p>
        @if ($kpis['failed_deploys'] > 0)
            {{-- Same window, same per-site counting: the list resolves exactly this number. --}}
            <a class="kpi-link" href="{{ route('ops.sites', ['deploy' => 'failed']) }}">{{ __('fleet.attention.see_all') }}</a>
        @endif
    </article>
    <article class="kpi-card{{ (($kpis['agent_secret']['missing'] ?? 0) + ($kpis['agent_secret']['unverified'] ?? 0)) > 0 ? ' is-alert' : ' is-quiet' }}">
        <p class="kpi-label">{{ __('fleet.kpis.agent_secret') }} @include('ops.dashboard._hint', ['text' => __('fleet.kpis.agent_secret_hint')])</p>
        <p class="kpi-value kpi-value-sm{{ (($kpis['agent_secret']['missing'] ?? 0) + ($kpis['agent_secret']['unverified'] ?? 0)) === 0 ? ' muted' : '' }}">
            @if (($kpis['agent_secret']['missing'] ?? 0) > 0)
                <a class="kpi-link" href="{{ route('ops.sites', ['agent' => 'missing']) }}">{{ __('fleet.kpis.agent_missing', ['count' => $kpis['agent_secret']['missing'] ?? 0]) }}</a>
            @else
                <span>{{ __('fleet.kpis.agent_missing', ['count' => $kpis['agent_secret']['missing'] ?? 0]) }}</span>
            @endif
            <span>·</span>
            @if (($kpis['agent_secret']['unverified'] ?? 0) > 0)
                <a class="kpi-link" href="{{ route('ops.sites', ['agent' => 'unverified']) }}">{{ __('fleet.kpis.agent_unverified', ['count' => $kpis['agent_secret']['unverified'] ?? 0]) }}</a>
            @else
                <span>{{ __('fleet.kpis.agent_unverified', ['count' => $kpis['agent_secret']['unverified'] ?? 0]) }}</span>
            @endif
            <span>·</span>
            <span>{{ __('fleet.kpis.agent_ok', ['count' => $kpis['agent_secret']['ok'] ?? 0]) }}</span>
        </p>
    </article>
    <article class="kpi-card">
        <p class="kpi-label">{{ __('fleet.kpis.deploying') }} @include('ops.dashboard._hint', ['text' => __('fleet.kpis.deploying_hint')])</p>
        <p class="kpi-value{{ $kpis['deploying'] === 0 ? ' muted' : '' }}">{{ $kpis['deploying'] }}</p>
    </article>
    <article class="kpi-card">
        <p class="kpi-label">{{ __('fleet.kpis.sites') }} @include('ops.dashboard._hint', ['text' => __('fleet.kpis.sites_hint')])</p>
        <p class="kpi-value">{{ $kpis['total_sites'] }}</p>
    </article>
    <article class="kpi-card">
        <p class="kpi-label">{{ __('fleet.kpis.by_channel') }} @include('ops.dashboard._hint', ['text' => __('fleet.kpis.by_channel_hint')])</p>
        <p class="kpi-value kpi-value-sm">
            @foreach ($kpis['by_channel'] as $channel => $count)
                <span>{{ $channel }} {{ $count }}@if (! $loop->last) · @endif</span>
            @endforeach
        </p>
    </article>
</section>
