<div class="site-section-heading">
    <div>
        <span class="site-section-kicker">{{ __('fleet.snapshot_kicker') }}</span>
        <h2>{{ __('fleet.kpis.aria') }} @include('ops.dashboard._hint', ['text' => __('fleet.lede')])</h2>
    </div>
</div>

<section class="kpi-grid" aria-label="{{ __('fleet.kpis.aria') }}">
    <article class="kpi-card">
        <p class="kpi-label">{{ __('fleet.kpis.unhealthy') }} @include('ops.dashboard._hint', ['text' => __('fleet.kpis.unhealthy_hint')])</p>
        <p class="kpi-value{{ $kpis['unhealthy'] === 0 ? ' muted' : '' }}">{{ $kpis['unhealthy'] }}</p>
    </article>
    <article class="kpi-card">
        <p class="kpi-label">{{ __('fleet.kpis.failed') }} @include('ops.dashboard._hint', ['text' => __('fleet.kpis.failed_hint')])</p>
        <p class="kpi-value{{ $kpis['failed_deploys'] === 0 ? ' muted' : '' }}">{{ $kpis['failed_deploys'] }}</p>
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
