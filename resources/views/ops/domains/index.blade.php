@extends('layouts.ops')

@section('title', __('domains.title'))

@section('content_class', 'ops-content-wide')

@section('actions')
    @if ($canWrite ?? false)
        <details class="ops-action-menu" data-ops-action-menu>
            <summary class="btn btn-primary btn-sm">{{ __('domains.new') }}</summary>
            <div class="ops-action-popover ops-action-popover-form" role="menu">
                <form method="POST" action="{{ route('ops.domains.store') }}" class="ops-form" data-ops-pending>
                    @csrf
                    <div class="field">
                        <label class="field-label" for="fleet_domain">{{ __('domains.form.domain') }}</label>
                        <input id="fleet_domain" class="field-input" type="text" name="domain" required maxlength="255" autocomplete="off">
                    </div>
                    <div class="field">
                        <label class="field-label" for="fleet_domain_site">{{ __('domains.form.site') }}</label>
                        <select id="fleet_domain_site" class="field-input" name="site_id" required>
                            <option value="">{{ __('domains.form.site_placeholder') }}</option>
                            @foreach ($sites as $siteOption)
                                <option value="{{ $siteOption->id }}">{{ $siteOption->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('domains.new') }}</button>
                </form>
            </div>
        </details>
    @endif
@endsection

@section('content')
    <div class="sites-page" data-ops-list>
        @if (($summary ?? null) !== null)
            <x-ops.metrics :label="__('domains.summary.label')" :columns="4">
                <x-ops.metric
                    :label="__('domains.summary.total')"
                    :value="$summary['total']"
                    :href="route('ops.domains')"
                    icon="M8 14A6 6 0 1 0 8 2a6 6 0 0 0 0 12ZM2 8h12M8 2c1.7 1.8 2.5 3.8 2.5 6S9.7 12.2 8 14c-1.7-1.8-2.5-3.8-2.5-6S6.3 3.8 8 2Z"
                    data-ops-list-view="summary-total"
                />
                <x-ops.metric
                    :label="__('domains.summary.bound')"
                    :value="$summary['bound']"
                    tone="success"
                    icon="M6.5 9.5 9.5 6.5M7 4.5l1-1a2.5 2.5 0 0 1 3.5 3.5l-1 1M9 11.5l-1 1a2.5 2.5 0 0 1-3.5-3.5l1-1"
                    :detail="$summary['total'] > 0 ? __('sites.summary.of_total', ['count' => $summary['bound'], 'total' => $summary['total']]) : null"
                    data-domains-summary="bound"
                />
                <x-ops.metric
                    :label="__('domains.summary.unbound')"
                    :value="$summary['unbound']"
                    :tone="$summary['unbound'] > 0 ? 'warning' : 'success'"
                    :href="route('ops.domains', ['unbound' => 1])"
                    icon="M8 5v3.5M8 11h.01M6.9 2.6 1.8 11.5A1.3 1.3 0 0 0 2.9 13.5h10.2a1.3 1.3 0 0 0 1.1-2L9.1 2.6a1.3 1.3 0 0 0-2.2 0Z"
                    :detail="$summary['total'] > 0 ? __('sites.summary.of_total', ['count' => $summary['unbound'], 'total' => $summary['total']]) : null"
                    data-ops-list-view="summary-unbound"
                    data-domains-summary="unbound"
                />
                <x-ops.metric
                    :label="__('domains.summary.temporary')"
                    :value="$summary['temporary']"
                    icon="M8 4.5V8l2.5 1.5M8 14A6 6 0 1 0 8 2a6 6 0 0 0 0 12Z"
                    data-domains-summary="temporary"
                />
            </x-ops.metrics>
        @endif

        <section class="plane-workspace">
            <div class="ops-list-toolbar-row plane-toolbar">
                <form method="GET" action="{{ route('ops.domains') }}" id="domains-list-filters" class="ops-list-toolbar" data-ops-list-toolbar>
                    <x-ops.search :label="__('domains.search')" :value="$search" />
                </form>

                <x-ops.segment
                    name="unbound"
                    form="domains-list-filters"
                    :label="__('domains.filter_label')"
                    :value="$unbound ? '1' : ''"
                    :options="['1' => ['label' => __('domains.filter_unbound'), 'count' => $summary['unbound'] ?? null]]"
                />

                {{-- Rendered even when idle so the async toolbar can reveal it without a round trip. --}}
                <a class="btn btn-ghost btn-sm" href="{{ route('ops.domains') }}" data-ops-list-clear{{ $search !== '' || $unbound ? '' : ' hidden' }}>{{ __('domains.clear') }}</a>
            </div>

            <div class="ops-list-region" data-ops-list-region>
                @include('ops.domains._region')
            </div>
        </section>
    </div>
@endsection
