@extends('layouts.ops')

@section('title', __('domains.title'))

@section('content_class', 'ops-content-wide')

@section('actions')
    @if ($canWrite ?? false)
        <details class="ops-action-menu" data-ops-action-menu>
            <summary class="btn btn-primary btn-sm">{{ __('domains.new') }}</summary>
            <div class="ops-action-popover ops-action-popover-form" role="menu">
                <form method="POST" action="{{ route('ops.domains.store') }}" class="ops-form" data-ops-pending>
                    @csrf
                    <div class="field">
                        <label class="field-label" for="fleet_domain">{{ __('domains.form.domain') }}</label>
                        <input id="fleet_domain" class="field-input" type="text" name="domain" required maxlength="255" autocomplete="off">
                    </div>
                    <div class="field">
                        <label class="field-label" for="fleet_domain_site">{{ __('domains.form.site') }}</label>
                        <select id="fleet_domain_site" class="field-input" name="site_id" required>
                            <option value="">{{ __('domains.form.site_placeholder') }}</option>
                            @foreach ($sites as $siteOption)
                                <option value="{{ $siteOption->id }}">{{ $siteOption->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('domains.new') }}</button>
                </form>
            </div>
        </details>
    @endif
@endsection

@section('content')
    <div class="sites-page">
        <form method="GET" action="{{ route('ops.domains') }}" class="ops-list-toolbar" data-ops-list-toolbar>
            <label class="ops-search">
                <span class="visually-hidden">{{ __('domains.search') }}</span>
                <input type="search" name="q" value="{{ $search }}" placeholder="{{ __('domains.search') }}" autocomplete="off">
            </label>
            <label class="ops-check-inline">
                <input type="checkbox" name="unbound" value="1" @checked($unbound) data-ops-list-filter>
                <span>{{ __('domains.filter_unbound') }}</span>
            </label>
            @if ($search !== '' || $unbound)
                <a class="btn btn-ghost btn-sm" href="{{ route('ops.domains') }}">{{ __('domains.clear') }}</a>
            @endif
        </form>

        @if ($domains->isEmpty())
            <div class="empty-panel">
                <h2>{{ $search !== '' || $unbound ? __('domains.empty.filtered') : __('domains.empty.title') }}</h2>
                <p>{{ __('domains.empty.hint') }}</p>
            </div>
        @else
            <div class="sites-table-wrap">
                <table class="ops-table">
                    <thead>
                        <tr>
                            <th>{{ __('domains.columns.domain') }}</th>
                            <th>{{ __('domains.columns.site') }}</th>
                            <th>{{ __('domains.columns.role') }}</th>
                            <th>{{ __('domains.columns.coolify') }}</th>
                            <th class="ops-actions-col">{{ __('domains.columns.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($domains as $row)
                            @php
                                $role = $row->is_primary ? 'primary' : ($row->is_temporary ? 'temp' : ($row->is_www ? 'www' : 'alias'));
                                $bound = $row->verified_at !== null;
                            @endphp
                            <tr>
                                <td><code>{{ $row->domain }}</code></td>
                                <td>
                                    @if ($row->site)
                                        <a href="{{ route('ops.sites.show', $row->site) }}">{{ $row->site->name }}</a>
                                    @else
                                        <span class="muted">{{ __('ops.none') }}</span>
                                    @endif
                                </td>
                                <td><span class="status-chip">{{ __('domains.role.'.$role) }}</span></td>
                                <td>
                                    <span class="status-chip status-{{ $bound ? 'ok' : 'error' }}">
                                        {{ $bound ? __('domains.coolify.bound') : __('domains.coolify.unbound') }}
                                    </span>
                                </td>
                                <td class="ops-row-actions">
                                    @if (($canWrite ?? false) && $row->site && filled($row->site->coolify_app_uuid))
                                        <form method="POST" action="{{ route('ops.domains.bind', $row) }}" data-ops-pending>
                                            @csrf
                                            <button type="submit" class="btn btn-ghost btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('domains.actions.bind') }}</button>
                                        </form>
                                    @endif
                                    @if ($row->site)
                                        <a class="btn btn-ghost btn-sm" href="{{ route('ops.sites.show', $row->site) }}">{{ __('domains.actions.open_site') }}</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $domains->links() }}
        @endif
    </div>
@endsection
