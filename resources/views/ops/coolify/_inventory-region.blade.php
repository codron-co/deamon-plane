@php
    $filtersActive = $filtersActive ?? false;
    $canWrite = $canWrite ?? false;
    $kind = $inventoryKind ?? '';
    $showKind = static fn (string $wanted): bool => $kind === '' || $kind === $wanted;
@endphp

@if (($inventoryEmpty ?? false) && ! $filtersActive)
    <div class="empty-panel">
        <h2>{{ __('coolify.empty_inventory') }}</h2>
        <p>{{ __('coolify.empty_inventory_hint') }}</p>
        @if ($canWrite)
            <div class="empty-panel-actions">
                <form method="POST" action="{{ route('ops.coolify.sync', $connection) }}" data-ops-pending data-confirm="{{ __('coolify.show.sync_confirm', ['name' => $connection->name]) }}" data-confirm-title="{{ __('coolify.show.sync_confirm_title') }}" data-confirm-label="{{ __('coolify.show.sync') }}" data-confirm-danger="false">
                    @csrf
                    <button type="submit" class="btn btn-primary" data-pending-label="{{ __('ops.actions.working') }}">{{ __('coolify.show.sync') }}</button>
                </form>
            </div>
        @else
            <p class="empty-panel-note">{{ __('ops.viewer_readonly') }}</p>
        @endif
    </div>
@elseif ($inventoryEmpty ?? false)
    <div class="empty-panel empty-panel-filtered">
        <h2>{{ __('coolify.empty_filtered_title') }}</h2>
        <p>{{ __('coolify.empty_filtered_hint', ['total' => $totalInventory ?? 0]) }}</p>
        @include('ops.partials.filter-chips', [
            'chips' => $activeFilters ?? [],
            'label' => __('coolify.empty_filters_label'),
        ])
        <div class="empty-panel-actions">
            <a class="btn btn-primary" href="{{ route('ops.coolify.show', $connection) }}">{{ __('ops.actions.clear_filters') }}</a>
        </div>
    </div>
@else
    @if ($showKind('servers') && (($inventoryServers ?? collect())->isNotEmpty() || ! $filtersActive))
        @include('ops.coolify._allowlist', [
            'title' => __('coolify.allowlist.servers'),
            'hint' => __('coolify.allowlist.servers_hint'),
            'rows' => $inventoryServers ?? collect(),
            'toggleRoute' => 'ops.coolify.servers.toggle',
            'param' => 'server',
            'extra' => 'ip',
            'canWrite' => $canWrite,
            'connection' => $connection,
        ])
    @endif

    @if ($showKind('projects') && (($inventoryProjects ?? collect())->isNotEmpty() || ! $filtersActive))
        @include('ops.coolify._allowlist', [
            'title' => __('coolify.allowlist.projects'),
            'hint' => __('coolify.allowlist.projects_hint'),
            'rows' => $inventoryProjects ?? collect(),
            'toggleRoute' => 'ops.coolify.projects.toggle',
            'param' => 'project',
            'canWrite' => $canWrite,
            'connection' => $connection,
        ])
    @endif

    @if ($showKind('environments') && (($inventoryEnvironments ?? collect())->isNotEmpty() || ! $filtersActive))
        @include('ops.coolify._allowlist', [
            'title' => __('coolify.allowlist.environments'),
            'hint' => __('coolify.allowlist.environments_hint'),
            'rows' => $inventoryEnvironments ?? collect(),
            'toggleRoute' => 'ops.coolify.environments.toggle',
            'param' => 'environment',
            'extra' => 'project_uuid',
            'canWrite' => $canWrite,
            'connection' => $connection,
        ])
    @endif

    @if ($showKind('git') && (($inventoryGitSources ?? collect())->isNotEmpty() || ! $filtersActive))
        @include('ops.coolify._allowlist', [
            'title' => __('coolify.allowlist.git'),
            'hint' => __('coolify.allowlist.git_hint'),
            'rows' => $inventoryGitSources ?? collect(),
            'toggleRoute' => 'ops.coolify.git-sources.toggle',
            'param' => 'source',
            'extra' => 'kind',
            'canWrite' => $canWrite,
            'connection' => $connection,
        ])
    @endif
@endif
