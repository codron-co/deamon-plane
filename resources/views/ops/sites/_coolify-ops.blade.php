@php
    /** @var \App\Models\Site $site */
    $canOps = auth()->user()?->can('update', $site) ?? false;
    $snapshot = [
        'build_pack' => null,
        'is_auto_deploy' => false,
        'git_commit_sha' => null,
        'error' => null,
    ];
    if (filled($site->coolify_app_uuid)) {
        $snapshot = app(\App\Services\Sites\CoolifyDeploySettings::class)->snapshot($site);
    }
    $showPack = $site->hasDockerfileBuildPackWarning() || ($snapshot['build_pack'] ?? null) === 'dockerfile';
@endphp

@if (filled($site->coolify_app_uuid) && ($canOps || $showPack || filled($snapshot['git_commit_sha'])))
    <section class="ops-panel" aria-labelledby="coolify-ops-heading">
        <h2 id="coolify-ops-heading">{{ __('site_ops.pack.title') }} / {{ __('site_ops.auto_deploy.title') }}</h2>

        @if ($snapshot['error'])
            <p class="field-error" role="status">{{ $snapshot['error'] }}</p>
        @endif

        @if ($showPack)
            <p class="ops-alert ops-alert-warning" role="status">{{ __('site_ops.pack.warning') }}</p>
            @if ($canOps)
                <form
                    method="POST"
                    action="{{ route('ops.sites.compose', $site) }}"
                    class="ops-form"
                    data-ops-pending
                    data-confirm="{{ __('site_ops.pack.confirm', ['name' => $site->name]) }}"
                    data-confirm-title="{{ __('site_ops.pack.confirm_title') }}"
                    data-confirm-label="{{ __('site_ops.pack.confirm_label') }}"
                >
                    @csrf
                    <div class="form-actions">
                        <button type="submit" class="btn btn-secondary" data-pending-label="{{ __('site_ops.pack.working') }}">{{ __('site_ops.pack.button') }}</button>
                    </div>
                </form>
            @endif
        @endif

        <dl class="spec-list">
            <div>
                <dt>{{ __('site_ops.auto_deploy.title') }}</dt>
                <dd>{{ $snapshot['is_auto_deploy'] ? __('site_ops.auto_deploy.status_on') : __('site_ops.auto_deploy.status_off') }}</dd>
            </div>
            <div>
                <dt>{{ __('site_ops.pin.current') }}</dt>
                <dd>
                    @if (filled($snapshot['git_commit_sha']))
                        <code>{{ $snapshot['git_commit_sha'] }}</code>
                    @else
                        {{ __('site_ops.pin.none') }}
                    @endif
                </dd>
            </div>
        </dl>

        @if ($canOps)
            <p class="field-hint">{{ __('site_ops.auto_deploy.lede') }}</p>
            <div class="form-actions">
                <form method="POST" action="{{ route('ops.sites.auto-deploy', $site) }}" data-ops-pending>
                    @csrf
                    <input type="hidden" name="enabled" value="1">
                    <button type="submit" class="btn btn-ghost btn-sm">{{ __('site_ops.auto_deploy.on_button') }}</button>
                </form>
                <form method="POST" action="{{ route('ops.sites.auto-deploy', $site) }}" data-ops-pending>
                    @csrf
                    <input type="hidden" name="enabled" value="0">
                    <button type="submit" class="btn btn-ghost btn-sm">{{ __('site_ops.auto_deploy.off_button') }}</button>
                </form>
            </div>

            <h3>{{ __('site_ops.pin.title') }}</h3>
            <p class="field-hint">{{ __('site_ops.pin.lede') }}</p>
            <form method="POST" action="{{ route('ops.sites.pin', $site) }}" class="ops-form" data-ops-pending>
                @csrf
                <div class="field">
                    <label class="field-label" for="site-pin-ref">{{ __('site_ops.pin.ref') }}</label>
                    <input id="site-pin-ref" class="field-input" type="text" name="ref" value="{{ old('ref') }}" maxlength="64" autocomplete="off" spellcheck="false">
                    @error('ref') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-secondary">{{ __('site_ops.pin.pin_button') }}</button>
                </div>
            </form>
            <form method="POST" action="{{ route('ops.sites.follow-head', $site) }}" data-ops-pending>
                @csrf
                <button type="submit" class="btn btn-ghost btn-sm">{{ __('site_ops.pin.follow_button') }}</button>
            </form>
        @endif
    </section>
@endif
