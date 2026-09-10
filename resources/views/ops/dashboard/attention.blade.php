@php
    $unhealthySites = $unhealthySites ?? collect();
    $failedDeploys = $failedDeploys ?? collect();
    $dockerfilePackSites = $dockerfilePackSites ?? collect();
    $hasAttention = $unhealthySites->isNotEmpty() || $failedDeploys->isNotEmpty() || $dockerfilePackSites->isNotEmpty();
@endphp

@if ($hasAttention)
    <div class="site-section-heading">
        <div>
            <span class="site-section-kicker">{{ __('fleet.kicker') }}</span>
            <h2>{{ __('fleet.heading') }}</h2>
        </div>
    </div>
    <section class="fleet-body" aria-label="{{ __('fleet.attention.aria') }}">
        @if ($unhealthySites->isNotEmpty())
            <aside class="fleet-attention is-danger" aria-labelledby="fleet-unhealthy-heading">
                <div class="fleet-attention-head">
                    <div>
                        <h2 id="fleet-unhealthy-heading" class="fleet-attention-title">{{ __('fleet.attention.unhealthy_title') }} @include('ops.dashboard._hint', ['text' => __('fleet.attention.unhealthy_lede')])</h2>
                    </div>
                    <span class="status-chip status-error">{{ $unhealthySites->count() }}</span>
                </div>
                <ul class="fleet-attention-list">
                    @foreach ($unhealthySites as $site)
                        @php
                            $healthStatus = is_array($site->last_health_payload) ? ($site->last_health_payload['status'] ?? null) : null;
                        @endphp
                        <li>
                            <a href="{{ route('ops.sites.show', $site) }}">
                                <span class="fleet-attention-name">{{ $site->name }}</span>
                                <code>{{ $site->primary_domain }}</code>
                                @if (filled($healthStatus) && $healthStatus !== 'ok')
                                    <span class="site-slug">{{ __('ops.health.'.$healthStatus) }}</span>
                                @endif
                            </a>
                            <span class="status-chip status-{{ $site->status?->value }}">{{ $site->status?->label() }}</span>
                        </li>
                    @endforeach
                </ul>
            </aside>
        @endif

        @if ($failedDeploys->isNotEmpty())
            <aside class="fleet-attention is-danger" aria-labelledby="fleet-failed-heading">
                <div class="fleet-attention-head">
                    <div>
                        <h2 id="fleet-failed-heading" class="fleet-attention-title">{{ __('fleet.attention.failed_title') }} @include('ops.dashboard._hint', ['text' => __('fleet.attention.failed_lede')])</h2>
                    </div>
                    <span class="status-chip status-failed">{{ $failedDeploys->count() }}</span>
                </div>
                <ul class="fleet-attention-list">
                    @foreach ($failedDeploys as $deployment)
                        @continue($deployment->site === null)
                        <li>
                            <a href="{{ route('ops.sites.show', $deployment->site) }}">
                                <span class="fleet-attention-name">{{ $deployment->site->name }}</span>
                                <code>{{ $deployment->channel?->value }}</code>
                                @if (filled($deployment->error_message))
                                    <span class="site-slug">{{ \Illuminate\Support\Str::limit($deployment->error_message, 140) }}</span>
                                @endif
                            </a>
                            <span class="status-chip status-failed">{{ $deployment->status?->label() }}</span>
                        </li>
                    @endforeach
                </ul>
            </aside>
        @endif

        @if ($dockerfilePackSites->isNotEmpty())
            <aside class="fleet-attention" aria-labelledby="fleet-attention-heading">
                <div class="fleet-attention-head">
                    <div>
                        <h2 id="fleet-attention-heading" class="fleet-attention-title">{{ __('fleet.attention.dockerfile_title') }} @include('ops.dashboard._hint', ['text' => __('fleet.attention.dockerfile_lede')])</h2>
                    </div>
                    <span class="status-chip status-dockerfile">{{ $dockerfilePackSites->count() }}</span>
                </div>
                <ul class="fleet-attention-list">
                    @foreach ($dockerfilePackSites as $site)
                        <li>
                            <a href="{{ route('ops.sites.show', $site) }}">
                                <span class="fleet-attention-name">{{ $site->name }}</span>
                                <code>{{ $site->primary_domain }}</code>
                            </a>
                            <span class="status-chip status-dockerfile">{{ __('ops.dockerfile_chip') }}</span>
                        </li>
                    @endforeach
                </ul>
            </aside>
        @endif
    </section>
@endif
