<section class="kpi-grid" aria-label="{{ __('fleet.kpis.aria') }}">
    <article class="kpi-card">
        <p class="kpi-label">{{ __('fleet.kpis.sites') }}</p>
        <p class="kpi-value">{{ $kpis['total_sites'] }}</p>
        <p class="kpi-hint">{{ __('fleet.kpis.sites_hint') }}</p>
    </article>
    <article class="kpi-card">
        <p class="kpi-label">{{ __('fleet.kpis.by_channel') }}</p>
        <p class="kpi-value kpi-value-sm">
            @foreach ($kpis['by_channel'] as $channel => $count)
                <span>{{ $channel }} {{ $count }}@if (! $loop->last) · @endif</span>
            @endforeach
        </p>
        <p class="kpi-hint">{{ __('fleet.kpis.by_channel_hint') }}</p>
    </article>
    <article class="kpi-card">
        <p class="kpi-label">{{ __('fleet.kpis.unhealthy') }}</p>
        <p class="kpi-value">{{ $kpis['unhealthy'] }}</p>
        <p class="kpi-hint">{{ __('fleet.kpis.unhealthy_hint') }}</p>
    </article>
    <article class="kpi-card">
        <p class="kpi-label">{{ __('fleet.kpis.failed') }}</p>
        <p class="kpi-value">{{ $kpis['failed_deploys'] }}</p>
        <p class="kpi-hint">{{ __('fleet.kpis.failed_hint') }}</p>
    </article>
    <article class="kpi-card">
        <p class="kpi-label">{{ __('fleet.kpis.deploying') }}</p>
        <p class="kpi-value">{{ $kpis['deploying'] }}</p>
        <p class="kpi-hint">{{ __('fleet.kpis.deploying_hint') }}</p>
    </article>
</section>
