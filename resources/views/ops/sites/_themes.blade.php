@php
    $themeInstallations = $themeInstallations ?? collect();
    $assignableThemes = $assignableThemes ?? collect();
    $canAssignTheme = $canAssignTheme ?? false;
    $activeInstallation = $site->activeThemeInstallation;
    $activeTheme = $activeInstallation?->theme;
    $reportedThemeId = $site->reportedActiveThemeId();
    $healthThemeId = $site->healthReportedThemeId();
    $themeSource = $site->reportedThemeSource();
    $healthOnly = $themeSource === 'health' && filled($reportedThemeId);
    $healthTheme = $healthOnly
        ? \App\Models\Theme::query()->where('theme_id', $reportedThemeId)->first()
        : null;
    $displayThemeName = $activeTheme?->displayName()
        ?? $healthTheme?->displayName()
        ?? $reportedThemeId;
    $healthDiffers = $activeTheme
        && filled($healthThemeId)
        && $healthThemeId !== $activeTheme->theme_id;
    // CMS < 1.2.21 (or unknown): merge rewrites every row and overwrite is rejected.
    $editSafeSync = $site->supportsEditSafeThemeSync();
    $editSafeVersion = \App\Services\Agent\ControlPlaneAgentContract::THEME_SYNC_EDIT_SAFE_VERSION;
    $reportedCms = $site->reportedDeamonVersion() ?? __('ops.unknown');
    // CMS < 1.2.32 (or unknown): update replaces theme files the site edited.
    $keepsFileCustomizations = $site->keepsThemeFileCustomizationsOnUpdate();
    $keepsFileCustomizationsVersion = \App\Services\Agent\ControlPlaneAgentContract::THEME_UPDATE_KEEPS_CUSTOMIZATIONS_VERSION;
@endphp

