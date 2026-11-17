@extends('layouts.ops')

@section('title', __('security.site.title', ['site' => $site->name]))

@section('content_class', 'ops-content-wide')

@section('content')
    @include('ops.security._tabs')

    @php
        $reporting = $detail['reporting'];
        $stored = $detail['stored'];
        $health = $detail['health_block'];
        $moduleLabel = match ($reporting['module_enabled']) {
            true => __('security.site.module_on'),
            false => __('security.site.module_off'),
            default => __('security.site.module_unknown'),
        };
    @endphp

    <h2 class="page-lede">{{ $site->name }}</h2>

    @if ($reporting['supported'] === false)
        <div class="ops-alert ops-alert-warning" role="status">{{ __('security.site.unsupported') }}</div>
    @endif

    <x-ops.metrics :label="__('security.site.title', ['site' => $site->name])" :columns="4">
        <x-ops.metric :label="__('security.site.events_24h')" :value="number_format($stored['events_24h'])" :href="route('ops.security.events', ['site' => $site->id])" />
        <x-ops.metric :label="__('security.site.critical_24h')" :value="number_format($stored['critical_24h'])" :tone="$stored['critical_24h'] > 0 ? 'danger' : 'success'" :href="route('ops.security.events', ['site' => $site->id, 'severity' => 'critical'])" />
        <x-ops.metric :label="__('security.site.events_7d')" :value="number_format($stored['events_7d'])" :href="route('ops.security.events', ['site' => $site->id, 'period' => '7d'])" />
        <x-ops.metric :label="__('security.site.blocks_active')" :value="$health['blocks_active'] ?? ($detail['site_counters']['blocks_active'] ?? 0)" />
    </x-ops.metrics>

    <section class="ops-panel" aria-labelledby="security-site-reporting">
        <h2 id="security-site-reporting">{{ __('security.site.reporting') }}</h2>
        <dl class="site-fact-list">
            <div><dt>{{ __('security.site.module') }}</dt><dd>{{ $moduleLabel }}</dd></div>
            <div><dt>{{ __('security.site.last_poll') }}</dt><dd><x-ops.freshness :at="$reporting['last_polled_at'] ? \Illuminate\Support\Carbon::parse($reporting['last_polled_at']) : null" :mark-stale="false" /></dd></div>
            <div><dt>{{ __('security.site.last_success') }}</dt><dd><x-ops.freshness :at="$reporting['last_success_at'] ? \Illuminate\Support\Carbon::parse($reporting['last_success_at']) : null" :mark-stale="false" /></dd></div>
            @if ($reporting['consecutive_failures'] > 0)
                <div><dt>{{ __('security.site.failures') }}</dt><dd>{{ $reporting['consecutive_failures'] }}</dd></div>
                <div><dt>{{ __('security.site.last_error') }}</dt><dd>{{ $reporting['last_error'] }}</dd></div>
            @endif
            @if (is_array($health))
                <div><dt>{{ __('security.site.health_block') }}</dt><dd>{{ __('security.site.critical_24h') }}: {{ $health['critical_24h'] }}, {{ __('security.site.blocks_active') }}: {{ $health['blocks_active'] }}</dd></div>
            @endif
        </dl>
        <p><a href="{{ route('ops.sites.show', $site) }}">{{ __('security.site.open_site') }}</a></p>
    </section>

    <section class="ops-panel" aria-labelledby="security-site-recent">
        <h2 id="security-site-recent">{{ __('security.site.recent') }}</h2>
        @if ($recent->isEmpty())
            <p class="muted">{{ __('security.events.empty_hint') }}</p>
        @else
            <table class="ops-table">
                <thead>
                    <tr>
                        <th scope="col">{{ __('security.cols.severity') }}</th>
                        <th scope="col">{{ __('security.cols.rule') }}</th>
                        <th scope="col">{{ __('security.cols.ip') }}</th>
                        <th scope="col">{{ __('security.cols.path') }}</th>
                        <th scope="col">{{ __('security.cols.when') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recent as $event)
                        <tr>
                            <td><span class="security-sev is-{{ $event->severityKey() }}" title="{{ __('security.severity.'.$event->severityKey()) }}"><span class="security-sev-dot" aria-hidden="true"></span><span class="visually-hidden">{{ __('security.severity.'.$event->severityKey()) }}</span></span></td>
                            <td>{{ $event->rule }}</td>
                            <td><code>{{ $event->ip }}</code></td>
                            <td><code>{{ $event->path ?? '-' }}</code></td>
                            <td><x-ops.freshness :at="$event->occurred_at" :mark-stale="false" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endsection
