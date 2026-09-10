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
                        <select id="site-theme-id" class="field-input" name="theme_id" required>
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
                            </td>
                            <td>{{ $installation->is_active ? __('ops.yes') : __('ops.no') }}</td>
                            <td>{{ $installation->auto_update ? __('ops.on') : __('ops.off') }}</td>
                            <td class="ops-row-actions">
                                @if ($canAssignTheme)
                                    <form
                                        method="POST"
                                        action="{{ route('ops.sites.themes.update', [$site, $installation]) }}"
                                        data-confirm="{{ __('sites.themes.update_confirm', ['theme' => $installedTheme?->theme_id]) }}"
                                        data-confirm-title="{{ __('sites.themes.update_title') }}"
                                        data-confirm-label="{{ __('sites.themes.update_latest') }}"
                                        data-confirm-danger="false"
                                    >
                                        @csrf
                                        <button type="submit" class="btn btn-ghost btn-sm">{{ __('sites.themes.update_latest') }}</button>
                                    </form>
                                    <form
                                        method="POST"
                                        action="{{ route('ops.sites.themes.sync', [$site, $installation]) }}"
                                        data-confirm="{{ __('sites.themes.sync_confirm', ['theme' => $installedTheme?->theme_id]) }}"
                                        data-confirm-title="{{ __('sites.themes.sync_title') }}"
                                        data-confirm-label="{{ __('sites.themes.sync') }}"
                                        data-confirm-danger="false"
                                    >
                                        @csrf
                                        <button type="submit" class="btn btn-ghost btn-sm">{{ __('sites.themes.sync') }}</button>
                                    </form>
                                    @if (! $installation->is_active)
                                        <form
                                            method="POST"
                                            action="{{ route('ops.sites.themes.activate', [$site, $installation]) }}"
                                            data-confirm="{{ __('sites.themes.activate_confirm', ['theme' => $installedTheme?->theme_id, 'site' => $site->name]) }}"
                                            data-confirm-title="{{ __('sites.themes.activate_title') }}"
                                            data-confirm-label="{{ __('sites.themes.activate') }}"
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
