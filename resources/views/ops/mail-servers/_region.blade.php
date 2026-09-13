@if ($servers->isEmpty() && ! $filtersActive)
    <div class="empty-panel">
        <h2>{{ __('mail.empty') }}</h2>
        <p>{{ __('mail.empty_hint') }}</p>
        @if ($canWrite ?? false)
            <div class="empty-panel-actions">
                <a class="btn btn-primary" href="{{ route('ops.mail-servers.create') }}">{{ __('mail.add') }}</a>
            </div>
        @else
            <p class="empty-panel-note">{{ __('ops.viewer_readonly') }}</p>
        @endif
    </div>
@elseif ($servers->isEmpty())
    <div class="empty-panel empty-panel-filtered">
        <h2>{{ __('mail.empty_filtered_title') }}</h2>
        <p>{{ __('mail.empty_filtered_hint', ['total' => $totalServers ?? 0]) }}</p>
        @include('ops.partials.filter-chips', [
            'chips' => $activeFilters ?? [],
            'label' => __('mail.empty_filters_label'),
        ])
        <div class="empty-panel-actions">
            <a class="btn btn-primary" href="{{ route('ops.mail-servers.index') }}">{{ __('ops.actions.clear_filters') }}</a>
        </div>
    </div>
@else
    <div class="sites-table-wrap">
        <table class="ops-table">
            <thead>
                <tr>
                    <th>{{ __('mail.columns.name') }}</th>
                    <th>{{ __('mail.columns.provider') }}</th>
                    <th>{{ __('mail.columns.sites') }}</th>
                    <th>{{ __('mail.columns.status') }}</th>
                    <th>{{ __('mail.columns.probe') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($servers as $server)
                    <tr data-href="{{ route('ops.mail-servers.show', $server) }}" tabindex="0">
                        <td>
                            <a class="site-name" href="{{ route('ops.mail-servers.show', $server) }}">{{ $server->name }}</a>
                        </td>
                        <td>{{ $server->provider?->label() }}</td>
                        <td class="muted">{{ $server->sites_count }}</td>
                        <td>
                            <span class="status-chip status-{{ $server->is_enabled ? 'active' : 'error' }}">
                                {{ $server->is_enabled ? __('ops.enabled') : __('ops.disabled') }}
                            </span>
                        </td>
                        <td class="muted">
                            @if ($server->last_probe_at)
                                <time datetime="{{ $server->last_probe_at->toIso8601String() }}">{{ $server->last_probe_at->toDateTimeString() }}</time>
                            @else
                                {{ __('ops.never') }}
                            @endif
                        </td>
                        <td class="ops-row-actions">
                            <a class="btn btn-ghost btn-sm" href="{{ route('ops.mail-servers.show', $server) }}">{{ __('ops.actions.open') }}</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @include('ops.partials.pagination', ['paginator' => $servers, 'label' => __('mail.pagination')])
@endif
