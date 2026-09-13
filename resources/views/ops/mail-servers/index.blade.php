@extends('layouts.ops')

@section('title', __('mail.title'))

@section('content_class', 'ops-content-wide')

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.platform-mail.edit') }}">{{ __('platform_mail.title') }}</a>
    @if ($canWrite)
        <a class="btn btn-primary btn-sm" href="{{ route('ops.mail-servers.create') }}">{{ __('mail.add') }}</a>
    @endif
@endsection

@section('content')
    <p class="page-lede">{{ __('mail.lede') }}</p>

    <div class="ops-mail-state-row">
        <span class="ops-mail-state-title">{{ __('platform_mail.title') }}</span>
        @include('ops.partials.platform-mail-chip', ['platformMailHint' => true])
        <a class="btn btn-ghost btn-sm" href="{{ route('ops.platform-mail.edit') }}">{{ __('ops.actions.open') }}</a>
    </div>

    <div data-ops-list>
        <form method="GET" action="{{ route('ops.mail-servers.index') }}" class="ops-list-toolbar" data-ops-list-toolbar>
            <label class="ops-search">
                <span class="visually-hidden">{{ __('mail.search') }}</span>
                <input type="search" name="q" value="{{ $search }}" placeholder="{{ __('mail.search_placeholder') }}" autocomplete="off">
            </label>
            <select name="status" class="field-input ops-filter" data-ops-list-filter aria-label="{{ __('mail.filter_status') }}">
                <option value="">{{ __('mail.all_statuses') }}</option>
                <option value="enabled" @selected($status === 'enabled')>{{ __('ops.enabled') }}</option>
                <option value="disabled" @selected($status === 'disabled')>{{ __('ops.disabled') }}</option>
            </select>
            {{-- Rendered even when idle so the async toolbar can reveal it without a round trip. --}}
            <a class="btn btn-ghost btn-sm" href="{{ route('ops.mail-servers.index') }}" data-ops-list-clear{{ $filtersActive ? '' : ' hidden' }}>{{ __('ops.actions.clear') }}</a>
        </form>

        <div class="ops-list-region" data-ops-list-region>
            @include('ops.mail-servers._region')
        </div>
    </div>
@endsection
