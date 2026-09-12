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
        <form method="GET" action="{{ route('ops.domains') }}" class="ops-list-toolbar" data-ops-list-toolbar>
            <label class="ops-search">
                <span class="visually-hidden">{{ __('domains.search') }}</span>
                <input type="search" name="q" value="{{ $search }}" placeholder="{{ __('domains.search') }}" autocomplete="off">
            </label>
            <label class="ops-check-inline">
                <input type="checkbox" name="unbound" value="1" @checked($unbound) data-ops-list-filter>
                <span>{{ __('domains.filter_unbound') }}</span>
            </label>
            {{-- Rendered even when idle so the async toolbar can reveal it without a round trip. --}}
            <a class="btn btn-ghost btn-sm" href="{{ route('ops.domains') }}" data-ops-list-clear{{ $search !== '' || $unbound ? '' : ' hidden' }}>{{ __('domains.clear') }}</a>
        </form>

        <div class="ops-list-region" data-ops-list-region>
            @include('ops.domains._region')
        </div>
    </div>
@endsection
