@extends('layouts.ops')

@section('title', $server->name)

@section('breadcrumbs')
    <a href="{{ route('ops.mail-servers.index') }}">{{ __('mail.title') }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ $server->name }}</span>
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.mail-servers.index') }}">{{ __('mail.title') }}</a>
@endsection

@section('content')
    <p class="page-lede">{{ __('mail.lede') }}</p>

    <section class="ops-panel">
        <dl class="site-fact-list">
            <div><dt>{{ __('mail.fields.provider') }}</dt><dd>{{ $server->provider?->label() }}</dd></div>
            <div><dt>{{ __('ops.enabled') }}</dt><dd>{{ $server->is_enabled ? __('ops.yes') : __('ops.no') }}</dd></div>
            @if ($isComail)
                <div><dt>{{ __('mail.fields.base_url') }}</dt><dd><code>{{ $server->comailBaseUrl() ?: __('ops.none') }}</code></dd></div>
                <div><dt>{{ __('mail.fields.mail_hostname') }}</dt><dd><code>{{ $server->comailHostname() ?: __('ops.none') }}</code></dd></div>
                <div><dt>{{ __('mail.comail.version') }}</dt><dd>{{ $server->last_probe_payload['version'] ?? __('ops.none') }}</dd></div>
                <div><dt>{{ __('mail.comail.active_label') }}</dt><dd>{{ $server->isComailReady() && \App\Models\MailServer::comail()?->is($server) ? __('ops.yes') : __('ops.no') }}</dd></div>
            @endif
        </dl>
    </section>

    @if ($canWrite)
        @if ($canDanger ?? false)
            <form method="POST" action="{{ route('ops.mail-servers.update', $server) }}" class="ops-form ops-form-stack">
                @csrf
                @method('PUT')
                @include('ops.mail-servers._fields', ['server' => $server, 'canWrite' => true, 'requireToken' => false])
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">{{ __('ops.actions.save_changes') }}</button>
                </div>
            </form>
        @else
            <p class="field-hint">{{ __('ops.super_admin_only') }}</p>
        @endif

        <form method="POST" action="{{ route('ops.mail-servers.test', $server) }}" data-ops-pending>
            @csrf
            <button type="submit" class="btn btn-secondary" data-pending-label="{{ __('ops.actions.working') }}">{{ __('mail.test') }}</button>
        </form>
    @endif

    @if ($isComail)
        <section class="ops-panel" aria-labelledby="comail-sites-heading">
            <h2 id="comail-sites-heading">{{ __('mail.comail.sites_title') }}</h2>
            <p>{{ __('mail.comail.sites_lede') }}</p>
            @if ($comailDomains->isEmpty())
                <p class="muted">{{ __('mail.comail.sites_empty') }}</p>
            @else
                <div class="sites-table-wrap">
                    <table class="ops-table">
                        <thead>
                            <tr>
                                <th>{{ __('mail.sites.site') }}</th>
                                <th>{{ __('mail.comail.domain') }}</th>
                                <th>{{ __('mail.columns.status') }}</th>
                                <th>{{ __('mail.comail.mx') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($comailDomains as $row)
                                <tr>
                                    <td>@if ($row->site)<a href="{{ route('ops.sites.show', $row->site) }}#site-comail">{{ $row->site->name }}</a>@endif</td>
                                    <td>{{ $row->domain }}</td>
                                    <td><span class="status-chip">{{ __('mail.comail.statuses.'.$row->status) }}</span></td>
                                    <td><span class="status-chip">{{ $row->mxActive() ? __('mail.comail.mx_active') : __('mail.comail.mx_waiting') }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @else
    <section class="ops-panel" aria-labelledby="mail-orders-heading">
        <h2 id="mail-orders-heading">{{ __('mail.orders.title') }}</h2>
        <p>{{ __('mail.orders.lede') }}</p>
        @php($orders = $server->probedOrders())
        @if ($orders === [])
            <p class="muted">{{ __('mail.orders.empty') }}</p>
        @else
            <div class="sites-table-wrap">
                <table class="ops-table">
                    <thead>
                        <tr>
                            <th>{{ __('mail.fields.order') }}</th>
                            <th>{{ __('mail.fields.mail_domain') }}</th>
                            <th>{{ __('mail.columns.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($orders as $order)
                            <tr>
                                <td><code>{{ $order['id'] }}</code></td>
                                <td>{{ $order['domain'] ?: __('ops.none') }}</td>
                                <td>{{ $order['status'] ?: __('ops.none') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="ops-panel" aria-labelledby="mail-sites-heading">
        <h2 id="mail-sites-heading">{{ __('mail.sites.title') }}</h2>
        @if ($sites->isEmpty())
            <p class="muted">{{ __('mail.sites.empty') }}</p>
        @else
            <div class="sites-table-wrap">
                <table class="ops-table">
                    <thead>
                        <tr>
                            <th>{{ __('mail.sites.site') }}</th>
                            <th>{{ __('mail.fields.mail_domain') }}</th>
                            <th>{{ __('mail.boxes.title') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sites as $site)
                            <tr>
                                <td><a href="{{ route('ops.sites.show', $site) }}">{{ $site->name }}</a></td>
                                <td>{{ $site->primary_domain }}</td>
                                <td>
                                    @if ($site->mailDomains() !== [])
                                        <span>{{ implode(', ', $site->mailDomains()) }}</span>
                                    @else
                                        <span class="muted">{{ __('mail.sites.unmatched') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @endif

    @if ($canDanger ?? false)
        <form method="POST" action="{{ route('ops.mail-servers.destroy', $server) }}" data-confirm="{{ __('mail.danger.confirm', ['name' => $server->name]) }}" data-confirm-title="{{ __('mail.danger.confirm_title') }}" data-confirm-label="{{ __('mail.danger.label') }}" data-confirm-danger="true">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-danger">{{ __('ops.actions.delete') }}</button>
        </form>
    @endif
@endsection
