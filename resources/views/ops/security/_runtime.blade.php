@php
    /** @var array<string, mixed> $runtime */
@endphp
<section class="ops-panel" aria-labelledby="security-runtime-heading">
    <h2 id="security-runtime-heading">
        {{ __('security.runtime.title') }}
        @if ($runtime['red'])
            <span class="status-chip status-error" data-security-runtime-badge="red">{{ __('security.runtime.badge_red') }}</span>
        @elseif ($runtime['sites_reporting'] > 0)
            <span class="status-chip status-ok" data-security-runtime-badge="ok">{{ __('security.runtime.badge_ok') }}</span>
        @endif
        @include('ops.dashboard._hint', ['text' => __('security.runtime.lede')])
    </h2>

    <x-ops.metrics :label="__('security.runtime.title')" :columns="5">
        <x-ops.metric :label="__('security.runtime.insecure')" :value="$runtime['insecure_settings']" :tone="$runtime['insecure_settings'] > 0 ? 'danger' : 'success'" :detail="__('security.runtime.insecure_sites', ['count' => $runtime['sites_insecure']])" />
        <x-ops.metric :label="__('security.runtime.ini_drift')" :value="$runtime['sites_ini_drift']" :tone="$runtime['sites_ini_drift'] > 0 ? 'warning' : 'success'" :detail="__('security.runtime.insecure_sites', ['count' => $runtime['sites_ini_drift']])" />
        <x-ops.metric :label="__('security.runtime.php_eol')" :value="$runtime['sites_php_eol']" :tone="$runtime['sites_php_eol'] > 0 ? 'warning' : 'success'" :detail="__('security.runtime.insecure_sites', ['count' => $runtime['sites_php_eol']])" />
        <x-ops.metric :label="__('security.runtime.telemetry')" :value="$runtime['sites_telemetry_gap']" :tone="$runtime['sites_telemetry_gap'] > 0 ? 'warning' : 'success'" :detail="__('security.runtime.insecure_sites', ['count' => $runtime['sites_telemetry_gap']])" />
        <x-ops.metric :label="__('security.runtime.advisories')" :value="$runtime['critical_production'] + $runtime['high_production']" :tone="$runtime['critical_production'] > 0 ? 'danger' : ($runtime['high_production'] > 0 ? 'warning' : 'success')" :detail="__('security.runtime.advisories_line', ['critical' => $runtime['critical_production'], 'high' => $runtime['high_production']])" />
    </x-ops.metrics>

    @if (count($runtime['top_risks']) > 0)
        <h3>{{ __('security.runtime.top_risks') }}</h3>
        <table class="ops-table">
            <tbody>
                @foreach ($runtime['top_risks'] as $risk)
                    <tr data-href="{{ route('ops.security.site', $risk['site_id']) }}" tabindex="0">
                        <td>
                            <span class="security-sev is-{{ $risk['severity'] >= 2 ? 'critical' : 'warning' }}" title="{{ __('security.severity.'.($risk['severity'] >= 2 ? 'critical' : 'warning')) }}">
                                <span class="security-sev-dot" aria-hidden="true"></span>
                                <span class="visually-hidden">{{ __('security.severity.'.($risk['severity'] >= 2 ? 'critical' : 'warning')) }}</span>
                            </span>
                        </td>
                        <td><a class="site-name" href="{{ route('ops.security.site', $risk['site_id']) }}">{{ $risk['site'] }}</a></td>
                        <td>{{ __('security.runtime.risk.'.$risk['kind'], ['value' => $risk['value']]) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>
