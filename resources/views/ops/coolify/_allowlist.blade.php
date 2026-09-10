@php
    $extra = $extra ?? null;
    $showRoute = match ($param) {
        'server' => 'ops.coolify.servers.show',
        'project' => 'ops.coolify.projects.show',
        'environment' => 'ops.coolify.environments.show',
        'source' => 'ops.coolify.git-sources.show',
        default => null,
    };
@endphp

<section class="ops-panel" aria-labelledby="allow-{{ $param }}-heading">
    <h2 id="allow-{{ $param }}-heading">{{ $title }}</h2>
    <p>{{ $hint }}</p>

    @if ($rows->isEmpty())
        <p class="muted">{{ __('coolify.allowlist.empty') }}</p>
    @else
        <div class="sites-table-wrap">
            <table class="ops-table">
                <thead>
                    <tr>
                        <th>{{ __('coolify.allowlist.name') }}</th>
                        @if ($extra)
                            <th>{{ $extra === 'kind' ? __('coolify.allowlist.kind') : ($extra === 'ip' ? __('coolify.allowlist.ip') : __('coolify.allowlist.project')) }}</th>
                        @endif
                        <th>{{ __('coolify.allowlist.status') }}</th>
                        @if ($canWrite)
                            <th></th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr @if ($showRoute) data-href="{{ route($showRoute, ['connection' => $connection, $param => $row]) }}" tabindex="0" @endif>
                            <td>
                                @if ($showRoute)
                                    <a class="site-name" href="{{ route($showRoute, ['connection' => $connection, $param => $row]) }}">{{ $row->name ?: $row->uuid }}</a>
                                @else
                                    <span class="site-name">{{ $row->name ?: $row->uuid }}</span>
                                @endif
                                <div class="site-slug">{{ $row->uuid }}</div>
                            </td>
                            @if ($extra === 'kind')
                                <td class="muted">{{ $row->kind?->label() ?? $row->kind }}</td>
                            @elseif ($extra)
                                <td class="muted">{{ $row->{$extra} }}</td>
                            @endif
                            <td>
                                <span class="status-chip status-{{ $row->is_active ? 'active' : 'error' }}">
                                    {{ $row->is_active ? __('ops.active') : __('ops.inactive') }}
                                </span>
                            </td>
                            @if ($canWrite)
                                <td class="ops-row-actions">
                                    <form method="POST" action="{{ route($toggleRoute, ['connection' => $connection, $param => $row]) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-ghost btn-sm">{{ $row->is_active ? __('coolify.allowlist.deactivate') : __('coolify.allowlist.activate') }}</button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
