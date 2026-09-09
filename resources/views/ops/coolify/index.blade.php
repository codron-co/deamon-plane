@extends('layouts.ops')

@section('title', 'Coolify')

@section('actions')
    @if ($canWrite)
        <a class="btn btn-primary btn-sm" href="{{ route('ops.coolify.create') }}">Bağlantı ekle</a>
    @endif
@endsection

@section('content')
    <p class="page-lede">
        Birden fazla Coolify instance. Bağladıktan sonra sunucuları API’den çekin, aktif/pasif seçin.
        Site kurma bu listelerden dolar — UUID elle yazılmaz.
    </p>

    @if ($connections->isEmpty())
        <p class="muted">Henüz Coolify bağlantısı yok. Token Settings’te değil, burada saklanır (şifreli).</p>
    @else
        <div class="sites-table-wrap">
            <table class="ops-table">
                <thead>
                    <tr>
                        <th>Ad</th>
                        <th>URL</th>
                        <th>Durum</th>
                        <th>Sunucu</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($connections as $connection)
                        <tr data-href="{{ route('ops.coolify.show', $connection) }}" tabindex="0">
                            <td>
                                <a class="site-name" href="{{ route('ops.coolify.show', $connection) }}">{{ $connection->name }}</a>
                                @if ($connection->is_default)
                                    <span class="ops-chip">varsayılan</span>
                                @endif
                            </td>
                            <td class="muted">{{ $connection->base_url ?: '—' }}</td>
                            <td>
                                <span class="status-chip status-{{ $connection->is_enabled ? 'active' : 'error' }}">
                                    {{ $connection->is_enabled ? 'açık' : 'kapalı' }}
                                </span>
                            </td>
                            <td class="muted">{{ $connection->active_servers_count }}/{{ $connection->servers_count }} aktif</td>
                            <td class="ops-row-actions">
                                <a class="btn btn-ghost btn-sm" href="{{ route('ops.coolify.show', $connection) }}">Aç</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
