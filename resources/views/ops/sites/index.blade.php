@extends('layouts.ops')

@section('title', __('sites.title'))

@section('content_class', 'ops-content-wide')

@section('actions')
    @if ($canWrite ?? false)
        <form
            method="POST"
            action="{{ route('ops.sites.bulk.sync') }}"
            data-ops-pending
            data-confirm="{{ __('sites.detail.sync_confirm_all') }}"
            data-confirm-title="{{ __('sites.detail.sync_title') }}"
            data-confirm-label="{{ __('sites.detail.sync') }}"
            data-confirm-danger="false"
        >
            @csrf
            <input type="hidden" name="all" value="1">
            <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.detail.sync') }}</button>
        </form>
        <form
            method="POST"
            action="{{ route('ops.sites.live-sync') }}"
            data-ops-pending
            data-confirm="{{ __('sites.live.confirm') }}"
            data-confirm-title="{{ __('sites.live.confirm_title') }}"
            data-confirm-label="{{ __('sites.live.sync') }}"
            data-confirm-danger="false"
        >
            @csrf
            <input type="hidden" name="all" value="1">
            <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.live.sync') }}</button>
        </form>
    @endif
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
            <form method="POST" class="sites-bulk" id="sites-bulk-form" data-ops-bulk>
                @csrf
                <input type="hidden" name="confirmed" value="0">
                <input type="hidden" name="filter_q" value="{{ $search }}">
                <input type="hidden" name="filter_channel" value="{{ $channel }}">
                <input type="hidden" name="filter_status" value="{{ $status }}">
            <div class="sites-table-wrap">
                <table class="ops-table">
                    <thead>
                        <tr>
                            @can('create', \App\Models\Site::class)
                                <th class="ops-check-col">
                                    <label class="ops-check-all">
                                        <input type="checkbox" name="all" value="1" data-ops-bulk-all>
                                        <span class="visually-hidden">{{ __('site_ops.bulk.select_all') }}</span>
                                    </label>
                                </th>
                            @endcan
                            <th>{{ __('sites.columns.site') }}</th>
                            <th>{{ __('sites.columns.domain') }}</th>
                            <th>{{ __('sites.columns.repo_branch') }}</th>
                            <th>{{ __('sites.columns.status') }}</th>
                            <th>{{ __('sites.columns.live') }}</th>
                            <th>{{ __('sites.columns.theme') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sites as $site)
                            @php
                                $reportedVersion = $site->reportedDeamonVersion();
                                $markLetter = $site->identityMarkLetter();
                            @endphp
                            <tr data-href="{{ route('ops.sites.show', $site) }}" tabindex="0">
                                @can('create', \App\Models\Site::class)
                                    <td>
                                        <label>
                                            <span class="visually-hidden">{{ $site->name }}</span>
                                            <input type="checkbox" name="site_ids[]" value="{{ $site->id }}">
                                        </label>
                                    </td>
                                @endcan
                                <td>
                                    <div class="site-name-row">
                                        <div
                                            class="site-identity-mark is-compact"
                                            aria-hidden="true"
                                            @if (filled($site->primary_domain))
                                                data-favicon-host="{{ $site->primary_domain }}"
                                                data-favicon-fallback="{{ $markLetter }}"
                                                @if (filled($site->last_live_favicon_url))
                                                    data-favicon-src="{{ $site->last_live_favicon_url }}"
                                                @endif
                                            @endif
                                        >{{ $markLetter }}</div>
                                        <div>
                                            <div class="site-name-row">
                                                <a class="site-name" href="{{ route('ops.sites.show', $site) }}">{{ $site->name }}</a>
                                                @if ($site->hasDockerfileBuildPackWarning())
                                                    <span class="status-chip status-dockerfile">{{ __('ops.dockerfile_chip') }}</span>
                                                @endif
                                            </div>
                                            <div class="site-slug">{{ $site->slug }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td><code>{{ $site->primary_domain }}</code></td>
                                <td>
                                    <div class="branch-version" aria-label="{{ __('sites.columns.repo_branch') }}">
                                        <span class="branch-chip">{{ $site->channel->value }}</span>
                                        <span class="version-chip">{{ $reportedVersion ?: __('sites.version_unknown') }}</span>
                                    </div>
                                </td>
                                @php($failure = $site->lastFailureMessage())
                                <td><span class="status-chip status-{{ $site->status->value }}" @if (filled($failure)) title="{{ $failure }}" @endif>{{ $site->status->label() }}</span></td>
                                <td>
                                    <span class="status-chip status-{{ $site->liveHttpTone() }}" @if ($site->last_live_checked_at) title="{{ $site->last_live_checked_at->timezone(config('app.timezone'))->format('Y-m-d H:i') }}" @endif>{{ $site->liveHttpLabel() }}</span>
                                </td>
                                <td class="muted">{{ $site->reportedActiveThemeId() ?: __('ops.none') }}</td>
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
                @can('create', \App\Models\Site::class)
                    <div class="form-actions sites-bulk-actions" data-ops-bulk-actions role="group" aria-label="{{ __('sites.bulk') }}">
                        <label class="ops-bulk-channel">
                            <span class="visually-hidden">{{ __('sites.channel_switch.target') }}</span>
                            <select name="channel" class="field-input ops-filter" data-ops-bulk-channel>
                                @foreach ($channels as $channelOption)
                                    <option value="{{ $channelOption }}">{{ $channelOption }}</option>
                                @endforeach
                            </select>
                        </label>
                        <button
                            type="submit"
                            class="btn btn-secondary btn-sm"
                            formaction="{{ route('ops.sites.bulk.channel') }}"
                            data-confirm="{{ __('site_ops.bulk.confirm_branch', ['target' => 'main']) }}"
                            data-confirm-title="{{ __('site_ops.bulk.confirm_branch_title') }}"
                            data-confirm-label="{{ __('site_ops.bulk.change_branch') }}"
                            data-confirm-template="{{ __('site_ops.bulk.confirm_branch', ['target' => '__TARGET__']) }}"
                            data-confirm-danger="false"
                        >{{ __('site_ops.bulk.change_branch') }}</button>
                        @if ($hasDockerfileSites ?? false)
                            <button
                                type="submit"
                                class="btn btn-ghost btn-sm"
                                formaction="{{ route('ops.sites.bulk.compose') }}"
                                data-confirm="{{ __('site_ops.bulk.confirm_compose') }}"
                                data-confirm-title="{{ __('site_ops.bulk.confirm_title') }}"
                                data-confirm-label="{{ __('site_ops.bulk.compose') }}"
                            >{{ __('site_ops.bulk.compose') }}</button>
                        @endif
                        <button
                            type="submit"
                            class="btn btn-ghost btn-sm"
                            formaction="{{ route('ops.sites.bulk.auto-deploy') }}"
                            data-confirm="{{ __('site_ops.bulk.confirm_auto_toggle') }}"
                            data-confirm-title="{{ __('site_ops.bulk.confirm_auto_toggle_title') }}"
                            data-confirm-label="{{ __('site_ops.bulk.auto_toggle') }}"
                            data-confirm-danger="false"
                        >{{ __('site_ops.bulk.auto_toggle') }}</button>
                    </div>
                @endcan
            </form>

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

