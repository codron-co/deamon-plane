@extends('layouts.ops')

@section('title', __('themes.git.manifest.title'))

@section('content_class', 'ops-content-wide')

@section('breadcrumbs')
    <a href="{{ route('ops.themes') }}">{{ __('themes.title') }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ __('themes.git.manifest.title') }}</span>
@endsection

@section('content')
    <section class="settings-panel" aria-labelledby="theme-git-manifest-heading">
        <h2 id="theme-git-manifest-heading">{{ __('themes.git.manifest.title') }}</h2>
        <p class="field-hint">{{ __('themes.git.manifest.hint') }}</p>
        <form method="POST" action="{{ $action }}" class="ops-form" data-theme-git-manifest>
            <input type="hidden" name="manifest" value="{{ $manifest }}">
            <input type="hidden" name="state" value="{{ $state }}">
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">{{ __('themes.git.manifest.continue') }}</button>
                <a class="btn btn-ghost" href="{{ route('ops.themes') }}">{{ __('ops.actions.cancel') }}</a>
            </div>
        </form>
    </section>
@endsection

@section('scripts')
    <script src="{{ asset('js/theme-git.js') }}?v={{ filemtime(public_path('js/theme-git.js')) }}" defer></script>
@endsection
