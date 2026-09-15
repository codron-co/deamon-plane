@php
    /** @var \App\Models\Site $site */
    $canToggleAdminActive = $canToggleAdminActive ?? false;
    $canDestroyAdmin = $canDestroyAdmin ?? false;
    /** @var \App\Services\Agent\AdminAgentResult $adminsResult */
    $admins = $adminsResult->admins;
    $activeAdminCount = collect($admins)->where('is_active', true)->count();
@endphp
{{-- Fetched into #admins by public/js/sites-admins.js. No <script>: injected HTML does not run it. --}}
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
                                        $passwordIsSet = ! empty($admin['password_is_set']);
                                    @endphp
                                    <tr>
                                        <td>
                                            {{ $admin['name'] ?? '' }}
                                            @if (! $passwordIsSet)
                                                <span class="status-chip">{{ __('sites.admins.password_not_set') }}</span>
                                            @elseif (! empty($admin['must_change_password']))
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
                                            @if (! $passwordIsSet)
                                                <form method="POST" action="{{ route('ops.sites.admins.password-invite', [$site, $adminId]) }}" class="ops-inline-form" data-ops-pending data-reload-on-success data-confirm="{{ __('sites.admins.send_password_invite_confirm', ['email' => $adminEmail]) }}" data-confirm-title="{{ __('sites.admins.send_password_invite_title') }}" data-confirm-label="{{ __('sites.admins.send_password_invite') }}">
                                                    @csrf
                                                    <input type="hidden" name="admin_email" value="{{ $adminEmail }}">
                                                    <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.admins.send_password_invite') }}</button>
                                                </form>
                                            @endif

                                            <form method="POST" action="{{ route('ops.sites.admins.password', [$site, $adminId]) }}" class="ops-inline-form" data-ops-pending data-reload-on-success>
                                                @csrf
                                                <input type="hidden" name="password_mode" value="generate">
                                                <input type="hidden" name="admin_email" value="{{ $adminEmail }}">
                                                <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.admins.reset_password') }}</button>
                                            </form>

                                            @if ($canToggleAdminActive && $isActive)
                                                <form method="POST" action="{{ route('ops.sites.admins.deactivate', [$site, $adminId]) }}" class="ops-inline-form" data-ops-pending data-reload-on-success data-confirm="{{ __('sites.admins.deactivate_confirm', ['email' => $adminEmail]) }}" data-confirm-title="{{ __('sites.admins.deactivate_title') }}" data-confirm-label="{{ __('sites.admins.deactivate') }}" data-confirm-danger="true">
                                                    @csrf
                                                    <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}" {{ $isLastActive ? 'disabled' : '' }}>{{ __('sites.admins.deactivate') }}</button>
                                                </form>
                                            @elseif ($canToggleAdminActive)
                                                <form method="POST" action="{{ route('ops.sites.admins.activate', [$site, $adminId]) }}" class="ops-inline-form" data-ops-pending data-reload-on-success>
                                                    @csrf
                                                    <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.admins.activate') }}</button>
                                                </form>
                                            @endif

                                            @if ($canDestroyAdmin)
                                                <form method="POST" action="{{ route('ops.sites.admins.destroy', [$site, $adminId]) }}" class="ops-inline-form" data-ops-pending data-reload-on-success data-confirm="{{ __('sites.admins.delete_confirm', ['email' => $adminEmail]) }}" data-confirm-title="{{ __('sites.admins.delete_title') }}" data-confirm-label="{{ __('sites.admins.delete') }}" data-confirm-danger="true">
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
                <form method="POST" action="{{ route('ops.sites.admins.store', $site) }}" class="ops-form" data-ops-pending data-reload-on-success data-admin-create>
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
                        <label class="field-check">
                            <input type="radio" name="password_mode" value="invite" data-admin-password-mode>
                            <span>{{ __('sites.admins.password_invite') }}</span>
                        </label>
                        <input class="input" type="text" name="password" value="" autocomplete="new-password" maxlength="255" data-admin-password-manual hidden placeholder="{{ __('sites.admins.password_placeholder') }}">
                        <p class="field-hint" data-admin-password-invite-hint hidden>{{ __('sites.admins.password_invite_hint') }}</p>
                    </fieldset>
                    <button type="submit" class="btn btn-primary" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.admins.create') }}</button>
                </form>
            </aside>
        </div>
    @endif
