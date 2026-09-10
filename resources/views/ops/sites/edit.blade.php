@extends('layouts.ops')

@section('title', $site->name)

@section('breadcrumbs')
    <a href="{{ route('ops.sites') }}">{{ __('sites.title') }}</a>
    <span aria-hidden="true">/</span>
    <a href="{{ route('ops.sites.show', $site) }}">{{ $site->name }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ __('ops.actions.edit') }}</span>
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.sites.show', $site) }}">{{ __('sites.back_to_site') }}</a>
@endsection

@section('content')
    @if ($site->hasDockerfileBuildPackWarning())
        <p class="ops-alert ops-alert-warning" role="status">
            {{ __('sites.edit.dockerfile') }}
        </p>
    @endif

    <p class="page-lede">
        @if ($readonly)
            {{ __('ops.viewer_readonly') }}
        @else
            {{ __('sites.edit.lede_other', ['status' => $site->status?->label()]) }}
        @endif
    </p>

    <form method="POST" action="{{ route('ops.sites.update', $site) }}" class="ops-form ops-form-stack">
        @csrf
        @method('PUT')
        @include('ops.sites._form')
        @if (! $readonly)
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">{{ __('ops.actions.save_changes') }}</button>
                <a class="btn btn-ghost" href="{{ route('ops.sites.show', $site) }}">{{ __('ops.actions.cancel') }}</a>
            </div>
        @endif
    </form>
@endsection

@section('scripts')
    <script src="{{ asset('js/ops-coolify-form.js') }}" defer></script>
    <script src="{{ asset('js/sites-aliases.js') }}?v={{ filemtime(public_path('js/sites-aliases.js')) }}" defer></script>
@endsection