<section class="site-theme-section" aria-labelledby="site-themes-heading">
    <div class="site-section-heading">
        <h2 id="site-themes-heading">{{ __('sites.themes.title') }} @include('ops.dashboard._hint', ['text' => __('sites.themes.lede')])</h2>
    </div>

    <div class="site-overview-grid">
        <article class="site-card">
            @if ($activeTheme)
                <div class="site-card-head">
                    <div>
                        <h3>{{ $activeTheme->displayName() }} @include('ops.dashboard._hint', ['text' => __('sites.themes.via_agent')])</h3>
                    </div>
                    <span class="status-chip">{{ $activeInstallation->status?->label() ?? $activeInstallation->status?->value }}</span>
                </div>
                <p class="site-note">{{ $activeTheme->theme_id }} · {{ $activeInstallation->ref }}</p>
                @if ($healthDiffers)
                    <p class="ops-alert ops-alert-warning" role="status">{{ __('sites.themes.health_differs', ['theme' => $healthThemeId]) }}</p>
                @endif
            @elseif ($healthOnly)
                <div class="site-card-head">
                    <div>
                        <h3>{{ $displayThemeName }} @include('ops.dashboard._hint', ['text' => __('sites.themes.health_only_hint')])</h3>
                    </div>
                    <span class="status-chip">{{ __('sites.themes.via_health') }}</span>
                </div>
                <p class="site-note">{{ $reportedThemeId }}</p>
            @else
                <div>
                    <h3>{{ __('sites.themes.empty_title') }} @include('ops.dashboard._hint', ['text' => __('sites.themes.empty_hint')])</h3>
                </div>
            @endif
        </article>

        @if ($canAssignTheme)
            <aside class="site-card">
                <form
                    method="POST"
                    action="{{ route('ops.sites.themes.assign', $site) }}"
                    class="ops-form"
                    data-confirm="{{ __('sites.themes.assign_confirm', ['site' => $site->name]) }}"
                    data-confirm-title="{{ __('sites.themes.assign_title') }}"
                    data-confirm-label="{{ __('sites.themes.assign') }}"
                    data-confirm-danger="true"
                >
                    @csrf
                    <input type="hidden" name="confirmed" value="0">
                    <div class="field">
                        <label class="field-label" for="site-theme-id">{{ __('sites.themes.catalog') }}</label>
                        <select
                            id="site-theme-id"
                            class="field-input"
                            name="theme_id"
                            required
                            data-ops-select-search
                            data-ops-select-search-placeholder="{{ __('sites.themes.search_placeholder') }}"
                            data-ops-select-empty="{{ __('ops.select.no_matches') }}"
                        >
                            <option value="">{{ __('sites.themes.select') }}</option>
                            @foreach ($assignableThemes as $theme)
                                <option value="{{ $theme->theme_id }}">{{ $theme->displayName() }} ({{ $theme->visibility?->label() }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label class="field-label" for="site-theme-ref">{{ __('sites.themes.ref') }} @include('ops.dashboard._hint', ['text' => __('sites.themes.ref_hint')])</label>
                        <input id="site-theme-ref" class="field-input" type="text" name="ref" value="{{ old('ref') }}" placeholder="main">
                    </div>
                    <input type="hidden" name="activate" value="0">
                    <input type="hidden" name="sync" value="0">
                    <label class="field-check">
                        <input type="checkbox" name="activate" value="1" checked>
                        {{ __('sites.themes.activate_after') }}
                    </label>
                    <label class="field-check">
                        <input type="checkbox" name="sync" value="1" checked>
                        {{ __('sites.themes.sync_after') }}
                    </label>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">{{ __('sites.themes.assign') }}</button>
                    </div>
                </form>
            </aside>
        @endif
    </div>

    @if ($themeInstallations->isNotEmpty())
        <div class="sites-table-wrap">
            <table class="ops-table">
                <thead>
                    <tr>
                        <th>{{ __('sites.themes.columns.theme') }}</th>
                        <th>{{ __('sites.themes.columns.ref') }}</th>
                        <th>{{ __('sites.themes.columns.status') }}</th>
                        <th>{{ __('sites.themes.columns.active') }}</th>
                        <th>{{ __('sites.themes.columns.auto_update') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($themeInstallations as $installation)
                        @php($installedTheme = $installation->theme)
                        <tr>
                            <td>
                                @if ($installedTheme)
                                    <a href="{{ route('ops.themes.show', $installedTheme) }}">{{ $installedTheme->displayName() }}</a>
                                    <div class="site-slug">{{ $installedTheme->theme_id }}</div>
                                @else
                                    {{ __('ops.none') }}
                                @endif
                            </td>
                            <td>
                                <code>{{ $installation->ref }}</code>
                                @if ($installation->pinned_sha)
                                    <div class="site-slug">{{ substr($installation->pinned_sha, 0, 7) }}</div>
                                @endif
                            </td>
                            <td>
                                <span class="status-chip">{{ $installation->status?->label() ?? $installation->status?->value }}</span>
                                @if ($installation->last_error)
                                    <div class="site-slug">{{ $installation->last_error }}</div>
                                @endif
                                @php($customizedFiles = is_array($installation->customized_files) ? $installation->customized_files : [])
                                @if (! empty($customizedFiles['kept']))
                                    <div class="site-slug" data-theme-customized-kept>{{ __('sites.themes.customized_kept', ['count' => count($customizedFiles['kept'])]) }}</div>
                                @endif
                                @if (! empty($customizedFiles['conflicts']))
                                    <details class="site-slug" data-theme-customized-conflicts>
                                        <summary>{{ __('sites.themes.customized_conflicts', ['count' => count($customizedFiles['conflicts'])]) }}</summary>
                                        <ul>
                                            @foreach ($customizedFiles['conflicts'] as $path)
                                                <li><code>{{ $path }}</code></li>
                                            @endforeach
                                        </ul>
                                    </details>
                                @endif
                            </td>
                            <td>{{ $installation->is_active ? __('ops.yes') : __('ops.no') }}</td>
                            <td>{{ $installation->auto_update ? __('ops.on') : __('ops.off') }}</td>
                            <td class="ops-row-actions">
                                @if ($canAssignTheme)
                                    <form
                                        method="POST"
                                        action="{{ route('ops.sites.themes.update', [$site, $installation]) }}"
                                        data-confirm="{{ $keepsFileCustomizations
                                            ? __('sites.themes.update_confirm', ['theme' => $installedTheme?->theme_id])
                                            : __('sites.themes.update_confirm_legacy', ['theme' => $installedTheme?->theme_id, 'version' => $keepsFileCustomizationsVersion, 'reported' => $reportedCms]) }}"
                                        data-confirm-title="{{ __('sites.themes.update_title') }}"
                                        data-confirm-label="{{ __('sites.themes.update_latest') }}"
                                        data-confirm-danger="{{ $keepsFileCustomizations ? 'false' : 'true' }}"
                                    >
                                        @csrf
                                        <button type="submit" class="btn btn-ghost btn-sm">{{ __('sites.themes.update_latest') }}</button>
                                    </form>
                                    <form
                                        method="POST"
                                        action="{{ route('ops.sites.themes.sync', [$site, $installation]) }}"
                                        data-theme-sync="merge"
                                        data-confirm="{{ $editSafeSync
                                            ? __('sites.themes.sync_confirm', ['theme' => $installedTheme?->theme_id])
                                            : __('sites.themes.sync_confirm_legacy', ['theme' => $installedTheme?->theme_id, 'version' => $editSafeVersion, 'reported' => $reportedCms]) }}"
                                        data-confirm-title="{{ __('sites.themes.sync_title') }}"
                                        data-confirm-label="{{ __('sites.themes.sync') }}"
                                        data-confirm-danger="{{ $editSafeSync ? 'false' : 'true' }}"
                                    >
                                        @csrf
                                        <button type="submit" class="btn btn-ghost btn-sm">{{ __('sites.themes.sync') }}</button>
                                    </form>
                                    @if ($editSafeSync)
                                        <form
                                            method="POST"
                                            action="{{ route('ops.sites.themes.sync', [$site, $installation]) }}"
                                            data-theme-sync="overwrite"
                                            data-confirm="{{ __('sites.themes.sync_overwrite_confirm', ['theme' => $installedTheme?->theme_id, 'site' => $site->name]) }}"
                                            data-confirm-title="{{ __('sites.themes.sync_overwrite_title') }}"
                                            data-confirm-label="{{ __('sites.themes.sync_overwrite') }}"
                                            data-confirm-danger="true"
                                        >
                                            @csrf
                                            <input type="hidden" name="mode" value="overwrite">
                                            <input type="hidden" name="confirmed" value="0">
                                            <button type="submit" class="btn btn-ghost btn-sm">{{ __('sites.themes.sync_overwrite') }}</button>
                                        </form>
                                    @else
                                        <button
                                            type="button"
                                            class="btn btn-ghost btn-sm"
                                            disabled
                                            data-theme-sync-overwrite-disabled
                                            title="{{ __('sites.themes.sync_overwrite_needs_cms', ['version' => $editSafeVersion, 'reported' => $reportedCms]) }}"
                                            aria-describedby="theme-overwrite-needs-cms-{{ $installation->id }}"
                                        >{{ __('sites.themes.sync_overwrite') }}</button>
                                        <span class="visually-hidden" id="theme-overwrite-needs-cms-{{ $installation->id }}">{{ __('sites.themes.sync_overwrite_needs_cms', ['version' => $editSafeVersion, 'reported' => $reportedCms]) }}</span>
                                    @endif
                                    @if ($installation->last_sync_task_id)
                                        <form
                                            method="POST"
                                            action="{{ route('ops.sites.themes.sync-rollback', [$site, $installation]) }}"
                                            data-confirm="{{ __('sites.themes.sync_rollback_confirm', ['theme' => $installedTheme?->theme_id, 'site' => $site->name]) }}"
                                            data-confirm-title="{{ __('sites.themes.sync_rollback_title') }}"
                                            data-confirm-label="{{ __('sites.themes.sync_rollback') }}"
                                            data-confirm-danger="true"
                                        >
                                            @csrf
                                            <input type="hidden" name="confirmed" value="0">
                                            <button type="submit" class="btn btn-ghost btn-sm">{{ __('sites.themes.sync_rollback') }}</button>
                                        </form>
                                    @endif
                                    @if ($installation->previous_pinned_sha)
                                        <form
                                            method="POST"
                                            action="{{ route('ops.sites.themes.files-rollback', [$site, $installation]) }}"
                                            data-confirm="{{ __('sites.themes.files_rollback_confirm', ['theme' => $installedTheme?->theme_id, 'sha' => substr($installation->previous_pinned_sha, 0, 7)]) }}"
                                            data-confirm-title="{{ __('sites.themes.files_rollback_title') }}"
                                            data-confirm-label="{{ __('sites.themes.files_rollback') }}"
                                            data-confirm-danger="true"
                                        >
                                            @csrf
                                            <input type="hidden" name="confirmed" value="0">
                                            <button type="submit" class="btn btn-ghost btn-sm">{{ __('sites.themes.files_rollback') }}</button>
                                        </form>
                                    @endif
                                    @if (! $installation->is_active)
                                        <form
                                            method="POST"
                                            action="{{ route('ops.sites.themes.activate', [$site, $installation]) }}"
                                            data-confirm="{{ __('sites.themes.activate_confirm', ['theme' => $installedTheme?->theme_id, 'site' => $site->name]) }}"
                                            data-confirm-title="{{ __('sites.themes.activate_title') }}"
                                            data-confirm-label="{{ __('sites.themes.activate') }}"
                                            data-confirm-danger="false"
                                        >
                                            @csrf
                                            <input type="hidden" name="confirmed" value="0">
                                            <button type="submit" class="btn btn-ghost btn-sm">{{ __('sites.themes.activate') }}</button>
                                        </form>
                                    @endif
                                    <form
                                        method="POST"
                                        action="{{ route('ops.sites.themes.auto-update', [$site, $installation]) }}"
                                        data-confirm="{{ $installation->auto_update ? __('sites.themes.auto_update_off_confirm', ['theme' => $installedTheme?->theme_id]) : __('sites.themes.auto_update_confirm', ['theme' => $installedTheme?->theme_id]) }}"
                                        data-confirm-title="{{ __('sites.themes.auto_update_title') }}"
                                        data-confirm-label="{{ $installation->auto_update ? __('sites.themes.disable_auto') : __('sites.themes.enable_auto') }}"
                                        data-confirm-danger="{{ $installation->auto_update ? 'false' : 'true' }}"
                                    >
                                        @csrf
                                        <input type="hidden" name="auto_update" value="{{ $installation->auto_update ? 0 : 1 }}">
                                        <button type="submit" class="btn btn-ghost btn-sm">
                                            {{ $installation->auto_update ? __('sites.themes.disable_auto') : __('sites.themes.enable_auto') }}
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
