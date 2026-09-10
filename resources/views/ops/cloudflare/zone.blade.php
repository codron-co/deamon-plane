@extends('layouts.ops')

@section('title', $zone['name'] ?? __('cloudflare.zones.title'))

@section('breadcrumbs')
    <a href="{{ route('ops.cloudflare.index') }}">{{ __('cloudflare.title') }}</a>
    <span aria-hidden="true">/</span>
    <a href="{{ route('ops.cloudflare.show', $account) }}">{{ $account->name }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ $zone['name'] ?? $zone['id'] }}</span>
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.cloudflare.show', $account) }}">{{ __('cloudflare.back') }}</a>
    @if ($canWrite)
        <form method="POST" action="{{ route('ops.cloudflare.zones.apply', ['account' => $account, 'zone' => $zone['id']]) }}" data-ops-pending data-confirm="{{ __('cloudflare.zones.apply_confirm', ['domain' => $zone['name'] ?? $zone['id']]) }}" data-confirm-title="{{ __('cloudflare.zones.apply_title') }}" data-confirm-label="{{ __('cloudflare.zones.apply') }}" data-confirm-danger="false">
            @csrf
            <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('cloudflare.zones.apply') }}</button>
        </form>
    @endif
@endsection

@section('content')
    <p class="page-lede">{{ __('cloudflare.zone.lede') }}</p>

    <section class="ops-panel" aria-labelledby="cf-zone-facts-heading">
        <h2 id="cf-zone-facts-heading">{{ $zone['name'] ?? $zone['id'] }}</h2>
        <p>
            <span class="status-chip status-{{ ($zone['status'] ?? '') === 'active' ? 'active' : 'pending' }}">{{ $zone['status'] ?? __('ops.unknown') }}</span>
        </p>
        @if ($nameservers !== [])
            <p class="field-hint">{{ __('cloudflare.zones.ns_hint') }}</p>
            <ul class="ops-checklist">
                @foreach ($nameservers as $ns)
                    <li><code>{{ $ns }}</code></li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($recordsError)
        <p class="ops-alert" role="alert">{{ $recordsError }}</p>
    @endif

    @if ($canWrite)
        <section class="ops-panel" aria-labelledby="cf-dns-add-heading">
            <h2 id="cf-dns-add-heading">{{ __('cloudflare.dns.add') }}</h2>
            <form method="POST" action="{{ route('ops.cloudflare.zones.dns.store', ['account' => $account, 'zone' => $zone['id']]) }}" class="ops-form">
                @csrf
                @include('ops.cloudflare._dns-fields', ['prefix' => 'zone-add', 'canWrite' => true, 'useOld' => true])
                @error('priority') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">{{ __('cloudflare.dns.add') }}</button>
                </div>
            </form>
        </section>
    @endif

    @if ($records === [])
        <div class="empty-panel">
            <h2>{{ __('cloudflare.dns.empty') }}</h2>
        </div>
    @else
        <div class="sites-table-wrap">
            <table class="ops-table">
                <thead>
                    <tr>
                        <th>{{ __('cloudflare.dns.type') }}</th>
                        <th>{{ __('cloudflare.dns.name') }}</th>
                        <th>{{ __('cloudflare.dns.content') }}</th>
                        <th>{{ __('cloudflare.dns.ttl') }}</th>
                        <th>{{ __('cloudflare.dns.priority') }}</th>
                        @if ($canWrite)<th></th>@endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($records as $row)
                        <tr>
                            @if ($canWrite)
                                <td colspan="6">
                                    <div class="ops-dns-row">
                                        <form method="POST" action="{{ route('ops.cloudflare.zones.dns.update', ['account' => $account, 'zone' => $zone['id'], 'record' => $row['id']]) }}">
                                            @csrf
                                            @method('PUT')
                                            @include('ops.cloudflare._dns-fields', ['prefix' => 'zone-'.$row['id'], 'canWrite' => true, 'useOld' => false, 'type' => $row['type'], 'name' => $row['name'], 'content' => $row['content'], 'ttl' => $row['ttl'], 'priority' => $row['priority']])
                                            <div class="ops-row-actions">
                                                <button type="submit" class="btn btn-secondary btn-sm">{{ __('ops.actions.save') }}</button>
                                            </div>
                                        </form>
                                        <form method="POST" action="{{ route('ops.cloudflare.zones.dns.destroy', ['account' => $account, 'zone' => $zone['id'], 'record' => $row['id']]) }}" data-confirm="{{ __('cloudflare.dns.delete_confirm', ['name' => $row['name'], 'type' => $row['type']]) }}" data-confirm-title="{{ __('cloudflare.dns.delete_title') }}" data-confirm-label="{{ __('ops.actions.delete') }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-ghost btn-sm">{{ __('ops.actions.delete') }}</button>
                                        </form>
                                    </div>
                                </td>
                            @else
                                <td>{{ $row['type'] }}</td>
                                <td>{{ $row['name'] }}</td>
                                <td class="muted">{{ $row['content'] }}</td>
                                <td class="muted">{{ (int) $row['ttl'] === 1 ? __('cloudflare.dns.ttl_auto') : $row['ttl'] }}</td>
                                <td class="muted">{{ $row['priority'] ?? __('ops.none') }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($canWrite)
        <div class="danger-zone">
            <h2>{{ __('cloudflare.zone.danger_title') }}</h2>
            <p>{{ __('cloudflare.zone.danger_lede') }}</p>
            <form method="POST" action="{{ route('ops.cloudflare.zones.destroy', ['account' => $account, 'zone' => $zone['id']]) }}" data-confirm="{{ __('cloudflare.zone.danger_confirm', ['domain' => $zone['name'] ?? $zone['id']]) }}" data-confirm-title="{{ __('cloudflare.zone.danger_title') }}" data-confirm-label="{{ __('cloudflare.zone.danger_label') }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">{{ __('cloudflare.zone.danger_button') }}</button>
            </form>
        </div>
    @endif
@endsection
