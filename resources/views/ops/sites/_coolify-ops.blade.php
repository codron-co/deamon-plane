@php
    /** @var \App\Models\Site $site */
    $canOpsCoolify = auth()->user()?->can('update', $site) ?? false;
    // Only a site whose card needs the live Coolify application is loaded after the page.
    $needsSnapshot = filled($site->coolify_app_uuid) && ($canOpsCoolify || $site->hasDockerfileBuildPackWarning());
@endphp

@if ($needsSnapshot)
    <div data-lazy-panel data-lazy-url="{{ route('ops.sites.coolify-ops.panel', $site) }}" aria-live="polite" aria-busy="true">
        <article class="site-card site-operation">
            <p class="site-note" data-lazy-loading>{{ __('sites.detail.panel_loading') }}</p>
            <p class="ops-alert" role="alert" data-lazy-error hidden>
                {{ __('sites.detail.panel_failed') }}
                <button type="button" class="btn btn-secondary btn-sm" data-lazy-retry>{{ __('sites.detail.panel_retry') }}</button>
            </p>
        </article>
    </div>
@else
    @include('ops.sites._coolify-ops-body', ['snapshot' => []])
@endif
