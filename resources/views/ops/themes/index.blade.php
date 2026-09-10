@extends('layouts.ops')

@section('title', __('themes.title'))

@section('content_class', 'ops-content-wide')

@section('actions')
    @if ($canSync)
        <form method="POST" action="{{ route('ops.themes.sync') }}" data-ops-pending>
            @csrf
            <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('themes.sync') }}</button>
        </form>
    @endif
@endsection

@section('content')
    <p class="page-lede">{{ __('themes.lede', ['org' => $org, 'prefix' => $prefix]) }}</p>

    <form method="GET" action="{{ route('ops.themes') }}" class="ops-list-toolbar">
        <label class="ops-search">
            <span class="visually-hidden">{{ __('themes.search') }}</span>
            <input type="search" name="q" value="{{ $search }}" placeholder="{{ __('themes.search_placeholder') }}" autocomplete="off">
        </label>
        <select name="visibility" class="field-input ops-filter" aria-label="{{ __('themes.filter_visibility') }}">
            <option value="">{{ __('themes.all_visibilities') }}</option>
            @foreach ($visibilities as $option)
                <option value="{{ $option->value }}" @selected($visibility === $option->value)>{{ $option->label() }}</option>
            @endforeach
        </select>
        @if ($filtersActive)
            <a class="btn btn-ghost btn-sm" href="{{ route('ops.themes') }}">{{ __('ops.actions.clear') }}</a>
        @endif
    </form>

    @if ($themes->isEmpty())
        <div class="empty-panel">
            <h2>{{ __('themes.empty.title') }}</h2>
            <p>{{ __('themes.empty.hint') }}</p>
        </div>
    @else
        <div class="sites-table-wrap">
            <table class="ops-table">
                <thead>
                    <tr>
                        <th>{{ __('themes.columns.theme') }}</th>
                        <th>{{ __('themes.columns.repo') }}</th>
                        <th>{{ __('themes.columns.visibility') }}</th>
                        <th>{{ __('themes.columns.min') }}</th>
                        <th>{{ __('themes.columns.sha') }}</th>
                        <th>{{ __('themes.columns.installs') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($themes as $theme)
                        <tr data-href="{{ route('ops.themes.show', $theme) }}" tabindex="0">
                            <td>
                                <a class="site-name" href="{{ route('ops.themes.show', $theme) }}">{{ $theme->displayName() }}</a>
                                <div class="site-slug">{{ $theme->theme_id }}</div>
                            </td>
                            <td><code>{{ $theme->repo_full_name }}</code></td>
                            <td><span class="status-chip">{{ $theme->visibility?->label() }}</span></td>
                            <td class="muted">{{ $theme->minimum_deamon_version ?: __('ops.none') }}</td>
                            <td><code>{{ $theme->latest_sha ? substr($theme->latest_sha, 0, 7) : __('ops.none') }}</code></td>
                            <td>{{ $theme->installations_count }}</td>
                            <td class="ops-row-actions">
                                <a class="btn btn-ghost btn-sm" href="{{ route('ops.themes.show', $theme) }}">{{ __('ops.actions.open') }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($themes->hasPages())
            <nav class="ops-pagination" aria-label="{{ __('themes.pagination') }}">
                @if ($themes->onFirstPage())
                    <span class="btn btn-ghost btn-sm" aria-disabled="true">{{ __('ops.actions.previous') }}</span>
                @else
                    <a class="btn btn-ghost btn-sm" href="{{ $themes->previousPageUrl() }}">{{ __('ops.actions.previous') }}</a>
                @endif
                <span class="ops-page-meta">{{ __('ops.pagination', ['from' => $themes->firstItem(), 'to' => $themes->lastItem(), 'total' => $themes->total()]) }}</span>
                @if ($themes->hasMorePages())
                    <a class="btn btn-ghost btn-sm" href="{{ $themes->nextPageUrl() }}">{{ __('ops.actions.next') }}</a>
                @else
                    <span class="btn btn-ghost btn-sm" aria-disabled="true">{{ __('ops.actions.next') }}</span>
                @endif
            </nav>
        @endif
    @endif
@endsection
