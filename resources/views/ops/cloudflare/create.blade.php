@extends('layouts.ops')

@section('title', __('cloudflare.create.title'))

@section('breadcrumbs')
    <a href="{{ route('ops.cloudflare.index') }}">{{ __('cloudflare.title') }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ __('cloudflare.create.title') }}</span>
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.cloudflare.index') }}">{{ __('ops.actions.back') }}</a>
@endsection

@section('content')
    <div class="site-section-heading">
        <div>
            <span class="site-section-kicker">{{ __('cloudflare.title') }}</span>
            <h2>{{ __('cloudflare.create.title') }} @include('ops.cloudflare._hint', ['text' => __('cloudflare.create.lede')])</h2>
        </div>
    </div>

    <section class="ops-panel" aria-labelledby="cf-permissions-heading">
        <h2 id="cf-permissions-heading">{{ __('cloudflare.permissions') }} @include('ops.cloudflare._hint', ['text' => __('cloudflare.permissions_lede')])</h2>
        <ol class="ops-checklist">
            <li>{{ __('cloudflare.permissions_items.resource') }}</li>
            <li>{{ __('cloudflare.permissions_items.dns') }}</li>
            <li>{{ __('cloudflare.permissions_items.zone') }}</li>
        </ol>
        <p class="ops-alert ops-alert-warning" role="status">{{ __('cloudflare.permissions_items.template') }}</p>
        <p class="ops-alert ops-alert-warning" role="status">{{ __('cloudflare.not_required') }}</p>
    </section>

    <form method="POST" action="{{ route('ops.cloudflare.store') }}" class="ops-form ops-form-stack">
        @csrf
        @include('ops.cloudflare._account-fields', ['account' => $account, 'canWrite' => true, 'requireToken' => true])
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">{{ __('cloudflare.create.save') }}</button>
        </div>
    </form>
@endsection
