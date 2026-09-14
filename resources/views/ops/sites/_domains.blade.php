@php
    /** @var \App\Models\Site $site */
    $canEditDomains = $canEdit ?? false;
    $rows = $site->relationLoaded('domains') ? $site->domains : $site->domains()->get();
    $rows = $rows->sortBy(fn ($row) => $row->is_primary ? 0 : ($row->is_temporary ? 2 : 1))->values();
@endphp

<article class="site-card site-operation" id="site-domains" aria-labelledby="site-domains-heading" data-domains-panel>
    <div class="site-card-head">
        <h3 id="site-domains-heading">{{ __('sites.detail.domains') }} @include('ops.dashboard._hint', ['text' => __('sites.detail.domains_hint')])</h3>
    </div>

    <ul class="ops-ns-list" data-domain-list>
        @forelse ($rows as $row)
            <li class="ops-ns-row">
                <span class="ops-host-copy">
                    <code>{{ $row->domain }}</code>
                    <button
                        type="button"
                        class="btn btn-ghost btn-sm"
                        data-copy-value="{{ $row->domain }}"
                        data-copied-label="{{ __('sites.detail.copied') }}"
                        aria-label="{{ __('sites.detail.copy_host', ['host' => $row->domain]) }}"
                    >{{ __('sites.detail.copy') }}</button>
                </span>
                <span>
                    @if ($row->is_primary)
                        {{ __('sites.detail.domain_primary') }}
                    @elseif ($row->is_temporary)
                        {{ __('sites.detail.domain_temp') }}
                    @elseif ($row->is_www)
                        {{ __('sites.detail.domain_www') }}
                    @else
                        {{ __('sites.detail.domain_alias') }}
                    @endif
                    ·
                    @if ($row->verified_at)
                        {{ __('domains.coolify.bound') }}
                    @else
                        {{ __('domains.coolify.unbound') }}
                    @endif
                    @if ($row->zonePending())
                        · <span class="status-chip">{{ __('sites.detail.domain_zone_pending') }}</span>
                    @endif
                </span>
            </li>
        @empty
            <li class="muted">{{ $site->primary_domain ?: __('ops.none') }}</li>
        @endforelse
    </ul>

    @foreach ($site->aliasZonesPending() as $pendingZone)
        <p class="site-note" data-domain-zone-pending="{{ $pendingZone['host'] }}">
            <strong>{{ $pendingZone['host'] }}</strong> — {{ __('sites.detail.domain_zone_pending') }}.
            {{ __('sites.detail.domain_zone_ns') }}:
            @foreach ($pendingZone['nameservers'] as $ns)
                <code>{{ $ns }}</code>@if (! $loop->last), @endif
            @endforeach
        </p>
    @endforeach

    @if ($canEditDomains)
        <form
            method="POST"
            action="{{ route('ops.sites.domains.store', $site) }}"
            class="ops-form"
            data-ops-pending
            data-domain-add
        >
            @csrf
            <div class="site-operation-line">
                <div class="field">
                    <label class="field-label" for="site_add_domain">{{ __('sites.detail.add_domain') }}</label>
                    <input
                        id="site_add_domain"
                        class="field-input"
                        type="text"
                        name="domain"
                        autocomplete="off"
                        maxlength="255"
                        placeholder="{{ __('sites.form.alias_placeholder') }}"
                        required
                    >
                </div>
                <button type="submit" class="btn btn-secondary" data-pending-label="{{ __('ops.actions.working') }}">
                    {{ __('sites.detail.add_domain') }}
                </button>
            </div>
        </form>
    @endif
</article>
