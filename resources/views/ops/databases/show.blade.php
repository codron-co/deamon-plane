@extends('layouts.ops')

@section('title', $server->name)

@section('breadcrumbs')
    <a href="{{ route('ops.databases.index') }}">{{ __('databases.title') }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ $server->name }}</span>
@endsection

@section('actions')
    @if ($canWrite)
        <form method="POST" action="{{ route('ops.databases.probe', $server) }}" data-ops-pending>
            @csrf
            <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('databases.probe') }}</button>
        </form>
    @endif
@endsection

@section('content')
    @php
        $probe = is_array($server->last_probe_payload) ? $server->last_probe_payload : [];
        $totalMb = collect($databases)->sum('size_mb');
        $uptime = (int) ($probe['uptime_seconds'] ?? 0);
    @endphp

    <section class="ops-panel">
        <dl class="site-fact-list">
            <div><dt>{{ __('databases.columns.host') }}</dt><dd><code>{{ $server->host }}:{{ $server->port }}</code></dd></div>
            <div><dt>{{ __('databases.server.version') }}</dt><dd>{{ $probe['version'] ?? __('ops.none') }}</dd></div>
            <div><dt>{{ __('databases.server.uptime') }}</dt><dd>{{ $uptime > 0 ? \Carbon\CarbonInterval::seconds($uptime)->cascade()->forHumans(['parts' => 2]) : __('ops.none') }}</dd></div>
            <div><dt>{{ __('databases.server.connections') }}</dt><dd>{{ $probe['connections'] ?? __('ops.none') }}</dd></div>
            <div><dt>{{ __('databases.server.total_size') }}</dt><dd>{{ number_format($totalMb, 1) }} MB</dd></div>
            <div><dt>{{ __('databases.columns.probe') }}</dt><dd>{{ $server->last_probe_at?->diffForHumans() ?? __('databases.probe_never') }}</dd></div>
        </dl>
    </section>

    <section class="ops-panel" aria-labelledby="db-list-heading">
        <h2 id="db-list-heading">{{ __('databases.server.databases') }}</h2>
        @if ($databases === [])
            <p class="muted">{{ __('databases.probe_never') }}</p>
        @else
            <div class="sites-table-wrap">
                <table class="ops-table">
                    <thead>
                        <tr>
                            <th>{{ __('databases.columns.database') }}</th>
                            <th>{{ __('databases.columns.site') }}</th>
                            <th>{{ __('databases.columns.tables') }}</th>
                            <th>{{ __('databases.columns.size') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($databases as $database)
                            <tr>
                                <td><code>{{ $database['name'] }}</code></td>
                                <td>
                                    @if ($database['site'])
                                        <a href="{{ route('ops.sites.database', $database['site']) }}">{{ $database['site']->name }}</a>
                                    @else
                                        <span class="muted">{{ __('databases.server.unassigned') }}</span>
                                    @endif
                                </td>
                                <td class="muted">{{ $database['tables'] }}</td>
                                <td class="muted">{{ number_format($database['size_mb'], 1) }} MB</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($canWrite)
        <section class="ops-panel" aria-labelledby="slow-heading" id="slow">
            <h2 id="slow-heading">{{ __('databases.slow.title') }}</h2>
            @if ($slowError !== null)
                <p class="muted">{{ __('databases.errors.probe_failed', ['message' => $slowError]) }}</p>
            @elseif ($slow !== null)
                <p class="muted">
                    @if (! $slow['enabled'])
                        {{ __('databases.slow.off') }}
                    @elseif (! $slow['to_table'])
                        {{ __('databases.slow.file_only') }}
                    @else
                        {{ __('databases.slow.on', ['seconds' => rtrim(rtrim(number_format($slow['threshold_seconds'], 2, '.', ''), '0'), '.')]) }}
                    @endif
                </p>

                @if ($databases !== [])
                    <form method="GET" action="{{ route('ops.databases.show', $server) }}#slow" class="ops-form">
                        <label for="slow_db">{{ __('databases.columns.database') }}</label>
                        <select id="slow_db" name="slow_db">
                            <option value="">{{ __('databases.slow.all') }}</option>
                            @foreach ($databases as $database)
                                <option value="{{ $database['name'] }}" @selected($slowSchema === $database['name'])>{{ $database['site']?->name ?? $database['name'] }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn btn-secondary btn-sm">{{ __('databases.slow.filter') }}</button>
                    </form>
                @endif

                @if ($slow['rows'] === [])
                    <p class="muted">{{ __('databases.slow.empty') }}</p>
                @else
                    <div class="sites-table-wrap">
                        <table class="ops-table">
                            <thead>
                                <tr>
                                    <th>{{ __('databases.slow.at') }}</th>
                                    <th>{{ __('databases.columns.site') }}</th>
                                    <th>{{ __('databases.slow.seconds') }}</th>
                                    <th>{{ __('databases.slow.rows_examined') }}</th>
                                    <th>{{ __('databases.slow.sql') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($slow['rows'] as $query)
                                    @php($querySite = $bySchema->get($query['database']))
                                    <tr>
                                        <td class="muted">{{ \Illuminate\Support\Carbon::parse($query['at'], 'UTC')->timezone(config('app.timezone'))->format('d.m.Y H:i:s') }}</td>
                                        <td>
                                            @if ($querySite)
                                                <a href="{{ route('ops.sites.database', $querySite) }}">{{ $querySite->name }}</a>
                                            @else
                                                <code>{{ $query['database'] !== '' ? $query['database'] : $query['user'] }}</code>
                                            @endif
                                        </td>
                                        <td>{{ __('databases.slow.duration', ['seconds' => number_format($query['seconds'], 2)]) }} @if ($query['lock_seconds'] >= 0.01)<span class="muted">({{ __('databases.slow.lock', ['seconds' => number_format($query['lock_seconds'], 2)]) }})</span>@endif</td>
                                        <td class="muted">{{ number_format($query['rows_examined']) }} → {{ number_format($query['rows_sent']) }}</td>
                                        <td>
                                            @if (mb_strlen($query['sql']) > 120)
                                                <details><summary><code>{{ mb_substr($query['sql'], 0, 120) }}…</code></summary><pre><code>{{ $query['sql'] }}</code></pre></details>
                                            @else
                                                <code>{{ $query['sql'] }}</code>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif
        </section>
    @endif

    @if ($canDanger)
        <details class="ops-panel">
            <summary>{{ __('ops.actions.edit') }}</summary>
            <form method="POST" action="{{ route('ops.databases.update', $server) }}" class="ops-form ops-form-stack">
                @csrf
                @method('PUT')
                @include('ops.databases._fields', ['server' => $server])
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">{{ __('ops.actions.save_changes') }}</button>
                </div>
            </form>
        </details>

        @if ($sites->isEmpty())
            <form method="POST" action="{{ route('ops.databases.destroy', $server) }}" data-confirm="{{ __('databases.danger.confirm', ['name' => $server->name]) }}" data-confirm-title="{{ __('databases.danger.confirm_title') }}" data-confirm-label="{{ __('databases.danger.label') }}" data-confirm-danger="true">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">{{ __('ops.actions.delete') }}</button>
            </form>
        @endif
    @endif
@endsection
