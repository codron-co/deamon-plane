@extends('layouts.ops')

@section('title', __('security.title'))

@section('content_class', 'ops-content-wide')

@section('content')
    <p class="page-lede">{{ __('security.lede') }}</p>

    @include('ops.security._tabs')

    @php
        $d24 = $overview['window_24h'];
        $d7 = $overview['window_7d'];
        $coverage = $overview['coverage'];
        $canWrite = (bool) auth()->user()?->canWriteOps();
        $sevCount = fn ($row, $key) => number_format($row[$key]);
    @endphp

    <x-ops.metrics :label="__('security.title')" :columns="5">
        <x-ops.metric :label="__('security.overview.window_24h').' · '.__('security.overview.events')" :value="$sevCount($d24, 'events')" :href="route('ops.security.events')" />
        <x-ops.metric :label="__('security.overview.window_24h').' · '.__('security.overview.critical')" :value="$sevCount($d24, 'critical')" :tone="$d24['critical'] > 0 ? 'danger' : 'success'" :href="route('ops.security.events', ['severity' => 'critical'])" />
        <x-ops.metric :label="__('security.overview.window_7d').' · '.__('security.overview.events')" :value="$sevCount($d7, 'events')" :href="route('ops.security.events', ['period' => '7d'])" />
        <x-ops.metric :label="__('security.overview.window_7d').' · '.__('security.overview.critical')" :value="$sevCount($d7, 'critical')" :tone="$d7['critical'] > 0 ? 'danger' : 'success'" :href="route('ops.security.events', ['severity' => 'critical', 'period' => '7d'])" />
        <x-ops.metric :label="__('security.overview.blocks')" :value="$overview['blocks_active']" :href="route('ops.security.blocks')" />
    </x-ops.metrics>

    @if (count($overview['cross_site']) > 0)
        <section class="ops-panel" aria-labelledby="security-cross-heading">
            <h2 id="security-cross-heading">{{ __('security.overview.cross_site') }} @include('ops.dashboard._hint', ['text' => __('security.overview.cross_site_hint')])</h2>
            <div class="security-cards">
                @foreach (array_slice($overview['cross_site'], 0, 4) as $card)
                    @include('ops.security._card', ['card' => $card, 'canWrite' => $canWrite])
                @endforeach
            </div>
            <p><a href="{{ route('ops.security.correlation') }}">{{ __('security.overview.see_cross_site') }}</a></p>
        </section>
    @endif

    <div class="security-columns">
        <section class="ops-panel" aria-labelledby="security-sites-heading">
            <h2 id="security-sites-heading">{{ __('security.overview.top_sites') }}</h2>
            @if (count($overview['top_sites']) === 0)
                <p class="muted">{{ __('security.overview.no_events') }}</p>
            @else
                <table class="ops-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('security.cols.site') }}</th>
                            <th scope="col">{{ __('security.cols.events') }}</th>
                            <th scope="col">{{ __('security.cols.critical') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($overview['top_sites'] as $row)
                            <tr data-href="{{ route('ops.security.site', $row['id']) }}" tabindex="0">
                                <td><a class="site-name" href="{{ route('ops.security.site', $row['id']) }}">{{ $row['name'] }}</a></td>
                                <td>{{ number_format($row['events']) }}</td>
                                <td>{{ number_format($row['critical']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <section class="ops-panel" aria-labelledby="security-ips-heading">
            <h2 id="security-ips-heading">{{ __('security.overview.top_ips') }}</h2>
            @if (count($overview['top_ips']) === 0)
                <p class="muted">{{ __('security.overview.no_events') }}</p>
            @else
                <table class="ops-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('security.cols.ip') }}</th>
                            <th scope="col">{{ __('security.cols.events') }}</th>
                            <th scope="col">{{ __('security.cols.sites') }}</th>
                            <th scope="col"><span class="visually-hidden">{{ __('security.blocks.block_row') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($overview['top_ips'] as $row)
                            <tr>
                                <td><a href="{{ route('ops.security.events', ['ip' => $row['ip'], 'period' => '7d']) }}"><code>{{ $row['ip'] }}</code></a></td>
                                <td>{{ number_format($row['events']) }}</td>
                                <td>{{ $row['sites'] }}</td>
                                <td class="ops-row-actions">
                                    @if ($row['blocked'])
                                        <span class="status-chip status-error">{{ __('security.events.blocked') }}</span>
                                    @elseif ($canWrite)
                                        @include('ops.security._block_form', ['ip' => $row['ip']])
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    </div>

    <section class="ops-panel" aria-labelledby="security-coverage-heading">
        <h2 id="security-coverage-heading">{{ __('security.overview.coverage') }}</h2>
        <p class="muted">{{ __('security.overview.coverage_line', [
            'monitored' => $coverage['monitored'],
            'total' => $coverage['total'],
            'module_off' => $coverage['module_off'],
            'unsupported' => $coverage['unsupported'],
            'failing' => $coverage['failing'],
            'pending' => $coverage['pending'],
        ]) }}</p>
    </section>
@endsection
