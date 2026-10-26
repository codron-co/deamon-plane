@extends('layouts.ops')

@section('title', __('comail.packages.create_title'))

@section('breadcrumbs')
    <a href="{{ route('ops.mail-packages.index') }}">{{ __('comail.packages.title') }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ __('comail.packages.create_title') }}</span>
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.mail-packages.index') }}">{{ __('ops.actions.back') }}</a>
@endsection

@section('content')
    <p class="page-lede">{{ __('comail.packages.lede') }}</p>

    <form method="POST" action="{{ route('ops.mail-packages.store') }}" class="ops-form ops-form-stack" data-ops-pending>
        @csrf
        @include('ops.mail-packages._fields', ['package' => $package, 'canWrite' => true])
        <div class="form-actions">
            <button type="submit" class="btn btn-primary" data-pending-label="{{ __('ops.actions.working') }}">{{ __('comail.packages.save') }}</button>
        </div>
    </form>
@endsection
