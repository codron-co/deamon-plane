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
            <div><dt>{{ __('mail.fields.mail_domain') }}</dt><dd>{{ $server->mail_domain ?: __('ops.none') }}</dd></div>
            <div><dt>{{ __('mail.fields.order') }}</dt><dd><code>{{ $server->hostinger_order_id ?: __('ops.none') }}</code></dd></div>
            <div><dt>{{ __('ops.enabled') }}</dt><dd>{{ $server->is_enabled ? __('ops.yes') : __('ops.no') }}</dd></div>
        </dl>
    </section>

    @if ($canWrite)
        <form method="POST" action="{{ route('ops.mail-servers.update', $server) }}" class="ops-form ops-form-stack">
            @csrf
            @method('PUT')
            @include('ops.mail-servers._fields', ['server' => $server, 'canWrite' => true, 'requireToken' => false])
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">{{ __('ops.actions.save_changes') }}</button>
            </div>
        </form>

        <form method="POST" action="{{ route('ops.mail-servers.test', $server) }}" data-ops-pending>
            @csrf
            <button type="submit" class="btn btn-secondary" data-pending-label="{{ __('ops.actions.working') }}">{{ __('mail.test') }}</button>
        </form>
    @endif

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
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($orders as $order)
                            <tr>
                                <td><code>{{ $order['id'] }}</code></td>
                                <td>{{ $order['domain'] ?: __('ops.none') }}</td>
                                <td>{{ $order['status'] ?: __('ops.none') }}</td>
                                <td class="ops-row-actions">
                                    @if ($canWrite)
                                        <form method="POST" action="{{ route('ops.mail-servers.order', $server) }}">
                                            @csrf
                                            <input type="hidden" name="hostinger_order_id" value="{{ $order['id'] }}">
                                            <button type="submit" class="btn btn-ghost btn-sm">{{ __('mail.orders.use') }}</button>
                                        </form>
                                    @endif
                                </td>
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
            <ul>
                @foreach ($sites as $site)
                    <li><a href="{{ route('ops.sites.show', $site) }}">{{ $site->name }}</a></li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($canWrite)
        <form method="POST" action="{{ route('ops.mail-servers.destroy', $server) }}" data-confirm="{{ __('mail.danger.confirm', ['name' => $server->name]) }}" data-confirm-title="{{ __('mail.danger.confirm_title') }}" data-confirm-label="{{ __('mail.danger.label') }}">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-danger">{{ __('ops.actions.delete') }}</button>
        </form>
    @endif
@endsection
