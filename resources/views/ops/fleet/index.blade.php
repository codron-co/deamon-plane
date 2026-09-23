@extends('layouts.ops')

@section('title', __('fleet.title'))

@section('content_class', 'ops-content-wide')

@section('content')
    <div class="sites-page" data-ops-list>
        @include('ops.dashboard.kpis')

        <div class="site-section-heading">
            <div>
                <span class="site-section-kicker">{{ __('fleet.kicker') }}</span>
                <h2>{{ __('fleet.heading') }} @include('ops.dashboard._hint', ['text' => __('fleet.lede')])</h2>
            </div>
        </div>

        <section class="plane-workspace">
            <div class="ops-list-toolbar-row plane-toolbar">
                <form method="GET" action="{{ route('ops.fleet') }}" id="fleet-list-filters" class="ops-list-toolbar" data-ops-list-toolbar>
                    <x-ops.search :label="__('fleet.search')" :value="$search" :placeholder="__('fleet.search_placeholder')" />
                </form>

                <x-ops.segment
                    name="kind"
                    form="fleet-list-filters"
                    :label="__('fleet.filter_kind')"
                    :all-label="__('fleet.all_kinds')"
                    :value="$kind"
                    :options="[
                        'unhealthy' => __('fleet.kinds.unhealthy'),
                        'failed' => __('fleet.kinds.failed'),
                        'agent' => __('fleet.kinds.agent'),
                        'dockerfile' => __('fleet.kinds.dockerfile'),
                    ]"
                />

                {{-- Rendered even when idle so the async toolbar can reveal it without a round trip. --}}
                <a class="btn btn-ghost btn-sm" href="{{ route('ops.fleet') }}" data-ops-list-clear{{ $filtersActive ? '' : ' hidden' }}>{{ __('ops.actions.clear') }}</a>
            </div>

            <div class="ops-list-region" data-ops-list-region>
                @include('ops.dashboard.attention')
            </div>
        </section>
    </div>
@endsection
