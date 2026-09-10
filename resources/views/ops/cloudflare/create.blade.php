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
    <p class="page-lede">{{ __('cloudflare.create.lede') }}</p>

    <section class="ops-panel" aria-labelledby="cf-permissions-heading">
        <h2 id="cf-permissions-heading">{{ __('cloudflare.permissions') }}</h2>
        <p>{{ __('cloudflare.permissions_lede') }}</p>
        <ol class="ops-checklist">
            <li>{{ __('cloudflare.permissions_items.resource') }}</li>
            <li>{{ __('cloudflare.permissions_items.dns') }}</li>
            <li>{{ __('cloudflare.permissions_items.zone') }}</li>
        </ol>
        <p class="field-hint">{{ __('cloudflare.permissions_items.template') }}</p>
        <p class="field-hint">{{ __('cloudflare.not_required') }}</p>
    </section>

    <form method="POST" action="{{ route('ops.cloudflare.store') }}" class="ops-form ops-form-stack">
        @csrf
        @include('ops.cloudflare._account-fields', ['account' => $account, 'canWrite' => true, 'requireToken' => true])
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">{{ __('cloudflare.create.save') }}</button>
        </div>
    </form>
@endsection
