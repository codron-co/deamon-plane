@php
    $unhealthySites = $unhealthySites ?? collect();
    $failedDeploys = $failedDeploys ?? collect();
    $dockerfilePackSites = $dockerfilePackSites ?? collect();
    $agentSecretSites = $agentSecretSites ?? collect();
    $hasAttention = $unhealthySites->isNotEmpty() || $failedDeploys->isNotEmpty() || $dockerfilePackSites->isNotEmpty() || $agentSecretSites->isNotEmpty();
    $filtersActive = $filtersActive ?? false;
    $unhealthyTotal = $unhealthyTotal ?? $unhealthySites->count();
    $failedTotal = $failedTotal ?? $failedDeploys->count();
    $agentSecretTotal = $agentSecretTotal ?? $agentSecretSites->count();
    $agentSecretCounts = $agentSecretCounts ?? ['missing' => 0, 'unverified' => 0, 'ok' => 0];
    $unhealthyHidden = max(0, $unhealthyTotal - $unhealthySites->count());
    $failedHidden = max(0, $failedTotal - $failedDeploys->count());
    $agentSecretHidden = max(0, $agentSecretTotal - $agentSecretSites->count());
    $failedWindowHours = $kpis['failed_deploy_window_hours'] ?? 24;
@endphp

@if (! $hasAttention && ! $filtersActive)
    <div class="empty-panel">
        <h2>{{ __('fleet.empty_title') }}</h2>
        <p>{{ __('fleet.empty_hint') }}</p>
        <div class="empty-panel-actions">
            <a class="btn btn-primary" href="{{ route('ops.sites') }}">{{ __('fleet.empty_action') }}</a>
        </div>
    </div>
@elseif (! $hasAttention)
    <div class="empty-panel empty-panel-filtered">
        <h2>{{ __('fleet.empty_filtered_title') }}</h2>
        <p>{{ __('fleet.empty_filtered_hint', ['total' => $totalAttention ?? 0]) }}</p>
        @include('ops.partials.filter-chips', [
            'chips' => $activeFilters ?? [],
            'label' => __('fleet.empty_filters_label'),
        ])
        <div class="empty-panel-actions">
            <a class="btn btn-primary" href="{{ route('ops.fleet') }}">{{ __('ops.actions.clear_filters') }}</a>
        </div>
    </div>
@else
    <section class="fleet-body" aria-label="{{ __('fleet.attention.aria') }}">
        @if ($unhealthySites->isNotEmpty())
            <aside class="fleet-attention is-danger" aria-labelledby="fleet-unhealthy-heading">
                <div class="fleet-attention-head">
                    <div>
                        <h2 id="fleet-unhealthy-heading" class="fleet-attention-title">{{ __('fleet.attention.unhealthy_title') }} @include('ops.dashboard._hint', ['text' => __('fleet.attention.unhealthy_lede')])</h2>
                    </div>
                    <span class="status-chip status-error">{{ $unhealthyTotal }}</span>
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
                @if ($unhealthyHidden > 0)
                    <p class="fleet-attention-more">
                        <a href="{{ route('ops.sites', ['health' => 'unhealthy']) }}">{{ __('fleet.attention.more', ['count' => $unhealthyHidden]) }}</a>
                    </p>
                @endif
            </aside>
        @endif

        @if ($failedDeploys->isNotEmpty())
            <aside class="fleet-attention is-danger" aria-labelledby="fleet-failed-heading">
                <div class="fleet-attention-head">
                    <div>
                        <h2 id="fleet-failed-heading" class="fleet-attention-title">{{ __('fleet.attention.failed_title') }} @include('ops.dashboard._hint', ['text' => __('fleet.attention.failed_lede', ['hours' => $failedWindowHours])])</h2>
                    </div>
                    <span class="status-chip status-failed">{{ $failedTotal }}</span>
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
                @if ($failedHidden > 0)
                    <p class="fleet-attention-more">
                        <a href="{{ route('ops.sites', ['deploy' => 'failed']) }}">{{ __('fleet.attention.more', ['count' => $failedHidden]) }}</a>
                    </p>
                @endif
            </aside>
        @endif

        @if ($agentSecretSites->isNotEmpty())
            <aside class="fleet-attention{{ ($agentSecretCounts['missing'] ?? 0) > 0 ? ' is-danger' : '' }}" aria-labelledby="fleet-agent-secret-heading">
                <div class="fleet-attention-head">
                    <div>
                        <h2 id="fleet-agent-secret-heading" class="fleet-attention-title">{{ __('fleet.attention.agent_secret_title') }} @include('ops.dashboard._hint', ['text' => __('fleet.attention.agent_secret_lede')])</h2>
                    </div>
                    @if ($filtersActive)
                        <span class="status-chip">{{ $agentSecretTotal }}</span>
                    @else
                        <span class="status-chip">{{ __('fleet.kpis.agent_missing', ['count' => $agentSecretCounts['missing'] ?? 0]) }} · {{ __('fleet.kpis.agent_unverified', ['count' => $agentSecretCounts['unverified'] ?? 0]) }} · {{ __('fleet.kpis.agent_ok', ['count' => $agentSecretCounts['ok'] ?? 0]) }}</span>
                    @endif
                </div>
                <ul class="fleet-attention-list">
                    @foreach ($agentSecretSites as $site)
                        @php
                            $agentState = $site->agentSecretFleetState();
                        @endphp
                        <li>
                            <a href="{{ route('ops.sites.show', $site) }}">
                                <span class="fleet-attention-name">{{ $site->name }}</span>
                                <code>{{ $site->primary_domain }}</code>
                            </a>
                            <span class="status-chip status-{{ $agentState === 'missing' ? 'error' : 'warning' }}">{{ __('sites.agent_states.'.$agentState) }}</span>
                        </li>
                    @endforeach
                </ul>
                @if ($agentSecretHidden > 0)
                    <p class="fleet-attention-more">
                        <a href="{{ route('ops.sites', ['agent' => ($agentSecretCounts['missing'] ?? 0) > 0 ? 'missing' : 'unverified']) }}">{{ __('fleet.attention.more', ['count' => $agentSecretHidden]) }}</a>
                    </p>
                @endif
                @if (($canWrite ?? false) && ! $filtersActive && ($agentSecretCounts['missing'] ?? 0) > 0)
                    <form
                        method="POST"
                        action="{{ route('ops.sites.bulk.agent-secret') }}"
                        class="fleet-attention-actions"
                        data-ops-pending
                        data-confirm="{{ __('sites.agent.bulk_confirm', ['count' => $agentSecretCounts['missing']]) }}"
                        data-confirm-title="{{ __('sites.agent.inject_title') }}"
                        data-confirm-label="{{ __('sites.agent.bulk') }}"
                        data-confirm-danger="true"
                    >
                        @csrf
                        <input type="hidden" name="all" value="1">
                        <input type="hidden" name="filter_agent" value="missing">
                        <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.agent.bulk') }}</button>
                    </form>
                @endif
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
