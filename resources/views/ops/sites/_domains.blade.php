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
                <code>{{ $row->domain }}</code>
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
                </span>
            </li>
        @empty
            <li class="muted">{{ $site->primary_domain ?: __('ops.none') }}</li>
        @endforelse
    </ul>

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
