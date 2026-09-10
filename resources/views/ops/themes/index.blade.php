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
    <div class="site-section-heading">
        <div>
            <span class="site-section-kicker">{{ __('themes.kicker') }}</span>
            <h2>{{ __('themes.catalog') }} <button class="site-hint" type="button" aria-label="{{ __('themes.lede', ['org' => $org, 'prefix' => $prefix]) }}"><span aria-hidden="true">i</span><span role="tooltip">{{ __('themes.lede', ['org' => $org, 'prefix' => $prefix]) }}</span></button></h2>
        </div>
    </div>

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
        @if ($filtersActive)
            <a class="btn btn-ghost btn-sm" href="{{ route('ops.themes') }}">{{ __('ops.actions.clear') }}</a>
        @endif
    </form>

    @if ($themes->isEmpty() && ! $filtersActive)
        <div class="empty-panel">
            <h2>{{ __('themes.empty.title') }}</h2>
            <p>{{ __('themes.empty.hint') }}</p>
        </div>
    @elseif ($themes->isEmpty())
        <div class="empty-panel">
            <h2>{{ __('themes.empty.filtered_title') }}</h2>
            <p>{{ __('themes.empty.filtered_hint') }}</p>
            <a class="btn btn-ghost" href="{{ route('ops.themes') }}">{{ __('ops.actions.clear_filters') }}</a>
        </div>
    @else
        <div class="sites-table-wrap">
            <table class="ops-table">
                <thead>
                    <tr>
                        <th>{{ __('themes.columns.theme') }}</th>
                        <th>{{ __('themes.columns.theme_id') }}</th>
                        <th>{{ __('themes.columns.repo') }}</th>
                        <th>{{ __('themes.columns.default_ref') }}</th>
                        <th>{{ __('themes.columns.visibility') }}</th>
                        <th>{{ __('themes.columns.sync') }}</th>
                        <th>{{ __('themes.columns.installs') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($themes as $theme)
                        <tr data-href="{{ route('ops.themes.show', $theme) }}" tabindex="0">
                            <td>
                                <a class="site-name" href="{{ route('ops.themes.show', $theme) }}">{{ $theme->displayName() }}</a>
                            </td>
                            <td><code>{{ $theme->theme_id }}</code></td>
                            <td><code>{{ $theme->repo_full_name }}</code></td>
                            <td><span class="branch-chip">{{ $theme->default_ref ?: __('ops.none') }}</span></td>
                            <td><span class="status-chip">{{ $theme->visibility?->label() }}</span></td>
                            <td>
                                @if ($theme->last_synced_at)
                                    <span class="status-chip status-active" title="{{ $theme->last_synced_at->toDateTimeString() }}">{{ $theme->last_synced_at->diffForHumans() }}</span>
                                @else
                                    <span class="status-chip">{{ __('themes.sync_state.never') }}</span>
                                @endif
                            </td>
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
