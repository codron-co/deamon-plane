@php
    /** @var \App\Models\Site $site */
    /** @var string $column */
@endphp

@switch ($column)
    @case ('site')
        <td>
            <div class="site-name-row">
                <div
                    class="site-identity-mark is-compact"
                    aria-hidden="true"
                    @if (filled($site->primary_domain))
                        data-favicon-host="{{ $site->primary_domain }}"
                        data-favicon-fallback="{{ $markLetter }}"
                        @if (filled($site->last_live_favicon_url))
                            data-favicon-src="{{ $site->last_live_favicon_url }}"
                        @endif
                    @endif
                >{{ $markLetter }}</div>
                <div>
                    <div class="site-name-row">
                        <a class="site-name" href="{{ route('ops.sites.show', $site) }}">{{ $site->name }}</a>
                        @if ($site->hasDockerfileBuildPackWarning())
                            <span class="status-chip status-dockerfile">{{ __('ops.dockerfile_chip') }}</span>
                        @endif
                    </div>
                    <div class="site-slug">{{ $site->slug }}</div>
                    @if (($searchMatch ?? null) !== null)
                        <div class="site-search-match">{{ __('sites.search_match.'.$searchMatch['type'], ['value' => $searchMatch['value']]) }}</div>
                    @endif
                </div>
            </div>
        </td>
        @break

    @case ('domain')
        <td>
            @if (filled($site->primary_domain))
                <a
                    class="ops-domain-link"
                    href="https://{{ $site->primary_domain }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label="{{ __('sites.columns.open_live', ['domain' => $site->primary_domain]) }}"
                ><code>{{ $site->primary_domain }}</code></a>
            @else
                <span class="muted">{{ __('ops.none') }}</span>
            @endif
        </td>
        @break

    @case ('repo_branch')
        <td>
            {{-- Branch and the version it runs read as one fact, so they share one pill. --}}
            <span
                class="plane-ref @if (! $reportedVersion) is-unknown @endif"
                title="{{ __('sites.repo_branch_version', ['branch' => $site->channel->value, 'version' => $reportedVersion ?: __('sites.version_unknown')]) }}"
            >
                <span class="plane-ref-branch branch-chip">
                    <svg viewBox="0 0 16 16" width="11" height="11" aria-hidden="true"><circle cx="5" cy="3.5" r="1.5" fill="none" stroke="currentColor" stroke-width="1.3"/><circle cx="5" cy="12.5" r="1.5" fill="none" stroke="currentColor" stroke-width="1.3"/><circle cx="11" cy="5.5" r="1.5" fill="none" stroke="currentColor" stroke-width="1.3"/><path d="M5 5v6M11 7c0 2.5-2.5 3-6 4" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
                    {{ $site->channel->value }}
                </span>
                <span class="plane-ref-version version-chip">{{ $reportedVersion ?: __('sites.version_unknown') }}</span>
            </span>
        </td>
        @break

    @case ('publish')
        <td>
            <div class="ops-fresh-cell">
                <span
                    class="status-chip status-{{ $site->publishTone() }}"
                    data-publish-chip
                    @if ($site->publishStatus() === null)
                        title="{{ __('sites.publish.unknown_hint') }}"
                    @endif
                >{{ $site->publishLabel() }}</span>
                {{-- An unknown state has no age worth showing; the chip already says "unknown". --}}
                @if ($site->publishStatus() !== null)
                    <x-ops.freshness :at="$site->cms_site_status_at" :missing="__('ops.unknown')" />
                @endif
            </div>
        </td>
        @break

    @case ('status')
        <td>
            <span class="status-chip status-{{ $site->status->value }}" @if (filled($failure)) title="{{ $failure }}" @endif>{{ $site->status->label() }}</span>
        </td>
        @break

    @case ('app')
        <td>
            @php
                $appTone = $appHealthView['tone'] === 'ok' ? 'ok' : ($appHealthView['tone'] === 'error' ? 'error' : 'unknown');
                $appPopId = 'app-health-pop-'.$site->id;
            @endphp
            @if ($appHealthView['issues'] === [])
                <button
                    type="button"
                    class="status-chip status-{{ $appTone }}"
                    data-app-health-copy
                    data-row-action
                    data-copy-text="{{ $appHealthView['copy_text'] }}"
                    title="{{ $appHealthView['copy_text'] }}"
                    aria-label="{{ __('sites.app_health.copy_named', ['name' => $site->name]) }}"
                >{{ $appHealthView['label'] }}</button>
            @else
                {{-- The chip lists the issues; copying is one action inside, not the whole point. --}}
                <div class="app-health-pop" data-app-health-pop data-row-action>
                    <button
                        type="button"
                        class="status-chip status-{{ $appTone }} app-health-pop-trigger"
                        data-app-health-trigger
                        aria-haspopup="dialog"
                        aria-expanded="false"
                        aria-controls="{{ $appPopId }}"
                        aria-label="{{ __('sites.app_health.issues_named', ['name' => $site->name, 'label' => $appHealthView['label']]) }}"
                    >{{ $appHealthView['label'] }}</button>
                    <div
                        class="app-health-popover"
                        id="{{ $appPopId }}"
                        role="dialog"
                        aria-label="{{ __('sites.app_health.issues_title', ['name' => $site->name]) }}"
                        data-app-health-popover
                        hidden
                    >
                        <p class="app-health-popover-title">{{ __('sites.app_health.issues_title', ['name' => $site->name]) }}</p>
                        <ul class="app-health-popover-list" data-app-health-pop-list>
                            @foreach ($appHealthView['issues'] as $issue)
                                <li>{{ $issue['message'] }}</li>
                            @endforeach
                        </ul>
                        <div class="app-health-popover-actions">
                            <button
                                type="button"
                                class="btn btn-ghost btn-sm"
                                data-app-health-copy
                                data-copy-text="{{ $appHealthView['copy_text'] }}"
                                aria-label="{{ __('sites.app_health.copy_named', ['name' => $site->name]) }}"
                            >{{ __('sites.app_health.copy') }}</button>
                        </div>
                    </div>
                </div>
            @endif
        </td>
        @break

    @case ('live')
        <td>
            @if ($site->last_live_checked_at === null)
                {{-- Never probed: one quiet state instead of an empty chip plus "never". --}}
                <span class="plane-muted-state" data-live-chip data-live-unchecked>{{ __('sites.live.not_checked') }}</span>
            @else
                <div class="ops-fresh-cell">
                    <span class="status-chip status-{{ $site->liveHttpTone() }}" data-live-chip>{{ $site->liveHttpLabel() }}</span>
                    <x-ops.freshness :at="$site->last_live_checked_at" />
                </div>
            @endif
        </td>
        @break

    @case ('theme')
        <td>
            @php
                $themeLabel = $site->reportedActiveThemeId();
                $themeIsGit = $site->activeThemeInstallation !== null;
                $themeVersion = $themeIsGit ? $site->activeThemeVersionLabel() : null;
            @endphp
            @if ($themeLabel === null)
                <span class="muted">{{ __('ops.none') }}</span>
            @elseif ($themeIsGit)
                <span class="plane-theme is-git" title="{{ __('sites.theme_git') }}">
                    @include('ops.sites._git-icon')
                    <span class="plane-theme-name">{{ $site->activeThemeInstallation->theme?->displayName() ?? $themeLabel }}</span>
                    @if ($themeVersion !== null)
                        <span class="plane-theme-version">{{ $themeVersion }}</span>
                    @endif
                    @if ($site->hasThemeUpdate())
                        <span class="plane-theme-update" title="{{ __('sites.theme_update_available') }}">
                            <span class="visually-hidden">{{ __('sites.theme_update_available') }}</span>
                        </span>
                    @endif
                </span>
            @else
                <span class="plane-theme" title="{{ __('sites.theme_reported_hint') }}">
                    <span class="plane-theme-name">{{ $themeLabel }}</span>
                </span>
            @endif
        </td>
        @break

    @case ('last_deploy')
        <td>
            @php
                /** @var \App\Models\Deployment|null $deployment */
                $deployment = $site->latestDeployment;
            @endphp
            @if ($deployment === null)
                <span class="muted">{{ __('sites.cells.deploy_none') }}</span>
            @else
                @php
                    $deployFailure = $deployment->status === \App\Enums\DeploymentStatus::Failed ? $deployment->failureHeadline() : null;
                    $deploySha = $deployment->shortSha();
                @endphp
                <div class="ops-fresh-cell plane-deploy-cell">
                    <span
                        class="status-chip status-{{ $deployment->status->value }}"
                        data-last-deploy-chip
                        @if (filled($deployFailure)) title="{{ $deployFailure }}" @endif
                    >{{ $deployment->status->label() }}</span>
                    <x-ops.freshness :at="$deployment->finished_at ?? $deployment->started_at ?? $deployment->created_at" :mark-stale="false" />
                    @if ($deploySha !== '')
                        <code class="plane-sha" title="{{ __('sites.cells.deploy_commit', ['sha' => substr((string) $deployment->commit_sha, 0, 12)]) }}">{{ $deploySha }}</code>
                    @endif
                </div>
            @endif
        </td>
        @break

    @case ('server')
        <td>
            @php
                /** @var \App\Models\CoolifyServer|null $coolifyServer */
                $coolifyServer = $site->relationLoaded('coolifyServer') ? $site->getRelation('coolifyServer') : null;
                $serverUuid = trim((string) $site->coolify_server_uuid);
            @endphp
            @if ($coolifyServer !== null)
                <span
                    class="plane-server"
                    title="{{ __('sites.cells.server_title', ['connection' => $coolifyServer->connection?->name ?? __('ops.none'), 'uuid' => $coolifyServer->uuid]) }}"
                >{{ $coolifyServer->label() }}</span>
            @elseif ($serverUuid !== '')
                <code class="plane-sha is-muted" title="{{ __('sites.cells.server_missing', ['uuid' => $serverUuid]) }}">{{ \Illuminate\Support\Str::limit($serverUuid, 8, '…') }}</code>
            @else
                <span class="muted">{{ __('ops.none') }}</span>
            @endif
        </td>
        @break

    @case ('auto_deploy')
        <td>
            @if ($site->coolify_deploy_settings_at === null || $site->coolify_auto_deploy === null)
                <span class="plane-muted-state" title="{{ __('sites.cells.auto_unknown_hint') }}">{{ __('sites.cells.auto_unknown') }}</span>
            @else
                @php
                    $autoAsOf = __('sites.cells.auto_as_of', ['time' => $site->coolify_deploy_settings_at->diffForHumans()]);
                    $pinnedSha = trim((string) $site->coolify_pinned_sha);
                @endphp
                <div class="ops-fresh-cell">
                    <span
                        class="status-chip status-{{ $site->coolify_auto_deploy ? 'ok' : 'unknown' }}"
                        data-auto-deploy-chip
                        title="{{ $autoAsOf }}"
                    >{{ $site->coolify_auto_deploy ? __('sites.cells.auto_on') : __('sites.cells.auto_off') }}</span>
                    @if ($pinnedSha !== '')
                        <code class="plane-sha is-pinned" title="{{ __('sites.cells.pinned', ['sha' => $pinnedSha]) }}">
                            <svg viewBox="0 0 16 16" width="10" height="10" aria-hidden="true"><path d="M9.5 2.5l4 4-2 1-2.5 2.5.5 3-1 1-3-3-3.5 3.5M5.5 9.5l-3-3 1-1 3 .5L9 3.5z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>
                            <span class="visually-hidden">{{ __('sites.cells.pinned', ['sha' => '']) }}</span>{{ substr($pinnedSha, 0, 7) }}
                        </code>
                    @endif
                </div>
            @endif
        </td>
        @break

    @case ('mail')
        <td>
            @php
                $mailServerName = $site->relationLoaded('mailServer') ? $site->mailServer?->name : null;
                $mailBindingCount = (int) ($site->mail_bindings_count ?? 0);
            @endphp
            @if ($mailServerName === null && $mailBindingCount === 0)
                <span class="muted">{{ __('ops.none') }}</span>
            @else
                <span class="plane-ref @if ($mailBindingCount === 0) is-unknown @endif" data-mail-cell>
                    @if ($mailServerName !== null)
                        <span class="plane-ref-branch">{{ $mailServerName }}</span>
                    @endif
                    <span class="plane-ref-version">{{ $mailBindingCount === 0
                        ? __('sites.cells.mail_unbound')
                        : trans_choice('sites.cells.mail_domains', $mailBindingCount, ['count' => $mailBindingCount]) }}</span>
                </span>
            @endif
        </td>
        @break

    @case ('health')
        <td><x-ops.freshness :at="$site->last_health_at" /></td>
        @break

    @case ('updated')
        <td><x-ops.freshness :at="$site->updated_at" :missing="__('ops.none')" :mark-stale="false" /></td>
        @break
@endswitch
