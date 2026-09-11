@php
    $canManageAdmins = $canManageAdmins ?? false;
    $canToggleAdminActive = $canToggleAdminActive ?? false;
    $canDestroyAdmin = $canDestroyAdmin ?? false;
    /** @var \App\Services\Agent\AdminAgentResult|null $adminsResult */
    $adminsResult = $adminsResult ?? null;
    $admins = $adminsResult?->admins ?? [];
    $activeAdminCount = collect($admins)->where('is_active', true)->count();
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

    @if ($adminsResult?->needsSecret)
        <p class="ops-alert ops-alert-warning" role="status">{{ __('sites.admins.needs_secret') }}</p>
    @elseif ($adminsResult && ! $adminsResult->ok)
        <p class="ops-alert" role="alert">{{ $adminsResult->outdated ? __('sites.admins.errors.outdated') : $adminsResult->safeMessage }}</p>
    @else
        <div class="site-overview-grid">
            <article class="site-card">
                <div class="site-card-head">
                    <div>
                        <h3>{{ __('sites.admins.list_title') }}</h3>
                    </div>
                </div>
                @if ($admins === [])
                    <p class="site-note">{{ __('sites.admins.empty') }}</p>
                @else
                    <div class="ops-table-wrap">
                        <table class="ops-table">
                            <thead>
                                <tr>
                                    <th>{{ __('sites.admins.columns.name') }}</th>
                                    <th>{{ __('sites.admins.columns.email') }}</th>
                                    <th>{{ __('sites.admins.columns.status') }}</th>
                                    <th>{{ __('sites.admins.columns.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($admins as $admin)
                                    @php
                                        $adminId = (int) ($admin['id'] ?? 0);
                                        $isActive = (bool) ($admin['is_active'] ?? false);
                                        $isLastActive = $isActive && $activeAdminCount <= 1;
                                        $adminEmail = (string) ($admin['email'] ?? '');
                                    @endphp
                                    <tr>
                                        <td>
                                            {{ $admin['name'] ?? '' }}
                                            @if (! empty($admin['must_change_password']))
                                                <span class="status-chip">{{ __('sites.admins.must_change') }}</span>
                                            @endif
                                            @if (! empty($admin['has_two_factor']))
                                                <span class="status-chip">{{ __('sites.admins.two_factor') }}</span>
                                            @endif
                                        </td>
                                        <td>{{ $adminEmail }}</td>
                                        <td>
                                            <span class="status-chip">{{ $isActive ? __('sites.admins.active') : __('sites.admins.inactive') }}</span>
                                        </td>
                                        <td class="ops-table-actions">
                                            <form method="POST" action="{{ route('ops.sites.admins.password', [$site, $adminId]) }}" class="ops-inline-form" data-ops-pending>
                                                @csrf
                                                <input type="hidden" name="password_mode" value="generate">
                                                <input type="hidden" name="admin_email" value="{{ $adminEmail }}">
                                                <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.admins.reset_password') }}</button>
                                            </form>

                                            @if ($canToggleAdminActive && $isActive)
                                                <form method="POST" action="{{ route('ops.sites.admins.deactivate', [$site, $adminId]) }}" class="ops-inline-form" data-ops-pending data-confirm="{{ __('sites.admins.deactivate_confirm', ['email' => $adminEmail]) }}" data-confirm-title="{{ __('sites.admins.deactivate_title') }}" data-confirm-label="{{ __('sites.admins.deactivate') }}">
                                                    @csrf
                                                    <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}" {{ $isLastActive ? 'disabled' : '' }}>{{ __('sites.admins.deactivate') }}</button>
                                                </form>
                                            @elseif ($canToggleAdminActive)
                                                <form method="POST" action="{{ route('ops.sites.admins.activate', [$site, $adminId]) }}" class="ops-inline-form" data-ops-pending>
                                                    @csrf
                                                    <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.admins.activate') }}</button>
                                                </form>
                                            @endif

                                            @if ($canDestroyAdmin)
                                                <form method="POST" action="{{ route('ops.sites.admins.destroy', [$site, $adminId]) }}" class="ops-inline-form" data-ops-pending data-confirm="{{ __('sites.admins.delete_confirm', ['email' => $adminEmail]) }}" data-confirm-title="{{ __('sites.admins.delete_title') }}" data-confirm-label="{{ __('sites.admins.delete') }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <input type="hidden" name="admin_email" value="{{ $adminEmail }}">
                                                    <button type="submit" class="btn btn-danger btn-sm" data-pending-label="{{ __('ops.actions.working') }}" {{ $isLastActive ? 'disabled' : '' }}>{{ __('sites.admins.delete') }}</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </article>

            <aside class="site-card">
                <div class="site-card-head">
                    <div>
                        <h3>{{ __('sites.admins.create_title') }}</h3>
                    </div>
                </div>
                <form method="POST" action="{{ route('ops.sites.admins.store', $site) }}" class="ops-form" data-ops-pending data-admin-create>
                    @csrf
                    <label class="field">
                        <span>{{ __('sites.admins.fields.name') }}</span>
                        <input class="input" type="text" name="name" value="{{ old('name') }}" required maxlength="255">
                    </label>
                    <label class="field">
                        <span>{{ __('sites.admins.fields.email') }}</span>
                        <input class="input" type="email" name="email" value="{{ old('email') }}" required maxlength="255">
                    </label>
                    <fieldset class="field">
                        <legend>{{ __('sites.admins.fields.password') }}</legend>
                        <label class="field-check">
                            <input type="radio" name="password_mode" value="generate" checked data-admin-password-mode>
                            <span>{{ __('sites.admins.password_generate') }}</span>
                        </label>
                        <label class="field-check">
                            <input type="radio" name="password_mode" value="manual" data-admin-password-mode>
                            <span>{{ __('sites.admins.password_manual') }}</span>
                        </label>
                        <input class="input" type="text" name="password" value="" autocomplete="new-password" maxlength="255" data-admin-password-manual hidden placeholder="{{ __('sites.admins.password_placeholder') }}">
                    </fieldset>
                    <button type="submit" class="btn btn-primary" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.admins.create') }}</button>
                </form>
            </aside>
        </div>
    @endif
</section>

<script>
(() => {
    const form = document.querySelector('[data-admin-create]');
    if (!form) return;
    const manual = form.querySelector('[data-admin-password-manual]');
    const modes = form.querySelectorAll('[data-admin-password-mode]');
    const sync = () => {
        const selected = form.querySelector('[data-admin-password-mode]:checked');
        const isManual = selected?.value === 'manual';
        if (!manual) return;
        manual.hidden = !isManual;
        manual.required = isManual;
        if (!isManual) manual.value = '';
    };
    modes.forEach((el) => el.addEventListener('change', sync));
    sync();
})();
</script>
@endif
