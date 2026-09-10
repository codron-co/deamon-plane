@extends('layouts.ops')

@section('title', __('sites.title'))

@section('content_class', 'ops-content-wide')

@section('actions')
    @if ($canCreate)
        <a class="btn btn-primary btn-sm" href="{{ route('ops.sites.create') }}">{{ __('sites.new') }}</a>
    @endif
@endsection

@section('content')
    <div class="sites-page">
        <form method="GET" action="{{ route('ops.sites') }}" class="ops-list-toolbar" data-ops-list-toolbar>
            <label class="ops-search">
                <span class="visually-hidden">{{ __('sites.search') }}</span>
                <input type="search" name="q" value="{{ $search }}" placeholder="{{ __('sites.search_placeholder') }}" autocomplete="off">
            </label>
            <select name="channel" class="field-input ops-filter" data-ops-list-filter aria-label="{{ __('sites.filter_branch') }}">
                <option value="">{{ __('sites.all_branches') }}</option>
                @foreach ($channels as $channelOption)
                    <option value="{{ $channelOption }}" @selected($channel === $channelOption)>{{ $channelOption }}</option>
                @endforeach
            </select>
            <select name="status" class="field-input ops-filter" data-ops-list-filter aria-label="{{ __('sites.filter_status') }}">
                <option value="">{{ __('sites.all_statuses') }}</option>
                @foreach ($statuses as $statusOption)
                    <option value="{{ $statusOption }}" @selected($status === $statusOption)>{{ __('ops.site_status.'.$statusOption) }}</option>
                @endforeach
            </select>
            @if ($filtersActive)
                <a class="btn btn-ghost btn-sm" href="{{ route('ops.sites') }}">{{ __('ops.actions.clear') }}</a>
            @endif
        </form>

        @if ($sites->isEmpty() && ! $filtersActive)
            <div class="empty-panel">
                <h2>{{ __('sites.empty.title') }}</h2>
                <p>{{ __('sites.empty.hint') }}</p>
                @if ($canCreate)
                    <a class="btn btn-primary" href="{{ route('ops.sites.create') }}">{{ __('sites.new') }}</a>
                @endif
            </div>
        @elseif ($sites->isEmpty())
            <div class="empty-panel">
                <h2>{{ __('sites.empty.filtered_title') }}</h2>
                <p>{{ __('sites.empty.filtered_hint') }}</p>
                <a class="btn btn-ghost" href="{{ route('ops.sites') }}">{{ __('ops.actions.clear_filters') }}</a>
            </div>
        @else
            <div class="sites-table-wrap">
                <table class="ops-table">
                    <thead>
                        <tr>
                            <th>{{ __('sites.columns.site') }}</th>
                            <th>{{ __('sites.columns.domain') }}</th>
                            <th>{{ __('sites.columns.repo_branch') }}</th>
                            <th>{{ __('sites.columns.status') }}</th>
                            <th>{{ __('sites.columns.theme') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sites as $site)
                            @php($reportedVersion = $site->reportedDeamonVersion())
                            <tr data-href="{{ route('ops.sites.show', $site) }}" tabindex="0">
                                <td>
                                    <div class="site-name-row">
                                        <a class="site-name" href="{{ route('ops.sites.show', $site) }}">{{ $site->name }}</a>
                                        @if ($site->hasDockerfileBuildPackWarning())
                                            <span class="status-chip status-dockerfile">{{ __('ops.dockerfile_chip') }}</span>
                                        @endif
                                    </div>
                                    <div class="site-slug">{{ $site->slug }}</div>
                                </td>
                                <td><code>{{ $site->primary_domain }}</code></td>
                                <td>
                                    <div class="branch-version">
                                        <span class="branch-chip">{{ $site->channel->value }}</span>
                                        <span class="version-chip">{{ $reportedVersion ?: __('sites.version_unknown') }}</span>
                                    </div>
                                </td>
                                <td><span class="status-chip status-{{ $site->status->value }}">{{ $site->status->label() }}</span></td>
                                <td class="muted">{{ $site->activeThemeInstallation?->theme?->theme_id ?: __('ops.none') }}</td>
                                <td class="ops-row-actions">
                                    <a class="btn btn-ghost btn-sm" href="{{ route('ops.sites.show', $site) }}">{{ __('ops.actions.view') }}</a>
                                    @can('update', $site)
                                        <a class="btn btn-ghost btn-sm" href="{{ route('ops.sites.edit', $site) }}">{{ __('ops.actions.edit') }}</a>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($sites->hasPages())
                <nav class="ops-pagination" aria-label="{{ __('sites.pagination') }}">
                    @if ($sites->onFirstPage())
                        <span class="btn btn-ghost btn-sm" aria-disabled="true">{{ __('ops.actions.previous') }}</span>
                    @else
                        <a class="btn btn-ghost btn-sm" href="{{ $sites->previousPageUrl() }}">{{ __('ops.actions.previous') }}</a>
                    @endif
                    <span class="ops-page-meta">{{ __('ops.pagination', ['from' => $sites->firstItem(), 'to' => $sites->lastItem(), 'total' => $sites->total()]) }}</span>
                    @if ($sites->hasMorePages())
                        <a class="btn btn-ghost btn-sm" href="{{ $sites->nextPageUrl() }}">{{ __('ops.actions.next') }}</a>
                    @else
                        <span class="btn btn-ghost btn-sm" aria-disabled="true">{{ __('ops.actions.next') }}</span>
                    @endif
                </nav>
            @endif
        @endif
    </div>
@endsection

@section('scripts')
    <script src="{{ asset('js/ops-sites-list.js') }}" defer></script>
@endsection
