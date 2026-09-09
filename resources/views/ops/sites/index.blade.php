@extends('layouts.ops')

@section('title', 'Sites')

@section('content_class', 'ops-content-wide')

@section('actions')
    @if ($canCreate)
        <a class="btn btn-primary btn-sm" href="{{ route('ops.sites.create') }}">New site</a>
    @endif
@endsection

@section('content')
    <div class="sites-page">
        <form method="GET" action="{{ route('ops.sites') }}" class="ops-list-toolbar" data-ops-list-toolbar>
            <label class="ops-search">
                <span class="visually-hidden">Search sites</span>
                <input
                    type="search"
                    name="q"
                    value="{{ $search }}"
                    placeholder="Search name, slug, domain"
                    autocomplete="off"
                >
            </label>
            <select name="channel" class="field-input ops-filter" data-ops-list-filter aria-label="Channel">
                <option value="">All channels</option>
                @foreach ($channels as $channelOption)
                    <option value="{{ $channelOption }}" @selected($channel === $channelOption)>{{ $channelOption }}</option>
                @endforeach
            </select>
            <select name="status" class="field-input ops-filter" data-ops-list-filter aria-label="Status">
                <option value="">All statuses</option>
                @foreach ($statuses as $statusOption)
                    <option value="{{ $statusOption }}" @selected($status === $statusOption)>{{ $statusOption }}</option>
                @endforeach
            </select>
            @if ($filtersActive)
                <a class="btn btn-ghost btn-sm" href="{{ route('ops.sites') }}">Clear</a>
            @endif
        </form>

        @if ($sites->isEmpty() && ! $filtersActive)
            <div class="empty-panel">
                <h2>No sites yet</h2>
                <p>Create a draft with slug, domain, and channel. Provisioning against Coolify is Task 4 — this list is desired state only.</p>
                @if ($canCreate)
                    <a class="btn btn-primary" href="{{ route('ops.sites.create') }}">New site</a>
                @endif
            </div>
        @elseif ($sites->isEmpty())
            <div class="empty-panel">
                <h2>No matching sites</h2>
                <p>Nothing matches the current search or filters.</p>
                <a class="btn btn-ghost" href="{{ route('ops.sites') }}">Clear filters</a>
            </div>
        @else
            <div class="sites-table-wrap">
                <table class="ops-table">
                    <thead>
                        <tr>
                            <th>Site</th>
                            <th>Domain</th>
                            <th>Channel</th>
                            <th>Status</th>
                            <th>Theme</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sites as $site)
                            <tr>
                                <td>
                                    <div class="site-name-row">
                                        <a class="site-name" href="{{ route('ops.sites.edit', $site) }}">{{ $site->name }}</a>
                                        @if ($site->hasDockerfileBuildPackWarning())
                                            <span class="status-chip status-dockerfile">Dockerfile (eski pack)</span>
                                        @endif
                                    </div>
                                    <div class="site-slug">{{ $site->slug }}</div>
                                </td>
                                <td><code>{{ $site->primary_domain }}</code></td>
                                <td><span class="channel-chip">{{ $site->channel->value }}</span></td>
                                <td><span class="status-chip status-{{ $site->status->value }}">{{ $site->status->value }}</span></td>
                                <td class="muted">{{ $site->activeThemeInstallation?->theme?->theme_id ?: '—' }}</td>
                                <td class="ops-row-actions">
                                    <a class="btn btn-ghost btn-sm" href="{{ route('ops.sites.edit', $site) }}">{{ auth()->user()?->can('update', $site) ? 'Edit' : 'View' }}</a>
                                    @can('delete', $site)
                                        <form
                                            method="POST"
                                            action="{{ route('ops.sites.destroy', $site) }}"
                                            data-confirm="Archive {{ $site->name }}? This soft-deletes the Plane record. Coolify is not contacted."
                                            data-confirm-title="Delete site"
                                            data-confirm-label="Delete"
                                        >
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-ghost btn-sm btn-danger-text">Delete</button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($sites->hasPages())
                <nav class="ops-pagination" aria-label="Sites pagination">
                    @if ($sites->onFirstPage())
                        <span class="btn btn-ghost btn-sm" aria-disabled="true">Previous</span>
                    @else
                        <a class="btn btn-ghost btn-sm" href="{{ $sites->previousPageUrl() }}">Previous</a>
                    @endif
                    <span class="ops-page-meta">{{ $sites->firstItem() }}–{{ $sites->lastItem() }} of {{ $sites->total() }}</span>
                    @if ($sites->hasMorePages())
                        <a class="btn btn-ghost btn-sm" href="{{ $sites->nextPageUrl() }}">Next</a>
                    @else
                        <span class="btn btn-ghost btn-sm" aria-disabled="true">Next</span>
                    @endif
                </nav>
            @endif
        @endif
    </div>
@endsection

@section('scripts')
    <script src="{{ asset('js/ops-sites-list.js') }}" defer></script>
@endsection
