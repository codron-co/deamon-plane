@php
    $canLiveSync = $canLiveSync ?? false;
    $canActivate = $canActivate ?? false;
    $canDeactivate = $canDeactivate ?? false;
    $canForceDelete = $canForceDelete ?? false;
    $canDeploy = ($canEdit ?? false) && filled($site->coolify_app_uuid);
    $coolifyAppUrl = $coolifyAppUrl ?? $site->coolifyUiUrl();
    $showDeployMenu = $canDeploy || filled($coolifyAppUrl);
    $showSyncMenu = ($canSyncCoolify ?? false) || $canLiveSync || ($canCheckHealth ?? false);
    $showSiteMenu = filled($site->primary_domain);
    $showSettingsMenu = ($canEdit ?? false) || ($canDelete ?? false) || $canForceDelete;
    $siteUrl = filled($site->primary_domain) ? 'https://'.$site->primary_domain : null;
    $adminUrl = $siteUrl ? $siteUrl.'/admin' : null;
@endphp

@if ($showDeployMenu)
    <details class="ops-action-menu" data-ops-action-menu>
        <summary class="btn btn-secondary btn-sm">{{ __('sites.menu.deploy') }}</summary>
        <div class="ops-action-popover" role="menu">
            @if ($canDeploy)
                <form
                    method="POST"
                    action="{{ route('ops.sites.deploy', $site) }}"
                    data-ops-pending
                    data-confirm="{{ __('site_ops.redeploy.confirm', ['name' => $site->name]) }}"
                    data-confirm-title="{{ __('site_ops.redeploy.confirm_title') }}"
                    data-confirm-label="{{ __('sites.menu.redeploy') }}"
                    data-confirm-danger="false"
                >
                    @csrf
                    <button type="submit" class="ops-menu-button" role="menuitem" data-pending-label="{{ __('site_ops.redeploy.working') }}">{{ __('sites.menu.redeploy') }}</button>
                </form>
                <form
                    method="POST"
                    action="{{ route('ops.sites.follow-head', $site) }}"
                    data-ops-pending
                    data-confirm="{{ __('site_ops.pin.confirm_follow', ['name' => $site->name]) }}"
                    data-confirm-title="{{ __('site_ops.pin.confirm_follow_title') }}"
                    data-confirm-label="{{ __('sites.menu.follow_head') }}"
                    data-confirm-danger="false"
                >
                    @csrf
                    <button type="submit" class="ops-menu-button" role="menuitem" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.menu.follow_head') }}</button>
                </form>
            @endif
            @if (filled($coolifyAppUrl))
                @if ($canDeploy)
                    <div class="ops-action-sep" role="separator"></div>
                @endif
                <a class="ops-menu-link" role="menuitem" href="{{ $coolifyAppUrl }}" target="_blank" rel="noopener noreferrer">{{ __('sites.menu.open_coolify') }}</a>
            @endif
        </div>
    </details>
@endif

@if ($showSyncMenu)
    <details class="ops-action-menu" data-ops-action-menu>
        <summary class="btn btn-secondary btn-sm">{{ __('sites.menu.sync') }}</summary>
        <div class="ops-action-popover" role="menu">
            @if ($canSyncCoolify ?? false)
                <form
                    method="POST"
                    action="{{ route('ops.sites.sync', $site) }}"
                    data-ops-pending
                    data-confirm="{{ __('sites.detail.sync_confirm', ['name' => $site->name]) }}"
                    data-confirm-title="{{ __('sites.detail.sync_title') }}"
                    data-confirm-label="{{ __('sites.menu.coolify') }}"
                    data-confirm-danger="false"
                >
                    @csrf
                    <button type="submit" class="ops-menu-button" role="menuitem" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.menu.coolify') }}</button>
                </form>
            @endif
            @if ($canLiveSync)
                <form
                    method="POST"
                    action="{{ route('ops.sites.live-sync.one', $site) }}"
                    data-ops-pending
                    data-confirm="{{ __('sites.live.confirm_one', ['name' => $site->name]) }}"
                    data-confirm-title="{{ __('sites.live.confirm_title') }}"
                    data-confirm-label="{{ __('sites.menu.live') }}"
                    data-confirm-danger="false"
                >
                    @csrf
                    <button type="submit" class="ops-menu-button" role="menuitem" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.menu.live') }}</button>
                </form>
            @endif
            @if ($canCheckHealth ?? false)
                <form method="POST" action="{{ route('ops.sites.health', $site) }}" data-ops-pending>
                    @csrf
                    <button type="submit" class="ops-menu-button" role="menuitem" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.menu.health') }}</button>
                </form>
            @endif
        </div>
    </details>
