@php
    $themeInstallations = $themeInstallations ?? collect();
    $assignableThemes = $assignableThemes ?? collect();
    $canAssignTheme = $canAssignTheme ?? false;
@endphp

<section class="settings-panel" aria-labelledby="site-themes-heading">
    <h2 id="site-themes-heading">Themes</h2>
    <p class="field-hint">
        Assign installs via the CMS theme agent (<code>POST /internal/control/v1/themes/*</code>, HMAC <code>X-Deamon-*</code>).
        Plane never uploads a ZIP and never copies random PHP onto Coolify volumes.
        Auto-update stays <strong>off</strong> unless you opt in.
    </p>

    @if ($themeInstallations->isEmpty())
        <p class="field-hint">No theme installations on this site.</p>
    @else
        <table class="ops-table">
            <thead>
                <tr>
                    <th>Theme</th>
                    <th>Ref / SHA</th>
                    <th>Status</th>
                    <th>Active</th>
                    <th>Auto-update</th>
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
                                —
                            @endif
                        </td>
                        <td>
                            <code>{{ $installation->ref }}</code>
                            @if ($installation->pinned_sha)
                                <div class="site-slug">{{ substr($installation->pinned_sha, 0, 7) }}</div>
                            @endif
                        </td>
                        <td>
                            <span class="status-chip">{{ $installation->status?->value }}</span>
                            @if ($installation->last_error)
                                <div class="site-slug">{{ $installation->last_error }}</div>
                            @endif
                        </td>
                        <td>{{ $installation->is_active ? 'yes' : 'no' }}</td>
                        <td>{{ $installation->auto_update ? 'on' : 'off' }}</td>
                        <td class="ops-row-actions">
                            @if ($canAssignTheme)
                                <form method="POST" action="{{ route('ops.sites.themes.update', [$site, $installation]) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-ghost btn-sm">Update to latest</button>
                                </form>
                                <form method="POST" action="{{ route('ops.sites.themes.sync', [$site, $installation]) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-ghost btn-sm">Sync now</button>
                                </form>
                                @if (! $installation->is_active)
                                    <form
                                        method="POST"
                                        action="{{ route('ops.sites.themes.activate', [$site, $installation]) }}"
                                        data-confirm="Activate {{ $installedTheme?->theme_id }} on {{ $site->name }}? This replaces the live CMS active theme."
                                        data-confirm-title="Activate theme"
                                        data-confirm-label="Activate"
                                    >
                                        @csrf
                                        <input type="hidden" name="confirmed" value="0">
                                        <button type="submit" class="btn btn-ghost btn-sm">Activate</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('ops.sites.themes.auto-update', [$site, $installation]) }}">
                                    @csrf
                                    <input type="hidden" name="auto_update" value="{{ $installation->auto_update ? 0 : 1 }}">
                                    <button type="submit" class="btn btn-ghost btn-sm">
                                        {{ $installation->auto_update ? 'Disable auto-update' : 'Enable auto-update' }}
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
            data-confirm="Assign and activate this catalog theme on {{ $site->name }}? The CMS agent will git-install it. This is not a ZIP upload."
            data-confirm-title="Assign theme"
            data-confirm-label="Assign"
            data-confirm-danger="true"
        >
            @csrf
            <input type="hidden" name="confirmed" value="0">
            <div class="field">
                <label class="field-label" for="site-theme-id">Catalog theme</label>
                <select id="site-theme-id" class="field-input" name="theme_id" required>
                    <option value="">Select a theme</option>
                    @foreach ($assignableThemes as $theme)
                        <option value="{{ $theme->theme_id }}">{{ $theme->displayName() }} ({{ $theme->visibility?->value }})</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label class="field-label" for="site-theme-ref">Ref</label>
                <p class="field-hint">Branch or tag. Empty uses the catalog default_ref.</p>
                <input id="site-theme-ref" class="field-input" type="text" name="ref" value="{{ old('ref') }}" placeholder="main">
            </div>
            <input type="hidden" name="activate" value="0">
            <input type="hidden" name="sync" value="0">
            <label class="field-check">
                <input type="checkbox" name="activate" value="1" checked>
                Activate after install (requires confirm)
            </label>
            <label class="field-check">
                <input type="checkbox" name="sync" value="1" checked>
                Run theme sync after activate
            </label>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Assign theme</button>
            </div>
        </form>
    @endif
</section>
