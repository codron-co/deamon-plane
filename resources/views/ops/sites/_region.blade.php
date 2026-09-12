        @if ($sites->isEmpty() && ! $filtersActive)
            <div class="empty-panel">
                <h2>{{ __('sites.empty.title') }}</h2>
                <p>{{ __('sites.empty.hint') }}</p>
                @if ($canCreate)
                    <a class="btn btn-primary" href="{{ route('ops.sites.create') }}">{{ __('sites.new') }}</a>
                @endif
            </div>
        @elseif ($sites->isEmpty())
            <div class="empty-panel">
                <h2>{{ __('sites.empty.filtered_title') }}</h2>
                <p>{{ __('sites.empty.filtered_hint') }}</p>
                <a class="btn btn-ghost" href="{{ route('ops.sites') }}">{{ __('ops.actions.clear_filters') }}</a>
            </div>
        @else
            <form method="POST" class="sites-bulk" id="sites-bulk-form" data-ops-bulk>
                @csrf
                <input type="hidden" name="confirmed" value="0">
                <input type="hidden" name="filter_q" value="{{ $search }}">
                <input type="hidden" name="filter_channel" value="{{ $channel }}">
                <input type="hidden" name="filter_status" value="{{ $status }}">
                <input type="hidden" name="filter_publish" value="{{ $publish }}">
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
                                                                data-confirm="{{ __('sites.app_health.fixes.'.$fixKey) }} — {{ $site->name }}?"
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
                    <div class="form-actions sites-bulk-actions" data-ops-bulk-actions role="group" aria-label="{{ __('sites.bulk') }}">
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
                            data-confirm="{{ __('site_ops.bulk.confirm_branch', ['target' => 'main']) }}"
                            data-confirm-title="{{ __('site_ops.bulk.confirm_branch_title') }}"
                            data-confirm-label="{{ __('site_ops.bulk.change_branch') }}"
                            data-confirm-template="{{ __('site_ops.bulk.confirm_branch', ['target' => '__TARGET__']) }}"
                            data-confirm-danger="false"
                        >{{ __('site_ops.bulk.change_branch') }}</button>
                        <button
                            type="submit"
                            class="btn btn-secondary btn-sm"
                            formaction="{{ route('ops.sites.bulk.publish-status') }}"
                            name="publish_status"
                            value="published"
                            data-confirm="{{ __('sites.publish.bulk.confirm_publish') }}"
                            data-confirm-title="{{ __('sites.publish.bulk.confirm_title') }}"
                            data-confirm-label="{{ __('sites.publish.bulk.publish') }}"
                            data-confirm-danger="false"
                        >{{ __('sites.publish.bulk.publish') }}</button>
                        <button
                            type="submit"
                            class="btn btn-ghost btn-sm"
                            formaction="{{ route('ops.sites.bulk.publish-status') }}"
                            name="publish_status"
                            value="draft"
                            data-confirm="{{ __('sites.publish.bulk.confirm_unpublish') }}"
                            data-confirm-title="{{ __('sites.publish.bulk.confirm_title') }}"
                            data-confirm-label="{{ __('sites.publish.bulk.unpublish') }}"
                            data-confirm-danger="true"
                        >{{ __('sites.publish.bulk.unpublish') }}</button>
                        @if ($hasDockerfileSites ?? false)
                            <button
                                type="submit"
                                class="btn btn-ghost btn-sm"
                                formaction="{{ route('ops.sites.bulk.compose') }}"
                                data-confirm="{{ __('site_ops.bulk.confirm_compose') }}"
                                data-confirm-title="{{ __('site_ops.bulk.confirm_title') }}"
                                data-confirm-label="{{ __('site_ops.bulk.compose') }}"
                            >{{ __('site_ops.bulk.compose') }}</button>
                        @endif
                        <button
                            type="submit"
                            class="btn btn-ghost btn-sm"
                            formaction="{{ route('ops.sites.bulk.auto-deploy') }}"
                            data-confirm="{{ __('site_ops.bulk.confirm_auto_toggle') }}"
                            data-confirm-title="{{ __('site_ops.bulk.confirm_auto_toggle_title') }}"
                            data-confirm-label="{{ __('site_ops.bulk.auto_toggle') }}"
                            data-confirm-danger="false"
                        >{{ __('site_ops.bulk.auto_toggle') }}</button>
                        <button
                            type="submit"
                            class="btn btn-secondary btn-sm"
                            formaction="{{ route('ops.sites.bulk.deploy') }}"
                            data-confirm="{{ __('site_ops.bulk.confirm_redeploy') }}"
                            data-confirm-title="{{ __('site_ops.redeploy.confirm_title') }}"
                            data-confirm-label="{{ __('site_ops.bulk.redeploy') }}"
                            data-confirm-danger="false"
                        >{{ __('site_ops.bulk.redeploy') }}</button>
                        <button
                            type="submit"
                            class="btn btn-ghost btn-sm"
                            formaction="{{ route('ops.sites.bulk.follow-head') }}"
                            data-confirm="{{ __('site_ops.bulk.confirm_follow') }}"
                            data-confirm-title="{{ __('site_ops.pin.confirm_follow_title') }}"
                            data-confirm-label="{{ __('site_ops.bulk.follow_head') }}"
                            data-confirm-danger="false"
                        >{{ __('site_ops.bulk.follow_head') }}</button>
                        <label class="ops-bulk-channel">
                            <span class="visually-hidden">{{ __('site_ops.bulk.ref') }}</span>
                            @if (($bulkPinCommits ?? collect())->isNotEmpty())
                                <select name="ref" class="field-input ops-filter" aria-label="{{ __('site_ops.bulk.ref') }}">
                                    <option value="">{{ __('site_ops.bulk.ref') }}</option>
                                    @foreach ($bulkPinCommits as $commit)
                                        <option value="{{ $commit->commit_sha }}">{{ $commit->shortSha() }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input type="text" name="ref" class="field-input ops-filter" maxlength="64" autocomplete="off" spellcheck="false" placeholder="{{ __('site_ops.bulk.ref') }}" aria-label="{{ __('site_ops.bulk.ref') }}">
                            @endif
                        </label>
                        <button
                            type="submit"
                            class="btn btn-ghost btn-sm"
                            formaction="{{ route('ops.sites.bulk.pin') }}"
                            data-confirm="{{ __('site_ops.bulk.confirm_pin') }}"
                            data-confirm-title="{{ __('site_ops.pin.confirm_title') }}"
                            data-confirm-label="{{ __('site_ops.bulk.pin') }}"
                        >{{ __('site_ops.bulk.pin') }}</button>
                        <button
                            type="submit"
                            class="btn btn-danger btn-sm"
                            formaction="{{ route('ops.sites.bulk.purge') }}"
                            data-confirm="{{ __('sites.danger.hard_confirm_bulk') }}"
                            data-confirm-title="{{ __('sites.danger.hard_confirm_title') }}"
                            data-confirm-label="{{ __('sites.menu.hard_delete') }}"
                        >{{ __('sites.menu.hard_delete') }}</button>
                    </div>
                @endcan
            </form>

            @include('ops.partials.pagination', ['paginator' => $sites, 'label' => __('sites.pagination')])
        @endif