@endif

@if ($showSiteMenu)
    <details class="ops-action-menu" data-ops-action-menu>
        <summary class="btn btn-secondary btn-sm">{{ __('sites.menu.site') }}</summary>
        <div class="ops-action-popover" role="menu">
            <a class="ops-menu-link" role="menuitem" href="{{ $siteUrl }}" target="_blank" rel="noopener noreferrer">{{ __('sites.menu.home') }}</a>
            <a class="ops-menu-link" role="menuitem" href="{{ $adminUrl }}" target="_blank" rel="noopener noreferrer">{{ __('sites.menu.admin') }}</a>
        </div>
    </details>
@endif

@if ($showSettingsMenu)
    <details class="ops-action-menu" data-ops-action-menu>
        <summary class="btn btn-secondary btn-sm">{{ __('sites.menu.settings') }}</summary>
        <div class="ops-action-popover" role="menu">
            @if ($canEdit ?? false)
                <a class="ops-menu-link" role="menuitem" href="{{ route('ops.sites.edit', $site) }}">{{ __('sites.menu.edit') }}</a>
            @endif
            @if ($canActivate)
                <form
                    method="POST"
                    action="{{ route('ops.sites.activate', $site) }}"
                    data-ops-pending
                    data-confirm="{{ __('sites.lifecycle.activate_confirm', ['name' => $site->name]) }}"
                    data-confirm-title="{{ __('sites.lifecycle.activate_confirm_title') }}"
                    data-confirm-label="{{ __('sites.menu.activate') }}"
                    data-confirm-danger="false"
                >
                    @csrf
                    <button type="submit" class="ops-menu-button" role="menuitem" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.menu.activate') }}</button>
                </form>
            @endif
            @if ($canDeactivate)
                <form
                    method="POST"
                    action="{{ route('ops.sites.deactivate', $site) }}"
                    data-ops-pending
                    data-confirm="{{ __('sites.lifecycle.deactivate_confirm', ['name' => $site->name]) }}"
                    data-confirm-title="{{ __('sites.lifecycle.deactivate_confirm_title') }}"
                    data-confirm-label="{{ __('sites.menu.deactivate') }}"
                    data-confirm-danger="false"
                >
                    @csrf
                    <button type="submit" class="ops-menu-button" role="menuitem" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.menu.deactivate') }}</button>
                </form>
            @endif
            @if (($canDelete ?? false) || $canForceDelete)
                <div class="ops-action-sep" role="separator"></div>
            @endif
            @if ($canDelete ?? false)
                <form
                    method="POST"
                    action="{{ route('ops.sites.destroy', $site) }}"
                    data-confirm="{{ __('sites.danger.confirm', ['name' => $site->name]) }}"
                    data-confirm-title="{{ __('sites.danger.confirm_title') }}"
                    data-confirm-label="{{ __('sites.menu.soft_delete') }}"
                >
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="ops-menu-button is-danger" role="menuitem">{{ __('sites.menu.soft_delete') }}</button>
                </form>
            @endif
            @if ($canForceDelete)
                <form
                    method="POST"
                    action="{{ route('ops.sites.purge', $site) }}"
                    data-confirm="{{ __('sites.danger.hard_confirm', ['name' => $site->name]) }}"
                    data-confirm-title="{{ __('sites.danger.hard_confirm_title') }}"
                    data-confirm-label="{{ __('sites.menu.hard_delete') }}"
                >
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="ops-menu-button is-danger" role="menuitem">{{ __('sites.menu.hard_delete') }}</button>
                </form>
            @endif
        </div>
    </details>
@endif

@if ($canProvision ?? false)
    <form
        method="POST"
        action="{{ route('ops.sites.provision', $site) }}"
        data-ops-pending
        data-confirm="{{ __('sites.provision.confirm', ['name' => $site->name]) }}"
        data-confirm-title="{{ __('sites.provision.confirm_title') }}"
        data-confirm-label="{{ __('sites.provision.button') }}"
    >
        @csrf
        <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.provision.button') }}</button>
    </form>
@endif
