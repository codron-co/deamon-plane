@extends('layouts.ops')

@section('title', $package->name)

@section('breadcrumbs')
    <a href="{{ route('ops.mail-packages.index') }}">{{ __('comail.packages.title') }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ $package->name }}</span>
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.mail-packages.index') }}">{{ __('comail.packages.title') }}</a>
@endsection

@section('content')
    <p class="page-lede">{{ __('comail.packages.edit_lede', ['count' => $package->sites_count]) }}</p>

    <form method="POST" action="{{ route('ops.mail-packages.update', $package) }}" class="ops-form ops-form-stack" data-ops-pending>
        @csrf
        @method('PUT')
        @include('ops.mail-packages._fields', ['package' => $package, 'canWrite' => $canWrite])
        @if ($canWrite)
            <div class="form-actions">
                <button type="submit" class="btn btn-primary" data-pending-label="{{ __('ops.actions.working') }}">{{ __('ops.actions.save_changes') }}</button>
            </div>
        @endif
    </form>

    @if ($canDanger && ! $package->is_default)
        <form method="POST" action="{{ route('ops.mail-packages.destroy', $package) }}" data-confirm="{{ __('comail.packages.delete_confirm', ['name' => $package->name]) }}" data-confirm-title="{{ __('comail.packages.delete_title') }}" data-confirm-label="{{ __('ops.actions.delete') }}" data-confirm-danger="true">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-danger">{{ __('ops.actions.delete') }}</button>
        </form>
    @endif
@endsection
