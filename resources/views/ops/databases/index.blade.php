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

    <section class="ops-panel" id="redis" aria-labelledby="redis-heading">
        <h2 id="redis-heading">{{ __('databases.redis.title') }}</h2>
        <p class="muted">{{ __('databases.redis.lede') }}</p>
        @if ($redisServers->isEmpty())
            <p class="muted">{{ __('databases.redis.empty') }}</p>
        @else
            <div class="sites-table-wrap">
                <table class="ops-table">
                    <thead>
                        <tr>
                            <th>{{ __('databases.columns.name') }}</th>
                            <th>{{ __('databases.columns.host') }}</th>
                            <th>{{ __('databases.redis.memory') }}</th>
                            <th>{{ __('databases.redis.clients') }}</th>
                            <th>{{ __('databases.redis.evicted') }}</th>
                            <th>{{ __('databases.columns.sites') }}</th>
                            <th>{{ __('databases.columns.probe') }}</th>
                            @if ($canWrite)
                                <th><span class="visually-hidden">{{ __('databases.probe') }}</span></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($redisServers as $redis)
                            {{-- Inline @php() only: a block @php here would swallow the inline one above. --}}
                            @php($probe = $redis->probe())
                            @php($usedMb = ((int) ($probe['used_memory'] ?? 0)) / 1048576)
                            @php($maxMb = ((int) ($probe['maxmemory'] ?? 0)) / 1048576)
                            @php($evicted = (int) ($probe['evicted_keys'] ?? 0))
                            <tr>
                                <td>
                                    {{ $redis->name }}
                                    @if ($redis->is_default)
                                        <span class="status-chip">{{ __('databases.default_badge') }}</span>
                                    @endif
                                </td>
                                <td><code>{{ $redis->host }}:{{ $redis->port }}</code></td>
                                <td class="muted">
                                    @if ($redis->last_probe_at)
                                        {{ number_format($usedMb, 1) }} / {{ $maxMb > 0 ? number_format($maxMb, 0).' MB' : __('databases.redis.no_limit') }}
                                    @else
                                        {{ __('ops.none') }}
                                    @endif
                                </td>
                                <td class="muted">{{ $redis->last_probe_at ? (int) ($probe['connected_clients'] ?? 0) : __('ops.none') }}</td>
                                <td class="{{ $evicted > 0 ? '' : 'muted' }}">{{ $redis->last_probe_at ? number_format($evicted) : __('ops.none') }}</td>
                                <td class="muted">{{ $redis->shared_sites_count }}</td>
                                <td class="muted">
                                    @if ($redis->last_probe_at)
                                        <time datetime="{{ $redis->last_probe_at->toIso8601String() }}">{{ $redis->last_probe_at->diffForHumans() }}</time>
                                    @else
                                        {{ __('databases.probe_never') }}
                                    @endif
                                </td>
                                @if ($canWrite)
                                    <td>
                                        <form method="POST" action="{{ route('ops.databases.redis.probe', $redis) }}" data-ops-pending>
                                            @csrf
                                            <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('databases.probe') }}</button>
                                        </form>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
