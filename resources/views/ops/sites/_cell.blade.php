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
            <div class="branch-version" aria-label="{{ __('sites.columns.repo_branch') }}">
                <span class="branch-chip">{{ $site->channel->value }}</span>
                <span class="version-chip">{{ $reportedVersion ?: __('sites.version_unknown') }}</span>
            </div>
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
                @if ($site->publishStatus() === null)
                    <x-ops.freshness :at="null" :missing="__('ops.unknown')" />
                @else
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
            <button
                type="button"
                class="status-chip status-{{ $appHealthView['tone'] === 'ok' ? 'ok' : ($appHealthView['tone'] === 'error' ? 'error' : 'unknown') }}"
                data-app-health-copy
                data-row-action
                data-copy-text="{{ $appHealthView['copy_text'] }}"
                title="{{ $appHealthView['copy_text'] }}"
                aria-label="{{ __('sites.app_health.copy_named', ['name' => $site->name]) }}"
            >{{ $appHealthView['label'] }}</button>
        </td>
        @break

    @case ('live')
        <td>
            <div class="ops-fresh-cell">
                <span class="status-chip status-{{ $site->liveHttpTone() }}" data-live-chip>{{ $site->liveHttpLabel() }}</span>
                <x-ops.freshness :at="$site->last_live_checked_at" />
            </div>
        </td>
        @break

    @case ('theme')
        <td class="muted">{{ $site->reportedActiveThemeId() ?: __('ops.none') }}</td>
        @break

    @case ('health')
        <td><x-ops.freshness :at="$site->last_health_at" /></td>
        @break

    @case ('updated')
        <td><x-ops.freshness :at="$site->updated_at" :missing="__('ops.none')" :mark-stale="false" /></td>
        @break
@endswitch
