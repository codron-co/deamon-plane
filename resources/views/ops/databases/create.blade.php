@extends('layouts.ops')

@section('title', __('databases.create.title'))

@section('breadcrumbs')
    <a href="{{ route('ops.databases.index') }}">{{ __('databases.title') }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ __('databases.create.title') }}</span>
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.databases.index') }}">{{ __('ops.actions.back') }}</a>
@endsection

@section('content')
    <p class="page-lede">{{ __('databases.lede') }}</p>

    <form method="POST" action="{{ route('ops.databases.store') }}" class="ops-form ops-form-stack">
        @csrf
        @include('ops.databases._fields', ['server' => $server])
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">{{ __('databases.create.save') }}</button>
        </div>
    </form>
@endsection
