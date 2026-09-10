@extends('layouts.ops')

@section('title', __('coolify.create.title'))

@section('breadcrumbs')
    <a href="{{ route('ops.coolify.index') }}">{{ __('coolify.title') }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ __('coolify.create.title') }}</span>
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.coolify.index') }}">{{ __('coolify.back_short') }}</a>
@endsection

@section('content')
    <p class="page-lede">{{ __('coolify.create.lede') }}</p>

    <form method="POST" action="{{ route('ops.coolify.store') }}" class="ops-form ops-form-stack">
        @csrf
        @include('ops.coolify._connection-fields', ['connection' => $connection, 'canWrite' => true, 'requireToken' => true])
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">{{ __('coolify.create.save') }}</button>
        </div>
    </form>
@endsection
