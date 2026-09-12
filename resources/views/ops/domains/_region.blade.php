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
    @include('ops.partials.pagination', ['paginator' => $domains, 'label' => __('domains.pagination')])
@endif
