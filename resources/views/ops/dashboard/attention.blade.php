@if ($dockerfilePackSites->isNotEmpty() || ($unhealthySites ?? collect())->isNotEmpty() || ($failedDeploys ?? collect())->isNotEmpty())
    <section class="fleet-body" aria-label="{{ __('fleet.attention.aria') }}">
        @if ($dockerfilePackSites->isNotEmpty())
            <aside class="fleet-attention" aria-labelledby="fleet-attention-heading">
                <div class="fleet-attention-head">
                    <div>
                        <h2 id="fleet-attention-heading" class="fleet-attention-title">{{ __('fleet.attention.dockerfile_title') }}</h2>
                        <p class="fleet-attention-lede">{{ __('fleet.attention.dockerfile_lede') }}</p>
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

        @if (($unhealthySites ?? collect())->isNotEmpty())
            <aside class="fleet-attention" aria-labelledby="fleet-unhealthy-heading">
                <div class="fleet-attention-head">
                    <div>
                        <h2 id="fleet-unhealthy-heading" class="fleet-attention-title">{{ __('fleet.attention.unhealthy_title') }}</h2>
                        <p class="fleet-attention-lede">{{ __('fleet.attention.unhealthy_lede') }}</p>
                    </div>
                    <span class="status-chip status-error">{{ $unhealthySites->count() }}</span>
                </div>
                <ul class="fleet-attention-list">
                    @foreach ($unhealthySites as $site)
                        <li>
                            <a href="{{ route('ops.sites.show', $site) }}">
                                <span class="fleet-attention-name">{{ $site->name }}</span>
                                <code>{{ $site->primary_domain }}</code>
                            </a>
                            <span class="status-chip status-{{ $site->status?->value }}">{{ $site->status?->label() }}</span>
                        </li>
                    @endforeach
                </ul>
            </aside>
        @endif

        @if (($failedDeploys ?? collect())->isNotEmpty())
            <aside class="fleet-attention" aria-labelledby="fleet-failed-heading">
                <div class="fleet-attention-head">
                    <div>
                        <h2 id="fleet-failed-heading" class="fleet-attention-title">{{ __('fleet.attention.failed_title') }}</h2>
                        <p class="fleet-attention-lede">{{ __('fleet.attention.failed_lede') }}</p>
                    </div>
                    <span class="status-chip status-failed">{{ $failedDeploys->count() }}</span>
                </div>
                <ul class="fleet-attention-list">
                    @foreach ($failedDeploys as $deployment)
                        <li>
                            <a href="{{ route('ops.sites.show', $deployment->site) }}">
                                <span class="fleet-attention-name">{{ $deployment->site?->name ?? __('ops.none') }}</span>
                                <code>{{ $deployment->channel?->value }}</code>
                            </a>
                            <span class="status-chip status-failed">{{ $deployment->status?->label() }}</span>
                        </li>
                    @endforeach
                </ul>
            </aside>
        @endif
    </section>
@endif
