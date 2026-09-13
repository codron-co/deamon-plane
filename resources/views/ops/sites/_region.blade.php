        <span
            hidden
            data-ops-list-columns="{{ implode(',', $listView->columns) }}"
            data-ops-list-sort="{{ $listView->sortKey }}:{{ $listView->sortDirection }}"
        ></span>
        @isset($savedViews)
            @include('ops.sites._saved-views')
        @endisset
        @if ($sites->isEmpty() && ! $filtersActive)
            <div class="empty-panel">
                <h2>{{ __('sites.empty.title') }}</h2>
                <p>{{ __('sites.empty.hint') }}</p>
                @if ($canCreate)
                    <div class="empty-panel-actions">
                        <a class="btn btn-primary" href="{{ route('ops.sites.create') }}">{{ __('sites.new') }}</a>
                    </div>
                @else
                    <p class="empty-panel-note">{{ __('ops.viewer_readonly') }}</p>
                @endif
            </div>
        @elseif ($sites->isEmpty())
            {{-- Filter-empty is a different problem from fleet-empty: name the filters and lead with clearing them. --}}
            <div class="empty-panel empty-panel-filtered">
                <h2>{{ __('sites.empty.filtered_title') }}</h2>
                <p>{{ __('sites.empty.filtered_hint', ['total' => $totalSites ?? 0]) }}</p>
                @include('ops.partials.filter-chips', [
                    'chips' => $activeFilters ?? [],
                    'label' => __('sites.empty.filters_label'),
                ])
                <div class="empty-panel-actions">
                    <a class="btn btn-primary" href="{{ \App\Support\Lists\SiteSavedViews::clearUrl() }}">{{ __('ops.actions.clear_filters') }}</a>
                    @if ($canCreate)
                        <a class="btn btn-ghost" href="{{ route('ops.sites.create') }}">{{ __('sites.new') }}</a>
                    @endif
                </div>
            </div>
        @else
            <form method="POST" class="sites-bulk" id="sites-bulk-form" data-ops-bulk>
                @csrf
                <input type="hidden" name="confirmed" value="0">
                <input type="hidden" name="filter_q" value="{{ $search }}">
                <input type="hidden" name="filter_channel" value="{{ $channel }}">
                <input type="hidden" name="filter_status" value="{{ $status }}">
                <input type="hidden" name="filter_publish" value="{{ $publish }}">
                <input type="hidden" name="filter_deploy" value="{{ $deploy ?? '' }}">
                <input type="hidden" name="filter_agent" value="{{ $agent ?? '' }}">
                <input type="hidden" name="filter_pack" value="{{ $pack ?? '' }}">
                <input type="hidden" name="filter_health" value="{{ $health ?? '' }}">
                <input type="hidden" name="filter_app" value="{{ $app ?? '' }}">
            <div class="sites-table-wrap">
                <table class="ops-table">
                    <thead>
                        <tr>
                            @can('create', \App\Models\Site::class)
                                <th class="ops-check-col">
                                    <label class="ops-check-all">
                                        <input type="checkbox" name="all" value="1" data-ops-bulk-all>
                                        <span class="visually-hidden">{{ __('site_ops.bulk.select_all') }}</span>
                                    </label>
                                </th>
                            @endcan
                            @foreach ($listView->columns as $columnKey)
                                @include('ops.sites._sort-header', ['column' => $columnKey])
                            @endforeach
                            <th class="ops-actions-col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sites as $site)
                            @php
                                $reportedVersion = $site->reportedDeamonVersion();
                                $markLetter = $site->identityMarkLetter();
                                $failure = $site->lastFailureMessage();
                                $appHealth = $site->appHealth();
                                $appHealthView = $appHealth->toView($site);
                                // Search reaches alias hosts and Coolify uuids: say why this row is here.
                                $searchMatch = $site->searchMatchReason($search ?? '');
                            @endphp
                            <tr data-href="{{ route('ops.sites.show', $site) }}" data-site-id="{{ $site->id }}" tabindex="0">
                                @can('create', \App\Models\Site::class)
                                    <td>
                                        <label>
                                            <span class="visually-hidden">{{ $site->name }}</span>
                                            <input type="checkbox" name="site_ids[]" value="{{ $site->id }}">
                                        </label>
                                    </td>
                                @endcan
                                @foreach ($listView->columns as $columnKey)
                                    @include('ops.sites._cell', ['column' => $columnKey])
                                @endforeach
                                <td class="ops-row-actions">
                                    @php
                                        $rowFixes = \App\Services\Sites\SiteAppHealthFixer::orderedUniqueFixes($appHealth);
                                    @endphp
                                    @can('update', $site)
                                        <details class="ops-action-menu ops-action-menu-compact" data-ops-action-menu data-row-action>
                                            <summary
                                                class="btn btn-ghost btn-sm"
                                                aria-label="{{ __('sites.app_health.fix_menu', ['name' => $site->name]) }}"
                                            >{{ __('sites.app_health.bulk') }}</summary>
                                            <div class="ops-action-popover" role="menu">
                                                @if ($rowFixes === [])
                                                    <span class="ops-menu-label">{{ __('sites.app_health.no_issues') }}</span>
                                                @else
                                                    @foreach ($rowFixes as $fixKey)
                                                        @if (in_array($fixKey, ['redeploy', 'inject_secret'], true))
                                                            <form
                                                                method="POST"
                                                                action="{{ route('ops.sites.app-health.fix', $site) }}"
                                                                data-ops-pending
                                                                data-app-health-fix
                                                                data-confirm="{{ __('sites.app_health.confirm_fix', ['label' => __('sites.app_health.fixes.'.$fixKey), 'name' => $site->name]) }}"
                                                                data-confirm-title="{{ __('sites.app_health.fixes.'.$fixKey) }}"
                                                                data-confirm-label="{{ __('sites.app_health.fixes.'.$fixKey) }}"
                                                                data-confirm-danger="true"
                                                            >
                                                                @csrf
                                                                <input type="hidden" name="fix" value="{{ $fixKey }}">
                                                                <button type="submit" class="ops-menu-button" role="menuitem" data-pending-label="{{ __('ops.actions.working') }}">
                                                                    {{ __('sites.app_health.fixes.'.$fixKey) }}
                                                                </button>
                                                            </form>
                                                        @else
                                                            <form
                                                                method="POST"
                                                                action="{{ route('ops.sites.app-health.fix', $site) }}"
                                                                data-ops-pending
                                                                data-app-health-fix
                                                            >
                                                                @csrf
                                                                <input type="hidden" name="fix" value="{{ $fixKey }}">
                                                                <button type="submit" class="ops-menu-button" role="menuitem" data-pending-label="{{ __('ops.actions.working') }}">
                                                                    {{ __('sites.app_health.fixes.'.$fixKey) }}
                                                                </button>
                                                            </form>
                                                        @endif
                                                    @endforeach
                                                    <div class="ops-action-sep" role="separator"></div>
                                                    <form
                                                        method="POST"
                                                        action="{{ route('ops.sites.app-health.fix', $site) }}"
                                                        data-ops-pending
                                                        data-app-health-fix
                                                        data-confirm="{{ __('sites.app_health.confirm_fix_all', ['name' => $site->name]) }}"
                                                        data-confirm-title="{{ __('sites.app_health.fix_all') }}"
                                                        data-confirm-label="{{ __('sites.app_health.fix_all') }}"
                                                        data-confirm-danger="true"
                                                    >
                                                        @csrf
                                                        <input type="hidden" name="fix" value="all">
                                                        <button type="submit" class="ops-menu-button" role="menuitem" data-pending-label="{{ __('ops.actions.working') }}">
                                                            {{ __('sites.app_health.fix_all') }}
                                                        </button>
                                                    </form>
                                                @endif
                                            </div>
                                        </details>
                                    @endcan
                                    <a class="btn btn-ghost btn-sm" href="{{ route('ops.sites.show', $site) }}">{{ __('ops.actions.view') }}</a>
                                    @can('update', $site)
                                        <a class="btn btn-ghost btn-sm" href="{{ route('ops.sites.edit', $site) }}">{{ __('ops.actions.edit') }}</a>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
                @can('create', \App\Models\Site::class)
                    {{--
                        The header checkbox posts all=1, which every bulk endpoint reads as
                        "every site matching the current filters", not the 25 rows on screen.
                        That number is only visible here, so the summary carries $sites->total()
                        and the same count is interpolated into every confirm body.
                    --}}
                    <div
                        class="sites-bulk-bar"
                        data-ops-bulk-actions
                        data-bulk-total="{{ $sites->total() }}"
                        data-copy-selected="{{ __('site_ops.bulk.summary_page', ['count' => '__COUNT__']) }}"
                        data-copy-all="{{ __('site_ops.bulk.summary_all', ['total' => $sites->total()]) }}"
                    >
                        <p class="sites-bulk-summary">
                            {{-- Filled by ops-ui.js: without JS there is no selection to count. --}}
                            <strong data-ops-bulk-summary-text aria-live="polite"></strong>
                            <button type="button" class="btn btn-ghost btn-sm" data-ops-bulk-select-all hidden>{{ __('site_ops.bulk.select_all_matching', ['total' => $sites->total()]) }}</button>
                            <button type="button" class="btn btn-ghost btn-sm" data-ops-bulk-select-page hidden>{{ __('site_ops.bulk.select_page_only') }}</button>
                        </p>
                        <div class="form-actions sites-bulk-actions" role="group" aria-label="{{ __('sites.bulk') }}">
                            <label class="ops-bulk-channel">
                                <span class="visually-hidden">{{ __('sites.channel_switch.target') }}</span>
                                <select name="channel" class="field-input ops-filter" data-ops-bulk-channel>
                                    @foreach ($channels as $channelOption)
                                        <option value="{{ $channelOption }}">{{ $channelOption }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <button
                                type="submit"
                                class="btn btn-secondary btn-sm"
                                formaction="{{ route('ops.sites.bulk.channel') }}"
                                data-confirm="{{ __('site_ops.bulk.confirm_branch', ['target' => 'main', 'count' => $sites->total()]) }}"
                                data-confirm-title="{{ __('site_ops.bulk.confirm_branch_title') }}"
                                data-confirm-label="{{ __('site_ops.bulk.change_branch') }}"
                                data-confirm-template="{{ __('site_ops.bulk.confirm_branch', ['target' => '__TARGET__', 'count' => '__COUNT__']) }}"
                                data-confirm-danger="true"
                            >{{ __('site_ops.bulk.change_branch') }}</button>
                            <button
                                type="submit"
                                class="btn btn-secondary btn-sm"
                                formaction="{{ route('ops.sites.bulk.publish-status') }}"
                                name="publish_status"
                                value="published"
                                data-confirm="{{ __('sites.publish.bulk.confirm_publish', ['count' => $sites->total()]) }}"
                                data-confirm-template="{{ __('sites.publish.bulk.confirm_publish', ['count' => '__COUNT__']) }}"
                                data-confirm-title="{{ __('sites.publish.bulk.confirm_title') }}"
                                data-confirm-label="{{ __('sites.publish.bulk.publish') }}"
                                data-confirm-danger="false"
                            >{{ __('sites.publish.bulk.publish') }}</button>
                            @if ($hasDockerfileSites ?? false)
                                <button
                                    type="submit"
                                    class="btn btn-ghost btn-sm"
                                    formaction="{{ route('ops.sites.bulk.compose') }}"
                                    data-confirm="{{ __('site_ops.bulk.confirm_compose', ['count' => $sites->total()]) }}"
                                    data-confirm-template="{{ __('site_ops.bulk.confirm_compose', ['count' => '__COUNT__']) }}"
                                    data-confirm-title="{{ __('site_ops.bulk.confirm_title') }}"
                                    data-confirm-label="{{ __('site_ops.bulk.compose') }}"
                                    data-confirm-danger="false"
                                >{{ __('site_ops.bulk.compose') }}</button>
                            @endif
                            <button
                                type="submit"
                                class="btn btn-ghost btn-sm"
                                formaction="{{ route('ops.sites.bulk.auto-deploy') }}"
                                name="enabled"
                                value="1"
                                data-confirm="{{ __('site_ops.bulk.confirm_auto_on', ['count' => $sites->total()]) }}"
                                data-confirm-template="{{ __('site_ops.bulk.confirm_auto_on', ['count' => '__COUNT__']) }}"
                                data-confirm-title="{{ __('site_ops.auto_deploy.confirm_on_title') }}"
                                data-confirm-label="{{ __('site_ops.bulk.auto_on') }}"
                                data-confirm-danger="false"
                            >{{ __('site_ops.bulk.auto_on') }}</button>
                            <button
                                type="submit"
                                class="btn btn-ghost btn-sm"
                                formaction="{{ route('ops.sites.bulk.auto-deploy') }}"
                                name="enabled"
                                value="0"
                                data-confirm="{{ __('site_ops.bulk.confirm_auto_off', ['count' => $sites->total()]) }}"
                                data-confirm-template="{{ __('site_ops.bulk.confirm_auto_off', ['count' => '__COUNT__']) }}"
                                data-confirm-title="{{ __('site_ops.auto_deploy.confirm_off_title') }}"
                                data-confirm-label="{{ __('site_ops.bulk.auto_off') }}"
                                data-confirm-danger="false"
                            >{{ __('site_ops.bulk.auto_off') }}</button>
                            <button
                                type="submit"
                                class="btn btn-secondary btn-sm"
                                formaction="{{ route('ops.sites.bulk.deploy') }}"
                                data-confirm="{{ __('site_ops.bulk.confirm_redeploy', ['count' => $sites->total()]) }}"
                                data-confirm-template="{{ __('site_ops.bulk.confirm_redeploy', ['count' => '__COUNT__']) }}"
                                data-confirm-title="{{ __('site_ops.redeploy.confirm_title') }}"
                                data-confirm-label="{{ __('site_ops.bulk.redeploy') }}"
                                data-confirm-danger="true"
                            >{{ __('site_ops.bulk.redeploy') }}</button>
                            <button
                                type="submit"
                                class="btn btn-ghost btn-sm"
                                formaction="{{ route('ops.sites.bulk.follow-head') }}"
                                data-confirm="{{ __('site_ops.bulk.confirm_follow', ['count' => $sites->total()]) }}"
                                data-confirm-template="{{ __('site_ops.bulk.confirm_follow', ['count' => '__COUNT__']) }}"
                                data-confirm-title="{{ __('site_ops.pin.confirm_follow_title') }}"
                                data-confirm-label="{{ __('site_ops.bulk.follow_head') }}"
                                data-confirm-danger="true"
                            >{{ __('site_ops.bulk.follow_head') }}</button>
                            <label class="ops-bulk-channel sites-bulk-pin">
                                <span class="visually-hidden">{{ __('site_ops.bulk.ref') }}</span>
                                <input
                                    type="text"
                                    name="ref"
                                    class="field-input ops-filter"
                                    maxlength="64"
                                    autocomplete="off"
                                    spellcheck="false"
                                    placeholder="{{ $sites->hasMorePages() ? __('site_ops.bulk.ref_explicit') : __('site_ops.bulk.ref') }}"
                                    aria-label="{{ __('site_ops.bulk.ref') }}"
                                    @if (($bulkPinCommits ?? collect())->isNotEmpty())
                                        list="sites-bulk-pin-refs"
                                    @endif
                                >
                                @if (($bulkPinCommits ?? collect())->isNotEmpty())
                                    <datalist id="sites-bulk-pin-refs">
                                        @foreach ($bulkPinCommits as $commit)
                                            <option value="{{ $commit->commit_sha }}">{{ $commit->shortSha() }}</option>
                                        @endforeach
                                    </datalist>
                                @endif
                                @if ($sites->hasMorePages())
                                    <span class="sites-bulk-pin-hint muted">{{ __('site_ops.bulk.ref_all_hint') }}</span>
                                @endif
                            </label>
                            <button
                                type="submit"
                                class="btn btn-ghost btn-sm"
                                formaction="{{ route('ops.sites.bulk.pin') }}"
                                data-confirm="{{ __('site_ops.bulk.confirm_pin', ['count' => $sites->total()]) }}"
                                data-confirm-template="{{ __('site_ops.bulk.confirm_pin', ['count' => '__COUNT__']) }}"
                                data-confirm-title="{{ __('site_ops.pin.confirm_title') }}"
                                data-confirm-label="{{ __('site_ops.bulk.pin') }}"
                                data-confirm-danger="true"
                            >{{ __('site_ops.bulk.pin') }}</button>
                            <button
                                type="submit"
                                class="btn btn-ghost btn-sm"
                                formaction="{{ route('ops.sites.bulk.agent-secret') }}"
                                data-confirm="{{ __('sites.agent.bulk_confirm', ['count' => $sites->total()]) }}"
                                data-confirm-template="{{ __('sites.agent.bulk_confirm', ['count' => '__COUNT__']) }}"
                                data-confirm-title="{{ __('sites.agent.inject_title') }}"
                                data-confirm-label="{{ __('sites.agent.bulk') }}"
                                data-confirm-danger="true"
                            >{{ __('sites.agent.bulk') }}</button>
                            {{--
                                Hard delete used to sit in this row, one button away from
                                "Commite geç", with `all=1` making a mis-click fleet-wide.
                                Everything that unpublishes or deletes now needs a menu opened
                                first, and the confirm still names the count from P0-1.
                            --}}
                            <details class="ops-action-menu sites-bulk-danger" data-ops-action-menu>
                                <summary class="btn btn-ghost btn-sm is-danger">{{ __('sites.bulk_danger.trigger') }}</summary>
                                <div class="ops-action-popover" role="menu">
                                    <span class="ops-menu-label">{{ __('sites.bulk_danger.label') }}</span>
                                    <button
                                        type="submit"
                                        class="ops-menu-button is-danger"
                                        role="menuitem"
                                        formaction="{{ route('ops.sites.bulk.publish-status') }}"
                                        name="publish_status"
                                        value="draft"
                                        data-confirm="{{ __('sites.publish.bulk.confirm_unpublish', ['count' => $sites->total()]) }}"
                                        data-confirm-template="{{ __('sites.publish.bulk.confirm_unpublish', ['count' => '__COUNT__']) }}"
                                        data-confirm-title="{{ __('sites.publish.bulk.confirm_title') }}"
                                        data-confirm-label="{{ __('sites.publish.bulk.unpublish') }}"
                                        data-confirm-danger="true"
                                    >{{ __('sites.publish.bulk.unpublish') }}</button>
                                    <div class="ops-action-sep" role="separator"></div>
                                    <button
                                        type="submit"
                                        class="ops-menu-button is-danger"
                                        role="menuitem"
                                        formaction="{{ route('ops.sites.bulk.purge') }}"
                                        data-confirm="{{ __('sites.danger.hard_confirm_bulk', ['count' => $sites->total()]) }}"
                                        data-confirm-template="{{ __('sites.danger.hard_confirm_bulk', ['count' => '__COUNT__']) }}"
                                        data-confirm-title="{{ __('sites.danger.hard_confirm_title') }}"
                                        data-confirm-label="{{ __('sites.menu.hard_delete') }}"
                                        data-confirm-danger="true"
                                    >{{ __('sites.menu.hard_delete') }}</button>
                                </div>
                            </details>
                        </div>
                    </div>
                @endcan
            </form>

            @include('ops.partials.pagination', ['paginator' => $sites, 'label' => __('sites.pagination')])
        @endif
