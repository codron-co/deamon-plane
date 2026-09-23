@if ($themes->isEmpty() && ! $filtersActive)
    <div class="empty-panel">
        <h2>{{ __('themes.empty.title') }}</h2>
        <p>{{ __('themes.empty.hint') }}</p>
        @if ($canSync ?? false)
            <div class="empty-panel-actions">
                <form method="POST" action="{{ route('ops.themes.sync') }}" data-ops-pending>
                    @csrf
                    <button type="submit" class="btn btn-primary" data-pending-label="{{ __('ops.actions.working') }}">{{ __('themes.sync') }}</button>
                </form>
            </div>
        @else
            <p class="empty-panel-note">{{ __('ops.viewer_readonly') }}</p>
        @endif
    </div>
@elseif ($themes->isEmpty())
    <div class="empty-panel empty-panel-filtered">
        <h2>{{ __('themes.empty.filtered_title') }}</h2>
        <p>{{ __('themes.empty.filtered_hint', ['total' => $totalThemes ?? 0]) }}</p>
        @include('ops.partials.filter-chips', [
            'chips' => $activeFilters ?? [],
            'label' => __('themes.empty.filters_label'),
        ])
        <div class="empty-panel-actions">
            <a class="btn btn-primary" href="{{ route('ops.themes') }}">{{ __('ops.actions.clear_filters') }}</a>
        </div>
    </div>
@else
    @include('ops.partials.filter-chips', [
        'chips' => $activeFilters ?? [],
        'label' => __('themes.empty.filters_label'),
    ])
    <div class="sites-table-wrap">
        <table class="ops-table themes-table">
            <thead>
                <tr>
                    <th>{{ __('themes.columns.theme') }}</th>
                    <th>{{ __('themes.columns.theme_id') }}</th>
                    <th>{{ __('themes.columns.repo') }}</th>
                    <th>{{ __('themes.columns.ref_latest') }}</th>
                    <th>{{ __('themes.columns.visibility') }}</th>
                    <th>{{ __('themes.columns.sync') }}</th>
                    <th class="ops-num-col">{{ __('themes.columns.installed_sites') }}</th>
                    <th class="ops-num-col">{{ __('themes.columns.outdated') }}</th>
                    <th><span class="visually-hidden">{{ __('themes.columns.actions') }}</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($themes as $theme)
                    @php
                        $latestLabel = filled($theme->latest_tag)
                            ? $theme->latest_tag
                            : (filled($theme->latest_sha) ? substr((string) $theme->latest_sha, 0, 7) : null);
                        $visibilityTone = match ($theme->visibility?->value) {
                            'public_catalog' => 'status-active',
                            'allowlist' => 'status-draft',
                            default => '',
                        };
                        $outdatedInstalls = (int) ($theme->outdated_installs_count ?? 0);
                    @endphp
                    <tr data-href="{{ route('ops.themes.show', $theme) }}" tabindex="0">
                        <td>
                            <a class="site-name" href="{{ route('ops.themes.show', $theme) }}">{{ $theme->displayName() }}</a>
                        </td>
                        <td><code>{{ $theme->theme_id }}</code></td>
                        <td><code>{{ $theme->repo_full_name }}</code></td>
                        <td>
                            <span class="plane-ref @if ($latestLabel === null) is-unknown @endif">
                                <span class="branch-chip plane-ref-branch"><x-ops.git-icon />{{ $theme->default_ref ?: __('ops.none') }}</span>
                                <span
                                    class="version-chip plane-ref-version"
                                    @if (filled($theme->latest_sha)) title="{{ $theme->latest_sha }}" @endif
                                    data-theme-latest
                                >{{ $latestLabel ?? __('themes.latest_unknown') }}</span>
                            </span>
                        </td>
                        <td><span class="status-chip {{ $visibilityTone }}">{{ $theme->visibility?->label() }}</span></td>
                        <td>
                            @if ($theme->last_synced_at)
                                <span class="status-chip status-active" title="{{ $theme->last_synced_at->toDateTimeString() }}">{{ $theme->last_synced_at->diffForHumans() }}</span>
                            @else
                                <span class="status-chip">{{ __('themes.sync_state.never') }}</span>
                            @endif
                        </td>
                        <td class="ops-num-col" data-theme-installs>{{ $theme->installations_count }}</td>
                        <td class="ops-num-col" data-theme-outdated>
                            @if ($outdatedInstalls > 0)
                                <a class="plane-count is-warning" href="{{ route('ops.sites', ['theme' => 'outdated', 'theme_id' => $theme->theme_id]) }}" aria-label="{{ __('themes.outdated_link', ['count' => $outdatedInstalls, 'theme' => $theme->displayName()]) }}">{{ $outdatedInstalls }}</a>
                            @else
                                <span class="muted">0</span>
                            @endif
                        </td>
                        <td class="ops-row-actions">
                            <a class="btn btn-ghost btn-sm" href="{{ route('ops.themes.show', $theme) }}">{{ __('ops.actions.open') }}</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @include('ops.partials.pagination', ['paginator' => $themes, 'label' => __('themes.pagination')])
@endif
