@extends('layouts.ops')

@section('title', __('coolify.title'))

@section('content_class', 'ops-content-wide')

@section('actions')
    @if ($canWrite)
        <a class="btn btn-primary btn-sm" href="{{ route('ops.coolify.create') }}">{{ __('coolify.add') }}</a>
    @endif
@endsection

@section('content')
    <p class="page-lede">{{ __('coolify.lede') }}</p>

    @if ($connections->isEmpty())
        <div class="empty-panel">
            <h2>{{ __('coolify.empty') }}</h2>
        </div>
    @else
        <div class="sites-table-wrap">
            <table class="ops-table">
                <thead>
                    <tr>
                        <th>{{ __('coolify.columns.name') }}</th>
                        <th>{{ __('coolify.columns.url') }}</th>
                        <th>{{ __('coolify.columns.status') }}</th>
                        <th>{{ __('coolify.columns.servers') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($connections as $connection)
                        <tr data-href="{{ route('ops.coolify.show', $connection) }}" tabindex="0">
                            <td>
                                <a class="site-name" href="{{ route('ops.coolify.show', $connection) }}">{{ $connection->name }}</a>
                                @if ($connection->is_default)
                                    <span class="ops-chip">{{ __('ops.default') }}</span>
                                @endif
                            </td>
                            <td class="muted">{{ $connection->base_url ?: __('ops.none') }}</td>
                            <td>
                                <span class="status-chip status-{{ $connection->is_enabled ? 'active' : 'error' }}">
                                    {{ $connection->is_enabled ? __('coolify.enabled') : __('coolify.disabled') }}
                                </span>
                            </td>
                            <td class="muted">{{ __('coolify.servers_active', ['active' => $connection->active_servers_count, 'total' => $connection->servers_count]) }}</td>
                            <td class="ops-row-actions">
                                <a class="btn btn-ghost btn-sm" href="{{ route('ops.coolify.show', $connection) }}">{{ __('ops.actions.open') }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
