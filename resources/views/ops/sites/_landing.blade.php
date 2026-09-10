@php
    /** @var \App\Models\Site $site */
    $canEditLanding = $canEdit ?? false;
    $waiting = $site->isWaitingOnDns();
    $nameservers = is_array($site->cloudflare_nameservers) ? array_values($site->cloudflare_nameservers) : [];
    $preview = filled($site->temporary_domain) ? 'https://'.$site->temporary_domain : null;
@endphp

<article
    class="site-card site-operation"
    id="site-landing"
    data-landing-panel
    aria-labelledby="site-landing-heading"
    @if (! $waiting) hidden @endif
>
    <div class="site-card-head">
        <h3 id="site-landing-heading">{{ __('sites.landing.title') }} @include('ops.dashboard._hint', ['text' => __('sites.landing.warning')])</h3>
        @if ($waiting)
            <span class="status-chip">{{ $site->cloudflare_zone_status ?: __('sites.detail.zone_pending') }}</span>
        @endif
    </div>

    <p class="ops-alert ops-alert-warning" role="status" data-landing-message>
        {{ __('sites.landing.pending') }}
    </p>

    @if ($preview)
        <p>
            <a href="{{ $preview }}" target="_blank" rel="noopener noreferrer">{{ $site->temporary_domain }}</a>
            @include('ops.dashboard._hint', ['text' => __('sites.landing.preview')])
        </p>
    @endif

    @if ($nameservers !== [])
        <ul class="ops-ns-list">
            @foreach ($nameservers as $ns)
                <li class="ops-ns-row"><code>{{ $ns }}</code></li>
            @endforeach
        </ul>
    @endif

    @if ($canEditLanding)
        <form
            method="POST"
            action="{{ route('ops.sites.cloudflare.dns', $site) }}"
            class="ops-form"
            data-ops-pending
            data-landing-confirm
        >
            @csrf
            <button type="submit" class="btn btn-primary" data-pending-label="{{ __('ops.actions.working') }}">
                {{ __('sites.landing.confirm_dns') }}
            </button>
        </form>
    @endif
</article>
