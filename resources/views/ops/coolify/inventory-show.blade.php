@extends('layouts.ops')

@section('title', $title)

@section('content_class', 'ops-content-wide')

@section('breadcrumbs')
    <a href="{{ route('ops.coolify.index') }}">{{ __('coolify.title') }}</a>
    <span aria-hidden="true">/</span>
    <a href="{{ route('ops.coolify.show', $connection) }}">{{ $connection->name }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ $title }}</span>
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.coolify.show', $connection) }}">{{ __('coolify.detail.back_to_connection') }}</a>
    @if ($canWrite)
        <form method="POST" action="{{ route($toggleRoute, ['connection' => $connection, $toggleParam => $record]) }}">
            @csrf
            <button type="submit" class="btn btn-secondary btn-sm">
                {{ $statusActive ? __('coolify.allowlist.deactivate') : __('coolify.allowlist.activate') }}
            </button>
        </form>
    @endif
@endsection

@section('content')
    @php
        $uuidLabel = __('coolify.detail.uuid');
        $summaryFacts = collect($facts)->reject(static fn (array $fact): bool => ($fact['label'] ?? '') === $uuidLabel);
        $uuidFacts = collect($facts)->filter(static fn (array $fact): bool => ($fact['label'] ?? '') === $uuidLabel);
        $firstSite = $sites->first();
        $hasEnvironments = $environments->isNotEmpty();
    @endphp

    <header class="site-hero">
        <div class="site-hero-main">
            <div class="site-identity-mark" aria-hidden="true">{{ \App\Support\IdentityMark::letter($title) }}</div>
            <div class="site-identity-copy">
                <div class="site-title-row">
                    <h2>{{ $title }}</h2>
                    <span class="status-chip status-{{ $statusActive ? 'active' : 'error' }}">
                        {{ $statusActive ? __('ops.active') : __('ops.inactive') }}
                    </span>
                    @if ($isDefault)
                        <span class="ops-chip">{{ __('ops.default') }}</span>
                    @endif
                </div>
                <div class="site-domain-row">
                    <span>{{ $kindLabel }}</span>
                    <span aria-hidden="true">·</span>
                    <a href="{{ route('ops.coolify.show', $connection) }}">{{ $connection->name }}</a>
                </div>
            </div>
        </div>
    </header>

    <nav class="site-section-nav" aria-label="{{ __('coolify.tabs.detail_aria') }}" role="tablist" data-site-tabs>
        <a class="is-active" href="#overview" role="tab" aria-selected="true" aria-controls="overview">{{ __('coolify.tabs.overview') }}</a>
        @if ($hasEnvironments)
            <a href="#environments" role="tab" aria-selected="false" aria-controls="environments">{{ __('coolify.tabs.environments') }}</a>
        @endif
        <a href="#sites" role="tab" aria-selected="false" aria-controls="sites">{{ __('coolify.tabs.sites') }}</a>
    </nav>

    <section id="overview" class="site-section" role="tabpanel" data-site-panel aria-labelledby="inventory-overview-heading">
        <div class="site-section-heading">
            <div>
                <span class="site-section-kicker">{{ __('coolify.detail.operation') }}</span>
                <h2 id="inventory-overview-heading">{{ __('coolify.tabs.overview') }} @include('ops.coolify._hint', ['text' => __('coolify.detail.lede', ['kind' => $kindLabel, 'name' => $connection->name])])</h2>
            </div>
        </div>

        <div class="site-overview-grid">
            <article class="site-card site-release-card">
                <div class="site-card-head">
                    <div>
                        <span class="site-section-kicker">{{ $kindLabel }}</span>
                        <h3>{{ $title }}</h3>
                    </div>
                </div>
                <dl class="site-fact-list">
                    <div>
                        <dt>{{ __('coolify.allowlist.status') }}</dt>
                        <dd>
                            <span class="status-chip status-{{ $statusActive ? 'active' : 'error' }}">
                                {{ $statusActive ? __('ops.active') : __('ops.inactive') }}
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt>{{ __('coolify.detail.type') }}</dt>
                        <dd>{{ $kindLabel }}</dd>
                    </div>
                    @foreach ($summaryFacts as $fact)
                        <div>
                            <dt>{{ $fact['label'] }}</dt>
                            <dd>
                                @if (! empty($fact['href']))
                                    <a href="{{ $fact['href'] }}">{{ $fact['value'] }}</a>
                                @elseif (! empty($fact['code']))
                                    <code>{{ $fact['value'] }}</code>
                                @else
                                    {{ $fact['value'] }}
                                @endif
                            </dd>
                        </div>
                    @endforeach
                    <div>
                        <dt>{{ __('coolify.detail.linked_sites') }}</dt>
                        <dd>{{ $sites->count() }}</dd>
                    </div>
                </dl>
            </article>

            <aside class="site-card site-next-action">
                <span class="site-section-kicker">{{ __('coolify.next.kicker') }}</span>
                @if ($canWrite && ! $statusActive)
                    <h3>{{ __('coolify.detail.next_activate') }}</h3>
                    <p>{{ __('coolify.detail.next_activate_hint') }}</p>
                    <form method="POST" action="{{ route($toggleRoute, ['connection' => $connection, $toggleParam => $record]) }}">
                        @csrf
                        <button type="submit" class="btn btn-primary btn-sm">{{ __('coolify.allowlist.activate') }}</button>
                    </form>
                @elseif ($firstSite)
                    <h3>{{ __('coolify.detail.next_open_site') }}</h3>
                    <p>{{ __('coolify.detail.next_open_site_hint') }}</p>
                    <a class="btn btn-primary btn-sm" href="{{ route('ops.sites.show', $firstSite) }}">{{ $firstSite->name }}</a>
                @else
                    <h3>{{ __('coolify.detail.next_none') }}</h3>
                    <p>{{ __('coolify.detail.next_none_hint') }}</p>
                    <a class="btn btn-ghost btn-sm" href="{{ route('ops.coolify.show', $connection) }}">{{ __('coolify.detail.back_to_connection') }}</a>
                @endif
            </aside>
        </div>

        @if ($uuidFacts->isNotEmpty())
            <details class="site-technical-card">
                <summary>
                    <span>
                        <strong>{{ __('coolify.technical.title') }}</strong>
                        <small>{{ __('coolify.technical.inventory_hint') }}</small>
                    </span>
                    <span class="site-disclosure-icon" aria-hidden="true"></span>
                </summary>
                <dl class="site-technical-list">
                    @foreach ($uuidFacts as $fact)
                        <div>
                            <dt>{{ $fact['label'] }}</dt>
                            <dd><code>{{ $fact['value'] }}</code></dd>
                        </div>
                    @endforeach
                </dl>
            </details>
        @endif
    </section>

    @if ($hasEnvironments)
        <section id="environments" class="site-section" role="tabpanel" data-site-panel aria-labelledby="inventory-environments-heading">
            <div class="site-section-heading">
                <div>
                    <span class="site-section-kicker">{{ __('coolify.allowlist.environments') }}</span>
                    <h2 id="inventory-environments-heading">{{ __('coolify.allowlist.environments') }} @include('ops.coolify._hint', ['text' => __('coolify.allowlist.environments_hint')])</h2>
                </div>
            </div>
            <div class="sites-table-wrap">
                <table class="ops-table">
                    <thead>
                        <tr>
                            <th>{{ __('coolify.allowlist.name') }}</th>
                            <th>{{ __('coolify.allowlist.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($environments as $environment)
                            <tr data-href="{{ route('ops.coolify.environments.show', [$connection, $environment]) }}" tabindex="0">
                                <td>
                                    <a class="site-name" href="{{ route('ops.coolify.environments.show', [$connection, $environment]) }}">{{ $environment->label() }}</a>
                                </td>
                                <td>
                                    <span class="status-chip status-{{ $environment->is_active ? 'active' : 'error' }}">
                                        {{ $environment->is_active ? __('ops.active') : __('ops.inactive') }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section id="sites" class="site-section" role="tabpanel" data-site-panel aria-labelledby="inventory-sites-heading">
        <div class="site-section-heading">
            <div>
                <span class="site-section-kicker">{{ __('coolify.detail.linked_sites') }}</span>
                <h2 id="inventory-sites-heading">{{ __('coolify.detail.linked_sites') }} @include('ops.coolify._hint', ['text' => __('coolify.detail.next_open_site_hint')])</h2>
            </div>
        </div>
        @if ($sites->isEmpty())
            <div class="empty-panel">
                <h2>{{ __('coolify.detail.no_sites') }}</h2>
            </div>
        @else
            <div class="sites-table-wrap">
                <table class="ops-table">
                    <thead>
                        <tr>
                            <th>{{ __('sites.columns.site') }}</th>
                            <th>{{ __('sites.columns.domain') }}</th>
                            <th>{{ __('sites.columns.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sites as $site)
                            <tr data-href="{{ route('ops.sites.show', $site) }}" tabindex="0">
                                <td>
                                    <a class="site-name" href="{{ route('ops.sites.show', $site) }}">{{ $site->name }}</a>
                                    <div class="site-slug">{{ $site->slug }}</div>
                                </td>
                                <td class="muted">{{ $site->primary_domain ?: __('ops.none') }}</td>
                                <td>
                                    <span class="status-chip status-{{ $site->status?->value }}">{{ $site->status?->label() ?? __('ops.unknown') }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection

@section('scripts')
    @include('ops.coolify._resource-tabs')
@endsection
