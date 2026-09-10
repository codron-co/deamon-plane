@php
    /** @var \App\Models\Site $site */
    /** @var \App\Services\Agent\SiteHealthEvaluator $agentHealth */
    $canCheckHealth = $canCheckHealth ?? false;
    $payload = $agentHealth->payload($site);
    $display = $agentHealth->displayStatus($site);
    $version = $site->reportedDeamonVersion();
    $themeId = is_string($payload['active_theme_id'] ?? null) ? $payload['active_theme_id'] : null;
    $queueOk = array_key_exists('queue_ok', $payload) ? $payload['queue_ok'] : null;
    $reason = is_string($payload['reason'] ?? null) ? $payload['reason'] : null;
@endphp

<section class="ops-panel" aria-labelledby="agent-health-heading">
    <h2 id="agent-health-heading">{{ __('sites.agent.title') }}</h2>
    <p>{{ __('sites.agent.lede', ['path' => '/internal/control/v1/health']) }}</p>

    <dl class="spec-list">
        <div>
            <dt>{{ __('sites.agent.status') }}</dt>
            <dd>
                <span class="status-chip status-{{ $display }}">{{ __('ops.health.'.$display) }}</span>
                @if ($reason)
                    <span class="muted">{{ $reason }}</span>
                @endif
            </dd>
        </div>
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

    @if ($canCheckHealth)
        <form method="POST" action="{{ route('ops.sites.health', $site) }}" class="ops-form" data-ops-pending>
            @csrf
            <div class="form-actions">
                <button type="submit" class="btn btn-ghost" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.agent.check') }}</button>
            </div>
        </form>
    @endif
</section>
