@extends('layouts.ops')

@section('title', __('sites.create.title'))

@section('breadcrumbs')
    <a href="{{ route('ops.sites') }}">{{ __('sites.title') }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ __('sites.create.title') }}</span>
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.sites') }}">{{ __('sites.back_to_sites') }}</a>
@endsection

@section('content')
    <p class="page-lede">{{ __('sites.create.lede', ['compose' => '/docker-compose.coolify.yml']) }}</p>

    <form method="POST" action="{{ route('ops.sites.store') }}" class="ops-form ops-form-stack">
        @csrf
        @include('ops.sites._form', ['site' => $site, 'channels' => $channels, 'readonly' => false, 'channelLocked' => false])
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">{{ __('sites.create.save') }}</button>
            <a class="btn btn-ghost" href="{{ route('ops.sites') }}">{{ __('ops.actions.cancel') }}</a>
        </div>
    </form>
@endsection

@section('scripts')
    <script src="{{ asset('js/ops-coolify-form.js') }}" defer></script>
@endsection
