@if ($domains->isEmpty() && ! ($filtersActive ?? false))
    <div class="empty-panel">
        <h2>{{ __('domains.empty.title') }}</h2>
        <p>{{ __('domains.empty.hint') }}</p>
        @if ($canWrite ?? false)
            <form method="POST" action="{{ route('ops.domains.store') }}" class="ops-form empty-panel-form" data-ops-pending>
                @csrf
                <div class="field">
                    <label class="field-label" for="fleet_domain_empty">{{ __('domains.form.domain') }}</label>
                    <input id="fleet_domain_empty" class="field-input" type="text" name="domain" required maxlength="255" autocomplete="off">
                </div>
                <div class="field">
                    <label class="field-label" for="fleet_domain_empty_site">{{ __('domains.form.site') }}</label>
                    <select id="fleet_domain_empty_site" class="field-input" name="site_id" required>
                        <option value="">{{ __('domains.form.site_placeholder') }}</option>
                        @foreach ($sites as $siteOption)
                            <option value="{{ $siteOption->id }}">{{ $siteOption->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="empty-panel-actions">
                    <button type="submit" class="btn btn-primary" data-pending-label="{{ __('ops.actions.working') }}">{{ __('domains.new') }}</button>
                    <a class="btn btn-ghost" href="{{ route('ops.coolify.index') }}">{{ __('domains.empty.import') }}</a>
                </div>
            </form>
        @else
            <p class="empty-panel-note">{{ __('ops.viewer_readonly') }}</p>
            <div class="empty-panel-actions">
                <a class="btn btn-ghost" href="{{ route('ops.coolify.index') }}">{{ __('domains.empty.import') }}</a>
            </div>
        @endif
    </div>
@elseif ($domains->isEmpty())
    <div class="empty-panel empty-panel-filtered">
        <h2>{{ __('domains.empty.filtered_title') }}</h2>
        <p>{{ __('domains.empty.filtered_hint', ['total' => $totalDomains ?? 0]) }}</p>
        @include('ops.partials.filter-chips', [
            'chips' => $activeFilters ?? [],
            'label' => __('domains.empty.filters_label'),
        ])
        <div class="empty-panel-actions">
            <a class="btn btn-primary" href="{{ route('ops.domains') }}">{{ __('ops.actions.clear_filters') }}</a>
        </div>
    </div>
@else
    @include('ops.partials.filter-chips', [
        'chips' => $activeFilters ?? [],
        'label' => __('domains.empty.filters_label'),
    ])
    @if ($canWrite ?? false)
    <form method="POST" class="sites-bulk" id="domains-bulk-form" data-ops-bulk>
        @csrf
        <input type="hidden" name="filter_q" value="{{ $search }}">
        <input type="hidden" name="filter_unbound" value="{{ $unbound ? '1' : '' }}">
    @endif
        <div class="sites-table-wrap">
            <table class="ops-table">
                <thead>
                    <tr>
                        @if ($canWrite ?? false)
                            <th class="ops-check-col">
                                <label class="ops-check-all">
                                    <input type="checkbox" name="all" value="1" data-ops-bulk-all>
                                    <span class="visually-hidden">{{ __('domains.bulk.select_all') }}</span>
                                </label>
                            </th>
                        @endif
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
                            @if ($canWrite ?? false)
                                <td>
                                    <label>
                                        <span class="visually-hidden">{{ $row->domain }}</span>
                                        <input type="checkbox" name="domain_ids[]" value="{{ $row->id }}">
                                    </label>
                                </td>
                            @endif
                            <td>
                                <span class="ops-host-copy">
                                    <code>{{ $row->domain }}</code>
                                    <button
                                        type="button"
                                        class="btn btn-ghost btn-sm"
                                        data-copy-value="{{ $row->domain }}"
                                        data-copied-label="{{ __('sites.detail.copied') }}"
                                        aria-label="{{ __('domains.actions.copy_host', ['host' => $row->domain]) }}"
                                    >{{ __('domains.actions.copy') }}</button>
                                </span>
                            </td>
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
        @if ($canWrite ?? false)
            @php
                $bulkConfirmKey = ($unbound ?? false) ? 'domains.bulk.confirm_unbound' : 'domains.bulk.confirm';
                $bulkSummaryAll = ($unbound ?? false)
                    ? __('domains.bulk.summary_all_unbound', ['total' => $domains->total()])
                    : __('domains.bulk.summary_all', ['total' => $domains->total()]);
                $bulkSkip = ($unbound ?? false)
                    ? __('domains.bulk.skip_hint')
                    : __('domains.bulk.skip_count', ['unbound' => $unboundInFilter ?? 0]);
                $clearConfirmKey = ($unbound ?? false) ? 'domains.bulk.confirm_clear_unbound' : 'domains.bulk.confirm_clear';
            @endphp
            <div
                class="sites-bulk-bar"
                data-ops-bulk-actions
                data-bulk-total="{{ $domains->total() }}"
                data-copy-selected="{{ __('domains.bulk.summary_page', ['count' => '__COUNT__']) }}"
                data-copy-all="{{ $bulkSummaryAll }}"
            >
                <p class="sites-bulk-summary">
                    <strong data-ops-bulk-summary-text aria-live="polite"></strong>
                    <button type="button" class="btn btn-ghost btn-sm" data-ops-bulk-select-all hidden>{{ __('domains.bulk.select_all_matching', ['total' => $domains->total()]) }}</button>
                    <button type="button" class="btn btn-ghost btn-sm" data-ops-bulk-select-page hidden>{{ __('domains.bulk.select_page_only') }}</button>
                </p>
                <p class="sites-bulk-skip" data-ops-bulk-skip>{{ $bulkSkip }}</p>
                <div class="form-actions sites-bulk-actions" role="group" aria-label="{{ __('domains.bulk.label') }}">
                    <button
                        type="submit"
                        class="btn btn-secondary btn-sm"
                        formaction="{{ route('ops.domains.bulk-bind') }}"
                        data-confirm="{{ __($bulkConfirmKey, ['count' => $domains->total()]) }}"
                        data-confirm-title="{{ __('domains.bulk.confirm_title') }}"
                        data-confirm-label="{{ __('domains.actions.bind') }}"
                        data-confirm-template="{{ __($bulkConfirmKey, ['count' => '__COUNT__']) }}"
                        data-confirm-danger="false"
                    >{{ __('domains.actions.bind') }}</button>
                    <button
                        type="submit"
                        class="btn btn-ghost btn-sm"
                        formaction="{{ route('ops.domains.bulk-clear') }}"
                        data-confirm="{{ __('domains.bulk.confirm_clear', ['count' => $domains->total()]) }}"
                        data-confirm-title="{{ __('domains.bulk.confirm_clear_title') }}"
                        data-confirm-label="{{ __('domains.actions.clear') }}"
                        data-confirm-template="{{ __('domains.bulk.confirm_clear', ['count' => '__COUNT__']) }}"
                        data-confirm-danger="true"
                    >{{ __('domains.actions.clear') }}</button>
                </div>
            </div>
    </form>
        @endif
    @include('ops.partials.pagination', ['paginator' => $domains, 'label' => __('domains.pagination')])
@endif
