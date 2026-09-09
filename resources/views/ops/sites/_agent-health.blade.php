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
    <h2 id="agent-health-heading">Agent health</h2>
    <p>Signed poll of <code>/internal/control/v1/health</code>. Secrets stay encrypted and are never shown.</p>

    <dl class="spec-list">
        <div>
            <dt>Status</dt>
            <dd>
                <span class="status-chip status-{{ $display }}">{{ $display }}</span>
                @if ($reason)
                    <span class="muted">{{ $reason }}</span>
                @endif
            </dd>
        </div>
        <div>
            <dt>Last check</dt>
            <dd>{{ $site->last_health_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? 'Never' }}</dd>
        </div>
        <div>
            <dt>Deamon version</dt>
            <dd>{{ $version ?? '—' }}</dd>
        </div>
        <div>
            <dt>Active theme</dt>
            <dd>{{ $themeId ?? '—' }}</dd>
        </div>
        <div>
            <dt>Queue</dt>
            <dd>
                @if ($queueOk === true)
                    ok
                @elseif ($queueOk === false)
                    failing
                @else
                    —
                @endif
            </dd>
        </div>
        <div>
            <dt>Agent secret</dt>
            <dd>{{ $site->hasAgentSecret() ? 'configured' : 'missing' }}</dd>
        </div>
    </dl>

    @if (! $site->hasAgentSecret())
        <p class="field-hint">
            Import does not invent secrets. Inject <code>CONTROL_PLANE_AGENT_SECRET</code> on the CMS Coolify app,
            then store the same value encrypted on this site (Dalga 5 hardens automation).
        </p>
    @endif

    @if ($canCheckHealth)
        <form method="POST" action="{{ route('ops.sites.health', $site) }}" class="ops-form">
            @csrf
            <div class="form-actions">
                <button type="submit" class="btn btn-ghost">Check health</button>
            </div>
        </form>
    @endif
</section>
