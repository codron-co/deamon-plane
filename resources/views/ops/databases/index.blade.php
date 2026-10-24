@extends('layouts.ops')

@section('title', __('databases.title'))

@section('actions')
    @if ($canDanger)
        <a class="btn btn-primary btn-sm" href="{{ route('ops.databases.create') }}">{{ __('databases.add') }}</a>
    @endif
@endsection

@section('content')
    <p class="page-lede">{{ __('databases.lede') }}</p>

    @if ($servers->isEmpty())
        <div class="empty-panel">
            <h2>{{ __('databases.empty') }}</h2>
            <p>{{ __('databases.empty_hint') }}</p>
            @if ($canDanger)
                <div class="empty-panel-actions">
                    <a class="btn btn-primary" href="{{ route('ops.databases.create') }}">{{ __('databases.add') }}</a>
                </div>
            @endif
        </div>
    @else
        <div class="sites-table-wrap">
            <table class="ops-table">
                <thead>
                    <tr>
                        <th>{{ __('databases.columns.name') }}</th>
                        <th>{{ __('databases.columns.host') }}</th>
                        <th>{{ __('databases.columns.sites') }}</th>
                        <th>{{ __('databases.columns.size') }}</th>
                        <th>{{ __('databases.columns.probe') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($servers as $server)
                        @php($size = collect($server->probedDatabases())->sum('size_mb'))
                        <tr data-href="{{ route('ops.databases.show', $server) }}" tabindex="0">
                            <td>
                                <a class="site-name" href="{{ route('ops.databases.show', $server) }}">{{ $server->name }}</a>
                                @if ($server->is_default)
                                    <span class="status-chip">{{ __('databases.default_badge') }}</span>
                                @endif
                            </td>
                            <td><code>{{ $server->host }}:{{ $server->port }}</code></td>
                            <td class="muted">{{ $server->sites_count }}</td>
                            <td class="muted">{{ $server->last_probe_at ? number_format($size, 1).' MB' : __('ops.none') }}</td>
                            <td class="muted">
                                @if ($server->last_probe_at)
                                    <time datetime="{{ $server->last_probe_at->toIso8601String() }}">{{ $server->last_probe_at->diffForHumans() }}</time>
                                @else
                                    {{ __('databases.probe_never') }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
