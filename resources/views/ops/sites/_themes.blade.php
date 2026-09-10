@php
    $themeInstallations = $themeInstallations ?? collect();
    $assignableThemes = $assignableThemes ?? collect();
    $canAssignTheme = $canAssignTheme ?? false;
@endphp

<section class="settings-panel" aria-labelledby="site-themes-heading">
    <h2 id="site-themes-heading">{{ __('sites.themes.title') }}</h2>
    <p class="field-hint">{{ __('sites.themes.lede') }}</p>

    @if ($themeInstallations->isEmpty())
        <p class="field-hint">{{ __('sites.themes.empty') }}</p>
    @else
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
                                <form method="POST" action="{{ route('ops.sites.themes.update', [$site, $installation]) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-ghost btn-sm">{{ __('sites.themes.update_latest') }}</button>
                                </form>
                                <form method="POST" action="{{ route('ops.sites.themes.sync', [$site, $installation]) }}">
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
                                <form method="POST" action="{{ route('ops.sites.themes.auto-update', [$site, $installation]) }}">
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
    @endif

    @if ($canAssignTheme)
        <form
            method="POST"
            action="{{ route('ops.sites.themes.assign', $site) }}"
            class="ops-form settings-form"
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
                <label class="field-label" for="site-theme-ref">{{ __('sites.themes.ref') }}</label>
                <p class="field-hint">{{ __('sites.themes.ref_hint') }}</p>
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
    @endif
</section>
