<section class="kpi-grid" aria-label="Fleet snapshot">
    <article class="kpi-card">
        <p class="kpi-label">Sites</p>
        <p class="kpi-value">{{ $kpis['total_sites'] }}</p>
        <p class="kpi-hint">Managed Coolify sites</p>
    </article>
    <article class="kpi-card">
        <p class="kpi-label">By channel</p>
        <p class="kpi-value kpi-value-sm">
            @foreach ($kpis['by_channel'] as $channel => $count)
                <span>{{ $channel }} {{ $count }}@if (! $loop->last) · @endif</span>
            @endforeach
        </p>
        <p class="kpi-hint">Git branch allowlist</p>
    </article>
    <article class="kpi-card">
        <p class="kpi-label">Unhealthy</p>
        <p class="kpi-value">{{ $kpis['unhealthy'] }}</p>
        <p class="kpi-hint">Status error or agent health fail</p>
    </article>
    <article class="kpi-card">
        <p class="kpi-label">Failed deploys</p>
        <p class="kpi-value">{{ $kpis['failed_deploys'] }}</p>
        <p class="kpi-hint">All recorded Coolify deploys</p>
    </article>
    <article class="kpi-card">
        <p class="kpi-label">Deploying</p>
        <p class="kpi-value">{{ $kpis['deploying'] }}</p>
        <p class="kpi-hint">Provisioning or channel switch</p>
    </article>
</section>
