@extends('layouts.ops')

@section('title', $account->name)

@section('breadcrumbs')
    <a href="{{ route('ops.cloudflare.index') }}">{{ __('cloudflare.title') }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ $account->name }}</span>
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.cloudflare.index') }}">{{ __('cloudflare.back') }}</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.cloudflare.defaults') }}">{{ __('cloudflare.defaults.nav') }}</a>
@endsection

@section('content')
    <p class="page-lede">{{ __('cloudflare.show.lede') }}</p>

    <section class="ops-panel" aria-labelledby="cf-permissions-heading">
        <h2 id="cf-permissions-heading">{{ __('cloudflare.permissions') }}</h2>
        <p>{{ __('cloudflare.permissions_lede') }}</p>
        <ol class="ops-checklist">
            <li>{{ __('cloudflare.permissions_items.resource') }}</li>
            <li>{{ __('cloudflare.permissions_items.dns') }}</li>
            <li>{{ __('cloudflare.permissions_items.zone') }}</li>
        </ol>
        <p class="field-hint">{{ __('cloudflare.permissions_items.template') }}</p>
    </section>

    <section class="settings-panel" aria-labelledby="cf-connection-heading">
        <h2 id="cf-connection-heading">{{ __('cloudflare.connection') }}</h2>
        <form method="POST" action="{{ route('ops.cloudflare.update', $account) }}" class="ops-form settings-form">
            @csrf
            @method('PUT')
            @include('ops.cloudflare._account-fields', ['account' => $account, 'canWrite' => $canWrite, 'requireToken' => false])
            @if ($canWrite)
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">{{ __('cloudflare.save') }}</button>
                </div>
            @else
                <p class="field-hint">{{ __('cloudflare.readonly') }}</p>
            @endif
        </form>
        @if ($canWrite)
            <div class="form-actions">
                <form method="POST" action="{{ route('ops.cloudflare.test', $account) }}" data-ops-pending>
                    @csrf
                    <button type="submit" class="btn btn-secondary" data-pending-label="{{ __('ops.actions.working') }}">{{ __('cloudflare.test') }}</button>
                </form>
                @unless ($account->is_default)
                    <form method="POST" action="{{ route('ops.cloudflare.default', $account) }}">
                        @csrf
                        <button type="submit" class="btn btn-ghost">{{ __('cloudflare.make_default') }}</button>
                    </form>
                @endunless
            </div>
        @endif
    </section>

    <section class="ops-panel" aria-labelledby="cf-probe-heading">
        <h2 id="cf-probe-heading">{{ __('cloudflare.probe.title') }}</h2>
        @php
            $probe = is_array($account->last_probe_payload) ? $account->last_probe_payload : [];
            $missing = is_array($probe['missing'] ?? null) ? $probe['missing'] : [];
        @endphp
        @if ($account->last_probe_at)
            <p><time datetime="{{ $account->last_probe_at->toIso8601String() }}">{{ $account->last_probe_at->toDateTimeString() }}</time></p>
            @if (($probe['dns_unverified'] ?? false) === true)
                <p class="ops-alert ops-alert-warning" role="status">{{ __('cloudflare.probe.unverified_dns') }}</p>
            @elseif ($missing !== [])
                <p class="ops-alert ops-alert-warning" role="status">{{ __('cloudflare.probe.partial', ['missing' => implode(', ', $missing)]) }}</p>
            @else
                <p>{{ __('cloudflare.probe.ok') }}</p>
            @endif
        @else
            <p class="field-hint">{{ __('cloudflare.probe.never') }}</p>
        @endif
    </section>

    <section class="ops-panel" aria-labelledby="cf-zones-heading">
        <h2 id="cf-zones-heading">{{ __('cloudflare.zones.title') }}</h2>
        <p>{{ __('cloudflare.zones.lede') }}</p>
        @if ($zonesError)
            <p class="ops-alert" role="alert">{{ $zonesError }}</p>
        @endif
        @if ($canWrite && $account->hasCredentials())
            <form method="POST" action="{{ route('ops.cloudflare.zones.store', $account) }}" class="ops-form ops-dns-add-domain" data-ops-pending>
                @csrf
                <div class="field">
                    <label class="field-label" for="cf-zone-name">{{ __('cloudflare.zones.new_domain') }}</label>
                    <input id="cf-zone-name" class="field-input" type="text" name="name" value="{{ old('name') }}" required maxlength="255" spellcheck="false" autocomplete="off" placeholder="example.com">
                    @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label class="field-check">
                        <input type="hidden" name="apply_defaults" value="0">
                        <input type="checkbox" name="apply_defaults" value="1" @checked(old('apply_defaults', true))>
                        <span>{{ __('cloudflare.zones.apply_defaults') }}</span>
                    </label>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" data-pending-label="{{ __('ops.actions.working') }}">{{ __('cloudflare.zones.add') }}</button>
                </div>
            </form>
        @endif
        @if ($zones === [])
            <p class="muted">{{ __('cloudflare.zones.empty') }}</p>
        @else
            <div class="sites-table-wrap">
                <table class="ops-table">
                    <thead>
                        <tr>
                            <th>{{ __('cloudflare.zones.domain') }}</th>
                            <th>{{ __('cloudflare.zones.status') }}</th>
                            <th>{{ __('cloudflare.zones.ns') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($zones as $zone)
                            @php
                                $zoneId = (string) ($zone['id'] ?? '');
                                $zoneName = (string) ($zone['name'] ?? $zoneId);
                                $href = $zoneId !== '' ? route('ops.cloudflare.zones.show', ['account' => $account, 'zone' => $zoneId]) : null;
                                $ns = is_array($zone['name_servers'] ?? null) ? implode(', ', $zone['name_servers']) : '';
                            @endphp
                            <tr @if ($href) data-href="{{ $href }}" tabindex="0" @endif>
                                <td>
                                    @if ($href)
                                        <a class="site-name" href="{{ $href }}">{{ $zoneName }}</a>
                                    @else
                                        <span class="site-name">{{ $zoneName }}</span>
                                    @endif
                                </td>
                                <td class="muted">{{ $zone['status'] ?? __('ops.unknown') }}</td>
                                <td class="muted">{{ $ns !== '' ? $ns : __('ops.none') }}</td>
                                <td class="ops-row-actions">
                                    @if ($href)
                                        <a class="btn btn-ghost btn-sm" href="{{ $href }}">{{ __('ops.actions.open') }}</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($canWrite)
        <div class="danger-zone">
            <h2>{{ __('cloudflare.danger.title') }}</h2>
            <p>{{ __('cloudflare.danger.lede') }}</p>
            <form method="POST" action="{{ route('ops.cloudflare.destroy', $account) }}" data-confirm="{{ __('cloudflare.danger.confirm', ['name' => $account->name]) }}" data-confirm-title="{{ __('cloudflare.danger.confirm_title') }}" data-confirm-label="{{ __('cloudflare.danger.label') }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">{{ __('cloudflare.danger.button') }}</button>
            </form>
        </div>
    @endif
@endsection
