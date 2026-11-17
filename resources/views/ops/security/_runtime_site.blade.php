@php
    /** @var array<string, mixed> $runtime */
    $red = collect($runtime['risks'])->contains(fn ($risk) => $risk['severity'] >= 2);
    $t = $runtime['telemetry'];
    $minutes = fn (?int $seconds): string => $seconds === null ? __('security.runtime.unknown') : __('security.runtime.site.minutes', ['count' => intdiv($seconds, 60)]);
    $eol = $runtime['php_eol'];
    $scans = $runtime['scans'];
@endphp
<section class="ops-panel" aria-labelledby="security-site-runtime">
    <h2 id="security-site-runtime">
        {{ __('security.runtime.title') }}
        @if ($red)
            <span class="status-chip status-error" data-security-runtime-badge="red">{{ __('security.runtime.badge_red') }}</span>
        @elseif ($runtime['known'])
            <span class="status-chip status-ok" data-security-runtime-badge="ok">{{ __('security.runtime.badge_ok') }}</span>
        @endif
    </h2>

    @if (! $runtime['known'])
        <p class="muted">{{ __('security.runtime.unknown') }}</p>
    @else
        @if ($runtime['exempt'])
            <p class="muted">{{ __('security.runtime.site.exempt') }}</p>
        @endif
        <dl class="site-fact-list">
            <div>
                <dt>{{ __('security.runtime.site.settings') }}</dt>
                <dd>{{ $runtime['insecure_settings'] }}@if ($runtime['insecure_keys'] !== []) <span class="muted">({{ implode(', ', $runtime['insecure_keys']) }})</span>@endif</dd>
            </div>
            <div>
                <dt>{{ __('security.runtime.php_eol') }}</dt>
                <dd>
                    @if (is_array($eol) && $eol['version'])
                        {{ __('security.runtime.site.eol', ['version' => $eol['version'], 'eol' => $eol['eol'] ?? '-', 'days' => $eol['days_left'] ?? '-']) }}
                    @else
                        <span class="muted">{{ __('security.runtime.site.eol_unknown') }}</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt>{{ __('security.runtime.site.integrity') }}</dt>
                <dd>{{ $runtime['integrity']['status'] ?? __('security.runtime.unknown') }}@if (($runtime['integrity']['open'] ?? 0) > 0) ({{ $runtime['integrity']['open'] }})@endif</dd>
            </div>
            <div>
                <dt>{{ __('security.runtime.site.ingest_age') }}</dt>
                <dd>{{ $minutes($t['ingest_age_seconds'] ?? null) }}</dd>
            </div>
            <div>
                <dt>{{ __('security.runtime.site.dropped') }}</dt>
                <dd>{{ is_array($t) ? $t['dropped_24h'] : __('security.runtime.unknown') }}</dd>
            </div>
            <div>
                <dt>{{ __('security.runtime.site.outbox') }}</dt>
                <dd>{{ $minutes($t['outbox_lag_seconds'] ?? null) }}</dd>
            </div>
            <div>
                <dt>{{ __('security.runtime.site.advisories') }}</dt>
                <dd>
                    @if (is_array($scans))
                        {{ __('security.runtime.advisories_line', ['critical' => $scans['critical_production'], 'high' => $scans['high_production']]) }}
                        <span class="muted">· {{ __('security.runtime.site.pending_review') }}: {{ $scans['pending_review'] }}@if ($scans['last_scan_at']) · {{ __('security.runtime.site.last_scan') }}: {{ $scans['last_scan_at'] }}@endif</span>
                    @else
                        <span class="muted">{{ __('security.runtime.unknown') }}</span>
                    @endif
                </dd>
            </div>
        </dl>

        @if ($runtime['ini_drift'] !== [])
            <h3>{{ __('security.runtime.site.ini') }}</h3>
            <table class="ops-table">
                <tbody>
                    @foreach ($runtime['ini_drift'] as $drift)
                        <tr>
                            <td><code>{{ $drift['key'] }}</code></td>
                            <td class="muted">{{ __('security.runtime.site.expected') }}: <code>{{ $drift['expected'] ?? '-' }}</code></td>
                            <td class="muted">{{ __('security.runtime.site.actual') }}: <code>{{ $drift['actual'] ?? '-' }}</code></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif
</section>
