@extends('layouts.ops')

@section('title', $zone['name'] ?? __('cloudflare.zones.title'))

@section('content_class', 'ops-content-wide')

@section('breadcrumbs')
    <a href="{{ route('ops.cloudflare.index') }}">{{ __('cloudflare.title') }}</a>
    <span aria-hidden="true">/</span>
    <a href="{{ route('ops.cloudflare.show', $account) }}">{{ $account->name }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ $zone['name'] ?? $zone['id'] }}</span>
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.cloudflare.show', $account) }}">{{ __('cloudflare.back') }}</a>
    @if ($canWrite)
        <form method="POST" action="{{ route('ops.cloudflare.zones.apply', ['account' => $account, 'zone' => $zone['id']]) }}" data-ops-pending data-confirm="{{ __('cloudflare.zones.apply_confirm', ['domain' => $zone['name'] ?? $zone['id']]) }}" data-confirm-title="{{ __('cloudflare.zones.apply_title') }}" data-confirm-label="{{ __('cloudflare.zones.apply') }}" data-confirm-danger="false">
            @csrf
            <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('cloudflare.zones.apply') }}</button>
        </form>
    @endif
@endsection

@section('content')
    @php
        $zoneName = $zone['name'] ?? $zone['id'];
        $zoneStatus = $zone['status'] ?? __('ops.unknown');
        $recordCount = is_countable($records) ? count($records) : 0;
        $dnsErrors = $errors->hasAny(['type', 'name', 'content', 'ttl', 'priority']);
        $initialTab = $dnsErrors ? 'dns' : '';
        $statusActive = ($zone['status'] ?? '') === 'active';
    @endphp

    <header class="site-hero">
        <div class="site-hero-main">
            <div class="site-identity-mark" aria-hidden="true">{{ strtoupper(substr((string) $zoneName, 0, 1)) }}</div>
            <div class="site-identity-copy">
                <div class="site-title-row">
                    <h2>{{ $zoneName }}</h2>
                    <span class="status-chip status-{{ $statusActive ? 'active' : 'pending' }}">{{ $zoneStatus }}</span>
                </div>
                <div class="site-domain-row">
                    <a href="{{ route('ops.cloudflare.show', $account) }}">{{ $account->name }}</a>
                </div>
            </div>
        </div>
    </header>

    <nav class="site-section-nav" aria-label="{{ __('cloudflare.tabs.zone_aria') }}" role="tablist" data-site-tabs data-initial-tab="{{ $initialTab }}">
        <a class="is-active" href="#overview" role="tab" aria-selected="true" aria-controls="overview">{{ __('cloudflare.tabs.overview') }}</a>
        <a href="#dns" role="tab" aria-selected="false" aria-controls="dns">{{ __('cloudflare.tabs.dns') }}</a>
        @if ($canWrite)
            <a href="#danger" role="tab" aria-selected="false" aria-controls="danger">{{ __('cloudflare.tabs.danger') }}</a>
        @endif
    </nav>

    @if ($recordsError)
        <p class="ops-alert site-banner" role="alert">{{ $recordsError }}</p>
    @endif

    <section id="overview" class="site-section" role="tabpanel" data-site-panel aria-labelledby="cf-zone-overview-heading">
        <div class="site-section-heading">
            <div>
                <span class="site-section-kicker">{{ __('cloudflare.zone_kicker') }}</span>
                <h2 id="cf-zone-overview-heading">{{ __('cloudflare.tabs.overview') }} @include('ops.cloudflare._hint', ['text' => __('cloudflare.zone.lede')])</h2>
            </div>
        </div>

        <div class="site-metric-grid">
            <article class="site-metric">
                <div class="site-metric-icon is-{{ $statusActive ? 'ok' : 'connection' }}" aria-hidden="true"><span></span></div>
                <div>
                    <span>{{ __('cloudflare.facts.status') }}</span>
                    <strong>{{ $zoneStatus }}</strong>
                    <small>{{ $zoneName }}</small>
                </div>
            </article>
            <article class="site-metric">
                <div class="site-metric-icon is-theme" aria-hidden="true"><span></span></div>
                <div>
                    <span>{{ __('cloudflare.metrics.records') }}</span>
                    <strong>{{ $recordCount }}</strong>
                    <small>{{ __('cloudflare.tabs.dns') }}</small>
                </div>
            </article>
            <article class="site-metric">
                <div class="site-metric-icon is-connection" aria-hidden="true"><span></span></div>
                <div>
                    <span>{{ __('cloudflare.metrics.nameservers') }}</span>
                    <strong>{{ $nameservers === [] ? __('ops.none') : count($nameservers) }}</strong>
                    <small>{{ __('cloudflare.zones.ns') }}</small>
                </div>
            </article>
        </div>

        <div class="site-overview-grid">
            <article class="site-card site-release-card">
                <div class="site-card-head">
                    <div>
                        <span class="site-section-kicker">{{ __('cloudflare.zones.ns') }}</span>
                        <h3>{{ $zoneName }}</h3>
                    </div>
                </div>
                @if ($nameservers !== [])
                    <p class="field-hint">{{ __('cloudflare.zones.ns_hint') }}</p>
                    <ul class="ops-checklist">
                        @foreach ($nameservers as $ns)
                            <li><code>{{ $ns }}</code></li>
                        @endforeach
                    </ul>
                @else
                    <p class="muted">{{ __('ops.none') }}</p>
                @endif
            </article>

            <aside class="site-card site-next-action">
                <span class="site-section-kicker">{{ __('cloudflare.next.kicker') }}</span>
                @if ($nameservers !== [] && ! $statusActive)
                    <h3>{{ __('cloudflare.next.ns_title') }}</h3>
                    <p>{{ __('cloudflare.next.ns_hint') }}</p>
                @elseif ($canWrite)
                    <h3>{{ __('cloudflare.next.apply_title') }}</h3>
                    <p>{{ __('cloudflare.next.apply_hint') }}</p>
                    <form method="POST" action="{{ route('ops.cloudflare.zones.apply', ['account' => $account, 'zone' => $zone['id']]) }}" data-ops-pending data-confirm="{{ __('cloudflare.zones.apply_confirm', ['domain' => $zoneName]) }}" data-confirm-title="{{ __('cloudflare.zones.apply_title') }}" data-confirm-label="{{ __('cloudflare.zones.apply') }}" data-confirm-danger="false">
                        @csrf
                        <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('cloudflare.zones.apply') }}</button>
                    </form>
                @else
                    <h3>{{ __('cloudflare.next.dns_title') }}</h3>
                    <p>{{ __('cloudflare.next.dns_hint') }}</p>
                    <a class="btn btn-ghost btn-sm" href="#dns">{{ __('cloudflare.tabs.dns') }}</a>
                @endif
            </aside>
        </div>

        <details class="site-technical-card">
            <summary>
                <span>
                    <strong>{{ __('cloudflare.technical.title') }}</strong>
                    <small>{{ __('cloudflare.technical.hint') }}</small>
                </span>
                <span class="site-disclosure-icon" aria-hidden="true"></span>
            </summary>
            <dl class="site-technical-list">
                <div><dt>{{ __('cloudflare.technical.zone_id') }}</dt><dd><code>{{ $zone['id'] ?? __('ops.none') }}</code></dd></div>
                <div><dt>{{ __('cloudflare.technical.account_id') }}</dt><dd><code>{{ $account->account_id ?: __('ops.none') }}</code></dd></div>
            </dl>
        </details>
    </section>

    <section id="dns" class="site-section" role="tabpanel" data-site-panel aria-labelledby="cf-dns-heading">
        <div class="site-section-heading">
            <div>
                <span class="site-section-kicker">{{ __('cloudflare.tabs.dns') }}</span>
                <h2 id="cf-dns-heading">{{ __('cloudflare.tabs.dns') }} @include('ops.cloudflare._hint', ['text' => __('cloudflare.zone.lede')])</h2>
            </div>
        </div>

        @if ($canWrite)
            <section class="ops-panel" aria-labelledby="cf-dns-add-heading">
                <h2 id="cf-dns-add-heading">{{ __('cloudflare.dns.add') }}</h2>
                <form method="POST" action="{{ route('ops.cloudflare.zones.dns.store', ['account' => $account, 'zone' => $zone['id']]) }}" class="ops-form">
                    @csrf
                    @include('ops.cloudflare._dns-fields', ['prefix' => 'zone-add', 'canWrite' => true, 'useOld' => true])
                    @error('priority') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">{{ __('cloudflare.dns.add') }}</button>
                    </div>
                </form>
            </section>
        @endif

        @if ($records === [])
            <div class="empty-panel">
                <h2>{{ __('cloudflare.dns.empty') }}</h2>
            </div>
        @else
            <div class="sites-table-wrap">
                <table class="ops-table">
                    <thead>
                        <tr>
                            <th>{{ __('cloudflare.dns.type') }}</th>
                            <th>{{ __('cloudflare.dns.name') }}</th>
                            <th>{{ __('cloudflare.dns.content') }}</th>
                            <th>{{ __('cloudflare.dns.ttl') }}</th>
                            <th>{{ __('cloudflare.dns.priority') }}</th>
                            @if ($canWrite)<th></th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($records as $row)
                            <tr>
                                @if ($canWrite)
                                    <td colspan="6">
                                        <div class="ops-dns-row">
                                            <form method="POST" action="{{ route('ops.cloudflare.zones.dns.update', ['account' => $account, 'zone' => $zone['id'], 'record' => $row['id']]) }}">
                                                @csrf
                                                @method('PUT')
                                                @include('ops.cloudflare._dns-fields', ['prefix' => 'zone-'.$row['id'], 'canWrite' => true, 'useOld' => false, 'type' => $row['type'], 'name' => $row['name'], 'content' => $row['content'], 'ttl' => $row['ttl'], 'priority' => $row['priority']])
                                                <div class="ops-row-actions">
                                                    <button type="submit" class="btn btn-secondary btn-sm">{{ __('ops.actions.save') }}</button>
                                                </div>
                                            </form>
                                            <form method="POST" action="{{ route('ops.cloudflare.zones.dns.destroy', ['account' => $account, 'zone' => $zone['id'], 'record' => $row['id']]) }}" data-confirm="{{ __('cloudflare.dns.delete_confirm', ['name' => $row['name'], 'type' => $row['type']]) }}" data-confirm-title="{{ __('cloudflare.dns.delete_title') }}" data-confirm-label="{{ __('ops.actions.delete') }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-ghost btn-sm">{{ __('ops.actions.delete') }}</button>
                                            </form>
                                        </div>
                                    </td>
                                @else
                                    <td>{{ $row['type'] }}</td>
                                    <td>{{ $row['name'] }}</td>
                                    <td class="muted">{{ $row['content'] }}</td>
                                    <td class="muted">{{ (int) $row['ttl'] === 1 ? __('cloudflare.dns.ttl_auto') : $row['ttl'] }}</td>
                                    <td class="muted">{{ $row['priority'] ?? __('ops.none') }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($canWrite)
        <section id="danger" class="site-section" role="tabpanel" data-site-panel>
            <div class="danger-zone">
                <div>
                    <h2>{{ __('cloudflare.zone.danger_title') }}</h2>
                    <p>{{ __('cloudflare.zone.danger_lede') }}</p>
                </div>
                <form method="POST" action="{{ route('ops.cloudflare.zones.destroy', ['account' => $account, 'zone' => $zone['id']]) }}" data-confirm="{{ __('cloudflare.zone.danger_confirm', ['domain' => $zoneName]) }}" data-confirm-title="{{ __('cloudflare.zone.danger_title') }}" data-confirm-label="{{ __('cloudflare.zone.danger_label') }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger">{{ __('cloudflare.zone.danger_button') }}</button>
                </form>
            </div>
        </section>
    @endif
@endsection

@section('scripts')
    @include('ops.cloudflare._resource-tabs')
@endsection
