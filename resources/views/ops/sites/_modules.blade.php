@php
    /** @var \App\Models\Site $site */
@endphp
<section id="modules" class="site-section site-section-surface" role="tabpanel" data-site-panel aria-labelledby="site-tab-modules site-modules-heading">
    <div class="site-section-heading">
        <h2 id="site-modules-heading">{{ __('site_modules.title') }} @include('ops.dashboard._hint', ['text' => __('site_modules.lede')])</h2>
    </div>

    {{-- The CMS module catalog is loaded on first view of this tab (ops-lazy-panels.js), so the detail page never waits on the site agent. --}}
    <div data-lazy-panel data-lazy-url="{{ route('ops.sites.modules.panel', $site) }}" aria-live="polite" aria-busy="true">
        <article class="site-card">
            <p class="site-note" data-lazy-loading>{{ __('site_modules.loading') }}</p>
            <p class="ops-alert" role="alert" data-lazy-error hidden>
                {{ __('site_modules.load_failed') }}
                <button type="button" class="btn btn-secondary btn-sm" data-lazy-retry>{{ __('site_modules.retry') }}</button>
            </p>
        </article>
    </div>
</section>
