@php
    $canManageAdmins = $canManageAdmins ?? false;
    $passwordOnce = session('admin_password_once');
@endphp

@if ($canManageAdmins)
<section id="admins" class="site-section site-section-surface" role="tabpanel" data-site-panel aria-labelledby="site-tab-admins site-admins-heading">
    <div class="site-section-heading">
        <h2 id="site-admins-heading">{{ __('sites.admins.title') }} @include('ops.dashboard._hint', ['text' => __('sites.admins.lede')])</h2>
    </div>

    @error('admins')
        <p class="ops-alert" role="alert">{{ $message }}</p>
    @enderror

    {{-- Flashed values are rendered by the page itself: the panel fetch is a later request and would find them gone. --}}
    @if (filled($passwordOnce))
        <aside class="site-card ops-alert ops-alert-warning" role="status">
            <div class="site-card-head">
                <div>
                    <h3>{{ __('sites.admins.password_once_title') }}</h3>
                </div>
            </div>
            <p class="site-note">{{ __('sites.admins.password_once_hint') }}</p>
            <code class="ops-mono" data-admin-password-once>{{ $passwordOnce }}</code>
        </aside>
    @endif

    {{-- The CMS admin list is loaded on first view of this tab, so the detail page never waits on the site agent. --}}
    <div data-admins-panel data-admins-url="{{ route('ops.sites.admins.panel', $site) }}" aria-live="polite" aria-busy="true">
        <p class="site-note" data-admins-loading>{{ __('sites.admins.loading') }}</p>
        <p class="ops-alert" role="alert" data-admins-error hidden>
            {{ __('sites.admins.load_failed') }}
            <button type="button" class="btn btn-secondary btn-sm" data-admins-retry>{{ __('sites.admins.retry') }}</button>
        </p>
    </div>
</section>
@endif
