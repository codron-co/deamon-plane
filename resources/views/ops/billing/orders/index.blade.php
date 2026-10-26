@extends('layouts.ops')

@section('title', __('billing.orders.title'))

@section('content_class', 'ops-content-wide')

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.billing.settings.edit') }}">{{ __('billing.nav.settings') }}</a>
@endsection

@section('content')
    <p class="page-lede">{{ __('billing.orders.lede') }}</p>

    <form method="GET" action="{{ route('ops.billing.orders.index') }}" class="ops-list-toolbar">
        <select name="status" class="field-input ops-filter" aria-label="{{ __('billing.orders.filter_status') }}">
            <option value="">{{ __('billing.orders.all_statuses') }}</option>
            @foreach (\App\Models\MailOrder::STATUSES as $statusOption)
                <option value="{{ $statusOption }}" @selected($status === $statusOption)>{{ __('billing.statuses.'.$statusOption) }}</option>
            @endforeach
        </select>
        <select name="method" class="field-input ops-filter" aria-label="{{ __('billing.orders.filter_method') }}">
            <option value="">{{ __('billing.orders.all_methods') }}</option>
            @foreach (\App\Models\MailOrder::METHODS as $methodOption)
                <option value="{{ $methodOption }}" @selected($method === $methodOption)>{{ __('billing.methods.'.$methodOption) }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-secondary btn-sm">{{ __('billing.orders.filter') }}</button>
        @if ($filtersActive)
            <a class="btn btn-ghost btn-sm" href="{{ route('ops.billing.orders.index') }}">{{ __('ops.actions.clear_filters') }}</a>
        @endif
    </form>

    @if ($orders->isEmpty())
        <p class="muted" data-billing-orders-empty>{{ __('billing.orders.empty') }}</p>
    @else
        <div class="sites-table-wrap">
            <table class="ops-table" data-billing-orders>
                <thead>
                    <tr>
                        <th>{{ __('billing.orders.columns.reference') }}</th>
                        <th>{{ __('billing.orders.columns.site') }}</th>
                        <th>{{ __('billing.orders.columns.package') }}</th>
                        <th>{{ __('billing.orders.columns.period') }}</th>
                        <th>{{ __('billing.orders.columns.amount') }}</th>
                        <th>{{ __('billing.orders.columns.method') }}</th>
                        <th>{{ __('billing.orders.columns.status') }}</th>
                        <th>{{ __('billing.orders.columns.created') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($orders as $order)
                        <tr data-href="{{ route('ops.billing.orders.show', $order) }}" tabindex="0" data-billing-order="{{ $order->reference }}">
                            <td><a class="site-name" href="{{ route('ops.billing.orders.show', $order) }}"><code>{{ $order->reference }}</code></a></td>
                            <td>{{ $order->site?->name ?? __('ops.none') }}</td>
                            <td>{{ $order->mailPackage?->name }}</td>
                            <td>{{ __('billing.periods.'.$order->period) }}</td>
                            <td>{{ \App\Services\Billing\MailBilling::money($order->amount, $order->currency) }}</td>
                            <td>{{ __('billing.methods.'.$order->method) }}</td>
                            <td><span class="status-chip {{ $order->isPaid() ? 'status-active' : '' }}" data-billing-status="{{ $order->status }}">{{ __('billing.statuses.'.$order->status) }}</span></td>
                            <td class="muted">{{ $order->created_at?->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $orders->links() }}
    @endif
@endsection
