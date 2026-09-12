@extends('layouts.ops')

@section('title', __('themes.title'))

@section('content_class', 'ops-content-wide')

@section('actions')
    @if ($canWriteGit && $hasGithubApp)
        <form method="POST" action="{{ route('ops.themes.git.connect-another') }}" data-ops-native>
            @csrf
            <button type="submit" class="btn btn-secondary btn-sm">{{ __('themes.git.connect_another') }}</button>
        </form>
    @elseif ($canWriteGit && $appUrlIsPublic)
        <form method="POST" action="{{ route('ops.themes.git.connect') }}" data-ops-native>
            @csrf
            <button type="submit" class="btn btn-secondary btn-sm">{{ __('themes.git.connect') }}</button>
        </form>
    @endif
    @if ($canSync)
        <form method="POST" action="{{ route('ops.themes.sync') }}" data-ops-pending>
            @csrf
            <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('themes.sync') }}</button>
        </form>
    @endif
@endsection

@section('content')
    @include('ops.themes.partials.connections')

    <div class="site-section-heading">
        <div>
            <span class="site-section-kicker">{{ __('themes.kicker') }}</span>
            <h2>{{ __('themes.catalog') }} <button class="site-hint" type="button" aria-label="{{ __('themes.lede') }}"><span aria-hidden="true">i</span><span role="tooltip">{{ __('themes.lede') }}</span></button></h2>
        </div>
    </div>

    <div data-ops-list>
        <form method="GET" action="{{ route('ops.themes') }}" class="ops-list-toolbar" data-ops-list-toolbar>
            <label class="ops-search">
                <span class="visually-hidden">{{ __('themes.search') }}</span>
                <input type="search" name="q" value="{{ $search }}" placeholder="{{ __('themes.search_placeholder') }}" autocomplete="off">
            </label>
            <select name="visibility" class="field-input ops-filter" data-ops-list-filter aria-label="{{ __('themes.filter_visibility') }}">
                <option value="">{{ __('themes.all_visibilities') }}</option>
                @foreach ($visibilities as $option)
                    <option value="{{ $option->value }}" @selected($visibility === $option->value)>{{ $option->label() }}</option>
                @endforeach
            </select>
            {{-- Rendered even when idle so the async toolbar can reveal it without a round trip. --}}
            <a class="btn btn-ghost btn-sm" href="{{ route('ops.themes') }}" data-ops-list-clear{{ $filtersActive ? '' : ' hidden' }}>{{ __('ops.actions.clear') }}</a>
        </form>

        <div class="ops-list-region" data-ops-list-region>
            @include('ops.themes._region')
        </div>
    </div>
@endsection
