@extends('layouts.ops')

@section('title', __('settings.deamon_git.manifest.title'))

@section('content_class', 'ops-content-wide')

@section('breadcrumbs')
    <a href="{{ route('ops.settings') }}#deamon-git-heading">{{ __('settings.title') }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ __('settings.deamon_git.manifest.title') }}</span>
@endsection

@section('content')
    <section class="settings-panel" aria-labelledby="deamon-git-manifest-heading">
        <h2 id="deamon-git-manifest-heading">{{ __('settings.deamon_git.manifest.title') }}</h2>
        <p class="field-hint">{{ __('settings.deamon_git.manifest.hint') }}</p>
        <form method="POST" action="{{ $action }}" class="ops-form" data-theme-git-manifest data-ops-native>
            <input type="hidden" name="manifest" value="{{ $manifest }}">
            <input type="hidden" name="state" value="{{ $state }}">
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">{{ __('settings.deamon_git.manifest.continue') }}</button>
                <a class="btn btn-ghost" href="{{ route('ops.settings') }}#deamon-git-heading">{{ __('ops.actions.cancel') }}</a>
            </div>
        </form>
    </section>
@endsection

@section('scripts')
    <script src="{{ asset('js/theme-git.js') }}?v={{ filemtime(public_path('js/theme-git.js')) }}" defer></script>
@endsection
