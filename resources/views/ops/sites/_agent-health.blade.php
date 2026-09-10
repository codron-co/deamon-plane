@php
    /** @var \App\Models\Site $site */
    /** @var \App\Services\Agent\SiteHealthEvaluator $agentHealth */
    $canCheckHealth = $canCheckHealth ?? false;
    $canWriteAgentSecret = $canInjectAgentSecret ?? false;
    $payload = $agentHealth->payload($site);
    $display = $agentHealth->displayStatus($site);
    $version = $site->reportedDeamonVersion();
    $themeId = is_string($payload['active_theme_id'] ?? null) ? $payload['active_theme_id'] : null;
    $queueOk = array_key_exists('queue_ok', $payload) ? $payload['queue_ok'] : null;
    $reason = is_string($payload['reason'] ?? null) ? $payload['reason'] : null;
    $agentHint = __('sites.agent.lede', ['path' => '/internal/control/v1/health']);
    $statusLabel = __('ops.health.'.$display).($reason ? ' · '.$reason : '');
@endphp

<article class="site-card site-operation" aria-labelledby="agent-health-heading">
    <div class="site-card-head">
        <h3 id="agent-health-heading">{{ __('sites.agent.title') }} <button class="site-hint" type="button" aria-label="{{ $agentHint }}"><span aria-hidden="true">i</span><span role="tooltip">{{ $agentHint }}</span></button></h3>
        <div class="branch-version">
            <span class="status-chip status-{{ $display }}">{{ $statusLabel }}</span>
            @if ($canWriteAgentSecret && $site->hasAgentSecret())
                <form
                    method="POST"
                    action="{{ route('ops.sites.agent-secret', $site) }}"
                    data-ops-pending
                    data-confirm="{{ __('sites.agent.rotate_confirm', ['name' => $site->name]) }}"
                    data-confirm-title="{{ __('sites.agent.rotate_title') }}"
                    data-confirm-label="{{ __('sites.agent.rotate') }}"
                >
                    @csrf
                    <button type="submit" class="ops-icon-btn is-lg" aria-label="{{ __('sites.agent.rotate') }}" title="{{ __('sites.agent.rotate') }}" data-pending-label="{{ __('ops.actions.working') }}">
                        <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M13.2 8A5.2 5.2 0 1 1 11.6 4.2" stroke-linecap="round"/>
                            <path d="M13.2 3.2v3h-3" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </form>
            @endif
            @if ($canCheckHealth)
                <form method="POST" action="{{ route('ops.sites.health', $site) }}" data-ops-pending>
                    @csrf
                    <button type="submit" class="btn btn-ghost btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.agent.check') }}</button>
                </form>
            @endif
        </div>
    </div>

    <dl class="site-fact-list is-compact">
        <div>
            <dt>{{ __('sites.agent.last_check') }}</dt>
            <dd>{{ $site->last_health_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? __('ops.never') }}</dd>
        </div>
        <div>
            <dt>{{ __('sites.agent.version') }}</dt>
            <dd>{{ $version ?? __('ops.none') }}</dd>
        </div>
        <div>
            <dt>{{ __('sites.agent.theme') }}</dt>
            <dd>{{ $themeId ?? __('ops.none') }}</dd>
        </div>
        <div>
            <dt>{{ __('sites.agent.queue') }}</dt>
            <dd>
                @if ($queueOk === true)
                    {{ __('ops.health.ok') }}
                @elseif ($queueOk === false)
                    {{ __('ops.health.failing') }}
                @else
                    {{ __('ops.none') }}
                @endif
            </dd>
        </div>
        <div>
            <dt>{{ __('sites.agent.secret') }}</dt>
            <dd>{{ $site->hasAgentSecret() ? __('ops.health.configured') : __('ops.health.missing') }}</dd>
        </div>
    </dl>

    @if (! $site->hasAgentSecret())
        <p class="field-hint">{{ __('sites.agent.missing_hint') }}</p>
    @endif
</article>
