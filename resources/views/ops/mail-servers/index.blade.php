@extends('layouts.ops')

@section('title', __('mail.title'))

@section('actions')
    @if ($canWrite)
        <a class="btn btn-primary btn-sm" href="{{ route('ops.mail-servers.create') }}">{{ __('mail.add') }}</a>
    @endif
@endsection

@section('content')
    <p class="page-lede">{{ __('mail.lede') }}</p>

    @if ($servers->isEmpty())
        <div class="empty-panel">
            <h2>{{ __('mail.empty') }}</h2>
            <p>{{ __('mail.empty_hint') }}</p>
        </div>
    @else
        <div class="sites-table-wrap">
            <table class="ops-table">
                <thead>
                    <tr>
                        <th>{{ __('mail.columns.name') }}</th>
                        <th>{{ __('mail.columns.provider') }}</th>
                        <th>{{ __('mail.columns.domain') }}</th>
                        <th>{{ __('mail.columns.status') }}</th>
                        <th>{{ __('mail.columns.probe') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($servers as $server)
                        <tr data-href="{{ route('ops.mail-servers.show', $server) }}" tabindex="0">
                            <td>
                                <a class="site-name" href="{{ route('ops.mail-servers.show', $server) }}">{{ $server->name }}</a>
                            </td>
                            <td>{{ $server->provider?->label() }}</td>
                            <td class="muted">{{ $server->mail_domain ?: __('ops.none') }}</td>
                            <td>
                                <span class="status-chip status-{{ $server->is_enabled ? 'active' : 'error' }}">
                                    {{ $server->is_enabled ? __('ops.enabled') : __('ops.disabled') }}
                                </span>
                            </td>
                            <td class="muted">
                                @if ($server->last_probe_at)
                                    <time datetime="{{ $server->last_probe_at->toIso8601String() }}">{{ $server->last_probe_at->toDateTimeString() }}</time>
                                @else
                                    {{ __('ops.never') }}
                                @endif
                            </td>
                            <td class="ops-row-actions">
                                <a class="btn btn-ghost btn-sm" href="{{ route('ops.mail-servers.show', $server) }}">{{ __('ops.actions.open') }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
