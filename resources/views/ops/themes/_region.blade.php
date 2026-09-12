@if ($themes->isEmpty() && ! $filtersActive)
    <div class="empty-panel">
        <h2>{{ __('themes.empty.title') }}</h2>
        <p>{{ __('themes.empty.hint') }}</p>
    </div>
@elseif ($themes->isEmpty())
    <div class="empty-panel">
        <h2>{{ __('themes.empty.filtered_title') }}</h2>
        <p>{{ __('themes.empty.filtered_hint') }}</p>
        <a class="btn btn-ghost" href="{{ route('ops.themes') }}">{{ __('ops.actions.clear_filters') }}</a>
    </div>
@else
    <div class="sites-table-wrap">
        <table class="ops-table">
            <thead>
                <tr>
                    <th>{{ __('themes.columns.theme') }}</th>
                    <th>{{ __('themes.columns.theme_id') }}</th>
                    <th>{{ __('themes.columns.repo') }}</th>
                    <th>{{ __('themes.columns.default_ref') }}</th>
                    <th>{{ __('themes.columns.visibility') }}</th>
                    <th>{{ __('themes.columns.sync') }}</th>
                    <th>{{ __('themes.columns.installs') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($themes as $theme)
                    <tr data-href="{{ route('ops.themes.show', $theme) }}" tabindex="0">
                        <td>
                            <a class="site-name" href="{{ route('ops.themes.show', $theme) }}">{{ $theme->displayName() }}</a>
                        </td>
                        <td><code>{{ $theme->theme_id }}</code></td>
                        <td><code>{{ $theme->repo_full_name }}</code></td>
                        <td><span class="branch-chip">{{ $theme->default_ref ?: __('ops.none') }}</span></td>
                        <td><span class="status-chip">{{ $theme->visibility?->label() }}</span></td>
                        <td>
                            @if ($theme->last_synced_at)
                                <span class="status-chip status-active" title="{{ $theme->last_synced_at->toDateTimeString() }}">{{ $theme->last_synced_at->diffForHumans() }}</span>
                            @else
                                <span class="status-chip">{{ __('themes.sync_state.never') }}</span>
                            @endif
                        </td>
                        <td>{{ $theme->installations_count }}</td>
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
