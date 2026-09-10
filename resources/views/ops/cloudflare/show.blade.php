@extends('layouts.ops')

@section('title', $account->name)

@section('content_class', 'ops-content-wide')

@section('breadcrumbs')
    <a href="{{ route('ops.cloudflare.index') }}">{{ __('cloudflare.title') }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ $account->name }}</span>
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.cloudflare.index') }}">{{ __('cloudflare.back') }}</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.cloudflare.defaults') }}">{{ __('cloudflare.defaults.nav') }}</a>
    @if ($canWrite && $account->hasCredentials())
        <form method="POST" action="{{ route('ops.cloudflare.test', $account) }}" data-ops-pending>
            @csrf
            <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('cloudflare.test') }}</button>
        </form>
    @endif
@endsection

@section('content')
    @php
        $probe = is_array($account->last_probe_payload) ? $account->last_probe_payload : [];
        $missing = is_array($probe['missing'] ?? null) ? $probe['missing'] : [];
        $probeState = ! $account->last_probe_at
            ? 'never'
            : (($probe['dns_unverified'] ?? false) === true ? 'unverified' : ($missing !== [] ? 'partial' : 'ok'));
        $health = ! $account->is_enabled
            ? 'disabled'
            : (! $account->hasCredentials() ? 'no_token' : $probeState);
        $healthIcon = $health === 'ok' ? 'ok' : (in_array($health, ['disabled', 'no_token', 'partial'], true) ? 'error' : 'connection');
        $zoneCount = is_countable($zones) ? count($zones) : 0;
        $wildcard = $account->wildcard_domain ?: config('ops.cloudflare.wildcard_domain');
        $configErrors = $errors->hasAny(['account_id', 'api_token', 'wildcard_domain', 'is_enabled', 'is_default'])
            || ($errors->has('name') && old('apply_defaults') === null);
        $zoneErrors = $errors->has('name') && old('apply_defaults') !== null;
        $initialTab = $zoneErrors ? 'zones' : ($configErrors ? 'configuration' : '');
    @endphp

    <header class="site-hero">
        <div class="site-hero-main">
            <div class="site-identity-mark" aria-hidden="true">{{ \App\Support\IdentityMark::letter($account->name) }}</div>
            <div class="site-identity-copy">
                <div class="site-title-row">
                    <h2>{{ $account->name }}</h2>
                    <span class="status-chip status-{{ $account->is_enabled ? 'active' : 'error' }}">
                        {{ $account->is_enabled ? __('ops.enabled') : __('ops.disabled') }}
                    </span>
                    @if ($account->is_default)
                        <span class="ops-chip">{{ __('ops.default') }}</span>
                    @endif
                </div>
                <div class="site-domain-row">
                    <span>{{ $wildcard ?: __('ops.none') }}</span>
                </div>
            </div>
        </div>
    </header>

    <nav class="site-section-nav" aria-label="{{ __('cloudflare.tabs.aria') }}" role="tablist" data-site-tabs data-initial-tab="{{ $initialTab }}">
        <a class="is-active" href="#overview" role="tab" aria-selected="true" aria-controls="overview">{{ __('cloudflare.tabs.overview') }}</a>
        <a href="#zones" role="tab" aria-selected="false" aria-controls="zones">{{ __('cloudflare.tabs.zones') }}</a>
        <a href="#configuration" role="tab" aria-selected="false" aria-controls="configuration">{{ __('cloudflare.tabs.configuration') }}</a>
        @if ($canWrite)
            <a href="#danger" role="tab" aria-selected="false" aria-controls="danger">{{ __('cloudflare.tabs.danger') }}</a>
        @endif
    </nav>

    @if ($zonesError)
        <p class="ops-alert site-banner" role="alert">{{ $zonesError }}</p>
    @endif

    @if ($account->last_probe_at && $probeState === 'unverified')
        <p class="ops-alert ops-alert-warning site-banner" role="status">{{ __('cloudflare.probe.unverified_dns') }}</p>
    @elseif ($account->last_probe_at && $missing !== [])
        <p class="ops-alert ops-alert-warning site-banner" role="status">{{ __('cloudflare.probe.partial', ['missing' => implode(', ', $missing)]) }}</p>
    @endif

    <section id="overview" class="site-section" role="tabpanel" data-site-panel aria-labelledby="cf-overview-heading">
        <div class="site-section-heading">
            <div>
                <span class="site-section-kicker">{{ __('cloudflare.show_kicker') }}</span>
                <h2 id="cf-overview-heading">{{ __('cloudflare.tabs.overview') }} @include('ops.cloudflare._hint', ['text' => __('cloudflare.show.lede')])</h2>
            </div>
        </div>

        <div class="site-metric-grid">
            <article class="site-metric">
                <div class="site-metric-icon is-{{ $healthIcon }}" aria-hidden="true"><span></span></div>
                <div>
                    <span>{{ __('cloudflare.metrics.health') }}</span>
                    <strong>{{ __('cloudflare.health.'.$health) }}</strong>
                    <small>{{ $account->last_probe_at?->diffForHumans() ?? __('cloudflare.probe.never') }}</small>
                </div>
            </article>
            <article class="site-metric">
                <div class="site-metric-icon is-{{ $account->is_enabled ? 'ok' : 'error' }}" aria-hidden="true"><span></span></div>
                <div>
                    <span>{{ __('cloudflare.metrics.status') }}</span>
                    <strong>{{ $account->is_enabled ? __('ops.enabled') : __('ops.disabled') }}</strong>
                    <small>{{ $account->is_default ? __('ops.default') : __('cloudflare.fields.is_enabled') }}</small>
                </div>
            </article>
            <article class="site-metric">
                <div class="site-metric-icon is-theme" aria-hidden="true"><span></span></div>
                <div>
                    <span>{{ __('cloudflare.metrics.zones') }}</span>
                    <strong>{{ $zoneCount }}</strong>
                    <small>{{ __('cloudflare.zones.title') }}</small>
                </div>
            </article>
            <article class="site-metric">
                <div class="site-metric-icon is-connection" aria-hidden="true"><span></span></div>
                <div>
                    <span>{{ __('cloudflare.metrics.wildcard') }}</span>
                    <strong>{{ $wildcard ?: __('ops.none') }}</strong>
                    <small>{{ __('cloudflare.facts.wildcard') }}</small>
                </div>
            </article>
        </div>

        <div class="site-overview-grid">
            <article class="site-card site-release-card">
                <div class="site-card-head">
                    <div>
                        <span class="site-section-kicker">{{ __('cloudflare.connection') }}</span>
                        <h3>{{ $account->name }}</h3>
                    </div>
                </div>
                <dl class="site-fact-list">
                    <div>
                        <dt>{{ __('cloudflare.facts.token') }}</dt>
                        <dd>{{ $account->hasToken() ? __('cloudflare.facts.token_present') : __('cloudflare.facts.token_missing') }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('cloudflare.facts.probe') }}</dt>
                        <dd>
                            @if ($account->last_probe_at)
                                <time datetime="{{ $account->last_probe_at->toIso8601String() }}">{{ $account->last_probe_at->toDateTimeString() }}</time>
                                @if ($probeState === 'ok')
                                    — {{ __('cloudflare.probe.ok') }}
                                @endif
                            @else
                                {{ __('cloudflare.probe.never') }}
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt>{{ __('cloudflare.facts.zones') }}</dt>
                        <dd>{{ $zoneCount }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('cloudflare.facts.wildcard') }}</dt>
                        <dd>{{ $wildcard ?: __('ops.none') }}</dd>
                    </div>
                </dl>
            </article>

            <aside class="site-card site-next-action">
                <span class="site-section-kicker">{{ __('cloudflare.next.kicker') }}</span>
                @if (! $account->hasCredentials())
                    <h3>{{ __('cloudflare.next.token_title') }}</h3>
                    <p>{{ __('cloudflare.next.token_hint') }}</p>
                    @if ($canWrite)
                        <a class="btn btn-primary btn-sm" href="#configuration">{{ __('cloudflare.next.configure') }}</a>
                    @endif
                @elseif (! $account->is_enabled)
                    <h3>{{ __('cloudflare.next.enable_title') }}</h3>
                    <p>{{ __('cloudflare.next.enable_hint') }}</p>
                    @if ($canWrite)
                        <a class="btn btn-primary btn-sm" href="#configuration">{{ __('cloudflare.next.configure') }}</a>
                    @endif
                @elseif (in_array($probeState, ['partial', 'unverified'], true))
                    <h3>{{ __('cloudflare.next.probe_title') }}</h3>
                    <p>{{ __('cloudflare.next.probe_hint') }}</p>
                    @if ($canWrite)
                        <form method="POST" action="{{ route('ops.cloudflare.test', $account) }}" data-ops-pending>
                            @csrf
                            <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('cloudflare.test') }}</button>
                        </form>
                    @endif
                @elseif ($probeState === 'never' && $canWrite)
                    <h3>{{ __('cloudflare.next.test_title') }}</h3>
                    <p>{{ __('cloudflare.next.test_hint') }}</p>
                    <form method="POST" action="{{ route('ops.cloudflare.test', $account) }}" data-ops-pending>
                        @csrf
                        <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('cloudflare.test') }}</button>
                    </form>
                @elseif ($zoneCount === 0 && $canWrite)
                    <h3>{{ __('cloudflare.next.add_zone_title') }}</h3>
                    <p>{{ __('cloudflare.next.add_zone_hint') }}</p>
                    <a class="btn btn-primary btn-sm" href="#zones">{{ __('cloudflare.next.zones') }}</a>
                @else
                    <h3>{{ __('cloudflare.next.none_title') }}</h3>
                    <p>{{ __('cloudflare.next.none_hint') }}</p>
                    <a class="btn btn-ghost btn-sm" href="#zones">{{ __('cloudflare.next.zones') }}</a>
                @endif
            </aside>
        </div>
    </section>

    <section id="zones" class="site-section" role="tabpanel" data-site-panel aria-labelledby="cf-zones-heading">
        <div class="site-section-heading">
            <div>
                <span class="site-section-kicker">{{ __('cloudflare.zones.title') }}</span>
                <h2 id="cf-zones-heading">{{ __('cloudflare.zones.title') }} @include('ops.cloudflare._hint', ['text' => __('cloudflare.zones.lede')])</h2>
            </div>
        </div>

        @if ($canWrite && $account->hasCredentials())
            <section class="ops-panel" aria-labelledby="cf-zone-add-heading">
                <h2 id="cf-zone-add-heading">{{ __('cloudflare.zones.add') }}</h2>
                <form method="POST" action="{{ route('ops.cloudflare.zones.store', $account) }}" class="ops-form ops-dns-add-domain" data-ops-pending>
                    @csrf
                    <div class="field">
                        <label class="field-label" for="cf-zone-name">{{ __('cloudflare.zones.new_domain') }}</label>
                        <input id="cf-zone-name" class="field-input" type="text" name="name" value="{{ old('name') }}" required maxlength="255" spellcheck="false" autocomplete="off" placeholder="example.com">
                        @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label class="field-check">
                            <input type="hidden" name="apply_defaults" value="0">
                            <input type="checkbox" name="apply_defaults" value="1" @checked(old('apply_defaults', true))>
                            <span>{{ __('cloudflare.zones.apply_defaults') }}</span>
                        </label>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" data-pending-label="{{ __('ops.actions.working') }}">{{ __('cloudflare.zones.add') }}</button>
                    </div>
                </form>
            </section>
        @endif

        @if ($zones === [])
            <div class="empty-panel">
                <h2>{{ __('cloudflare.zones.empty') }}</h2>
            </div>
        @else
            <div class="sites-table-wrap">
                <table class="ops-table">
                    <thead>
                        <tr>
                            <th>{{ __('cloudflare.zones.domain') }}</th>
                            <th>{{ __('cloudflare.zones.status') }}</th>
                            <th>{{ __('cloudflare.zones.ns') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($zones as $zone)
                            @php
                                $zoneId = (string) ($zone['id'] ?? '');
                                $zoneName = (string) ($zone['name'] ?? $zoneId);
                                $href = $zoneId !== '' ? route('ops.cloudflare.zones.show', ['account' => $account, 'zone' => $zoneId]) : null;
                                $ns = is_array($zone['name_servers'] ?? null) ? implode(', ', $zone['name_servers']) : '';
                            @endphp
                            <tr @if ($href) data-href="{{ $href }}" tabindex="0" @endif>
                                <td>
                                    @if ($href)
                                        <a class="site-name" href="{{ $href }}">{{ $zoneName }}</a>
                                    @else
                                        <span class="site-name">{{ $zoneName }}</span>
                                    @endif
                                </td>
                                <td class="muted">{{ $zone['status'] ?? __('ops.unknown') }}</td>
                                <td class="muted">{{ $ns !== '' ? $ns : __('ops.none') }}</td>
                                <td class="ops-row-actions">
                                    @if ($href)
                                        <a class="btn btn-ghost btn-sm" href="{{ $href }}">{{ __('ops.actions.open') }}</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section id="configuration" class="site-section" role="tabpanel" data-site-panel aria-labelledby="cf-configuration-heading">
        <div class="site-section-heading">
            <div>
                <span class="site-section-kicker">{{ __('cloudflare.tabs.configuration') }}</span>
                <h2 id="cf-configuration-heading">{{ __('cloudflare.tabs.configuration') }} @include('ops.cloudflare._hint', ['text' => __('cloudflare.permissions_lede')])</h2>
            </div>
        </div>

        <div class="site-operations-grid">
            <div class="site-operations-main">
                <section class="ops-panel" aria-labelledby="cf-permissions-heading">
                    <h2 id="cf-permissions-heading">{{ __('cloudflare.permissions') }}</h2>
                    <ol class="ops-checklist">
                        <li>{{ __('cloudflare.permissions_items.resource') }}</li>
                        <li>{{ __('cloudflare.permissions_items.dns') }}</li>
                        <li>{{ __('cloudflare.permissions_items.zone') }}</li>
                    </ol>
                    <p class="ops-alert ops-alert-warning" role="status">{{ __('cloudflare.permissions_items.template') }}</p>
                </section>

                <section class="settings-panel" aria-labelledby="cf-connection-heading">
                    <h2 id="cf-connection-heading">{{ __('cloudflare.connection') }}</h2>
                    <form method="POST" action="{{ route('ops.cloudflare.update', $account) }}" class="ops-form settings-form">
                        @csrf
                        @method('PUT')
                        @include('ops.cloudflare._account-fields', ['account' => $account, 'canWrite' => $canWrite, 'requireToken' => false])
                        @if ($canWrite)
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">{{ __('cloudflare.save') }}</button>
                            </div>
                        @else
                            <p class="field-hint">{{ __('cloudflare.readonly') }}</p>
                        @endif
                    </form>
                    @if ($canWrite)
                        <div class="form-actions">
                            <form method="POST" action="{{ route('ops.cloudflare.test', $account) }}" data-ops-pending>
                                @csrf
                                <button type="submit" class="btn btn-secondary" data-pending-label="{{ __('ops.actions.working') }}">{{ __('cloudflare.test') }}</button>
                            </form>
                            @unless ($account->is_default)
                                <form method="POST" action="{{ route('ops.cloudflare.default', $account) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-ghost">{{ __('cloudflare.make_default') }}</button>
                                </form>
                            @endunless
                        </div>
                    @endif
                </section>
            </div>

            <aside class="site-operations-aside">
                <details class="site-technical-card">
                    <summary>
                        <span>
                            <strong>{{ __('cloudflare.technical.title') }}</strong>
                            <small>{{ __('cloudflare.technical.hint') }}</small>
                        </span>
                        <span class="site-disclosure-icon" aria-hidden="true"></span>
                    </summary>
                    <dl class="site-technical-list">
                        <div><dt>{{ __('cloudflare.technical.account_pk') }}</dt><dd><code>{{ $account->id }}</code></dd></div>
                        <div><dt>{{ __('cloudflare.technical.account_id') }}</dt><dd><code>{{ $account->account_id ?: __('ops.none') }}</code></dd></div>
                    </dl>
                </details>
            </aside>
        </div>
    </section>

    @if ($canWrite)
        <section id="danger" class="site-section" role="tabpanel" data-site-panel>
            <div class="danger-zone">
                <div>
                    <h2>{{ __('cloudflare.danger.title') }}</h2>
                    <p>{{ __('cloudflare.danger.lede') }}</p>
                </div>
                <form method="POST" action="{{ route('ops.cloudflare.destroy', $account) }}" data-confirm="{{ __('cloudflare.danger.confirm', ['name' => $account->name]) }}" data-confirm-title="{{ __('cloudflare.danger.confirm_title') }}" data-confirm-label="{{ __('cloudflare.danger.label') }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger">{{ __('cloudflare.danger.button') }}</button>
                </form>
            </div>
        </section>
    @endif
@endsection

@section('scripts')
    @include('ops.cloudflare._resource-tabs')
@endsection
