@php
    /** @var \App\Models\Site $site */
    $envChannel = (string) request()->query('env_channel', '');
    $envPanelUrl = route('ops.sites.env.panel', array_filter([$site, 'channel' => $envChannel !== '' ? $envChannel : null]));
@endphp

@if (filled($site->coolify_app_uuid))
    {{-- Validation errors are rendered by the page itself: the panel fetch is a later request and would find them gone. --}}
    @error('env')
        <p class="ops-alert" role="alert" id="site-env-error">{{ $message }}</p>
    @enderror
    {{-- The Coolify env list is loaded after the page, so the detail page never waits on Coolify. --}}
    <div data-lazy-panel data-lazy-url="{{ $envPanelUrl }}" aria-live="polite" aria-busy="true">
        <article class="site-card site-operation">
            <p class="site-note" data-lazy-loading>{{ __('site_env.loading') }}</p>
            <p class="ops-alert" role="alert" data-lazy-error hidden>
                {{ __('site_env.load_failed_short') }}
                <button type="button" class="btn btn-secondary btn-sm" data-lazy-retry>{{ __('site_env.retry') }}</button>
            </p>
        </article>
    </div>
@endif
