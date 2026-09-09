@extends('layouts.ops')

@section('title', 'Themes')

@section('content_class', 'ops-content-wide')

@section('actions')
    @if ($canSync)
        <form method="POST" action="{{ route('ops.themes.sync') }}">
            @csrf
            <button type="submit" class="btn btn-primary btn-sm">Sync catalog</button>
        </form>
    @endif
@endsection

@section('content')
    <p class="page-lede">
        GitHub org <code>{{ $org }}</code>, repos <code>{{ $prefix }}{theme_id}</code>.
        Catalog is git-only. Plane does not accept ZIP uploads — emergency ZIP stays on the CMS Super Admin import.
        Auto-update defaults <strong>off</strong>.
    </p>

    <form method="GET" action="{{ route('ops.themes') }}" class="ops-list-toolbar">
        <label class="ops-search">
            <span class="visually-hidden">Search themes</span>
            <input type="search" name="q" value="{{ $search }}" placeholder="Search id, name, repo" autocomplete="off">
        </label>
        <select name="visibility" class="field-input ops-filter" aria-label="Visibility">
            <option value="">All visibilities</option>
            @foreach ($visibilities as $option)
                <option value="{{ $option->value }}" @selected($visibility === $option->value)>{{ $option->label() }}</option>
            @endforeach
        </select>
        @if ($filtersActive)
            <a class="btn btn-ghost btn-sm" href="{{ route('ops.themes') }}">Clear</a>
        @endif
    </form>

    @if ($themes->isEmpty())
        <div class="empty-panel">
            <h2>No catalog themes yet</h2>
            <p>Save a GitHub App or PAT under Settings, then Sync catalog. Live GitHub credentials are optional for local tests — the suite uses Http::fake.</p>
        </div>
    @else
        <div class="sites-table-wrap">
            <table class="ops-table">
                <thead>
                    <tr>
                        <th>Theme</th>
                        <th>Repo</th>
                        <th>Visibility</th>
                        <th>Min Deamon</th>
                        <th>SHA</th>
                        <th>Installs</th>
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
                            <td class="muted">{{ $theme->minimum_deamon_version ?: '—' }}</td>
                            <td><code>{{ $theme->latest_sha ? substr($theme->latest_sha, 0, 7) : '—' }}</code></td>
                            <td>{{ $theme->installations_count }}</td>
                            <td class="ops-row-actions">
                                <a class="btn btn-ghost btn-sm" href="{{ route('ops.themes.show', $theme) }}">Open</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($themes->hasPages())
            <nav class="ops-pagination" aria-label="Themes pagination">
                @if ($themes->onFirstPage())
                    <span class="btn btn-ghost btn-sm" aria-disabled="true">Previous</span>
                @else
                    <a class="btn btn-ghost btn-sm" href="{{ $themes->previousPageUrl() }}">Previous</a>
                @endif
                <span class="ops-page-meta">{{ $themes->firstItem() }}–{{ $themes->lastItem() }} of {{ $themes->total() }}</span>
                @if ($themes->hasMorePages())
                    <a class="btn btn-ghost btn-sm" href="{{ $themes->nextPageUrl() }}">Next</a>
                @else
                    <span class="btn btn-ghost btn-sm" aria-disabled="true">Next</span>
                @endif
            </nav>
        @endif
    @endif
@endsection
