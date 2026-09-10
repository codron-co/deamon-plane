@php
    /** @var \App\Models\Site $site */
    $canOps = auth()->user()?->can('update', $site) ?? false;
    $snapshot = [
        'build_pack' => null,
        'is_auto_deploy' => null,
        'git_commit_sha' => null,
        'error' => null,
    ];
    $showPack = $site->hasDockerfileBuildPackWarning();
    if (filled($site->coolify_app_uuid) && ($canOps || $showPack)) {
        try {
            $snapshot = app(\App\Services\Sites\CoolifyDeploySettings::class)->snapshot($site);
        } catch (\Throwable $exception) {
            $snapshot['error'] = $exception->getMessage();
        }
    }
    $showPack = $showPack || ($snapshot['build_pack'] ?? null) === 'dockerfile';
    $autoDeployOn = $snapshot['is_auto_deploy'] ?? null;
    $autoDeployKnown = $autoDeployOn !== null;
    $currentSha = filled($snapshot['git_commit_sha'] ?? null) ? (string) $snapshot['git_commit_sha'] : null;
    $pinCommits = collect();
    foreach ($deployments ?? [] as $deployment) {
        $sha = trim((string) $deployment->commit_sha);
        if ($sha === '' || $pinCommits->has($sha)) {
            continue;
        }
        $pinCommits[$sha] = [
            'sha' => $sha,
            'short' => $deployment->shortSha(),
            'started' => $deployment->started_at,
        ];
    }
    if ($currentSha !== null && ! $pinCommits->has($currentSha)) {
        $pinCommits->put($currentSha, [
            'sha' => $currentSha,
            'short' => substr($currentSha, 0, 7),
            'started' => null,
        ]);
    }
    $selectedRef = old('ref', $currentSha ?? $pinCommits->keys()->first());
    $latestSha = $pinCommits->keys()->first();
    $autoDeployLabel = match ($autoDeployOn) {
        true => __('site_ops.auto_deploy.status_on'),
        false => __('site_ops.auto_deploy.status_off'),
        default => __('site_ops.auto_deploy.status_unknown'),
    };
    $autoDeployHint = __('site_ops.auto_deploy.lede');
    if (! $autoDeployKnown) {
        $autoDeployHint .= ' '.__('site_ops.auto_deploy.unknown_hint');
    }
    if ($autoDeployOn === false) {
        $autoDeployHint .= ' '.__('site_ops.pin.lede').' '.__('site_ops.pin.volume_warning');
    }
@endphp

@if ($showPack)
    <article class="site-card site-operation" aria-labelledby="coolify-pack-heading">
        <div class="site-card-head">
            <h3 id="coolify-pack-heading">{{ __('site_ops.pack.title') }} @include('ops.dashboard._hint', ['text' => __('site_ops.pack.warning')])</h3>
        </div>
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
    </article>
@endif

@if (filled($site->coolify_app_uuid) && ($canOps || filled($snapshot['git_commit_sha']) || filled($snapshot['error'])))
    <article class="site-card site-operation" data-autodeploy aria-labelledby="coolify-ops-heading">
        <div class="site-card-head">
            <h3 id="coolify-ops-heading">{{ __('site_ops.auto_deploy.title') }} @include('ops.dashboard._hint', ['text' => $autoDeployHint])</h3>
            <div class="branch-version">
                <span class="status-chip">{{ $autoDeployLabel }}</span>
                @if ($canOps && $autoDeployOn !== false)
                    <form
                        method="POST"
                        action="{{ route('ops.sites.auto-deploy', $site) }}"
                        data-ops-pending
                        data-confirm="{{ __('site_ops.auto_deploy.confirm_off', ['name' => $site->name]) }}"
                        data-confirm-title="{{ __('site_ops.auto_deploy.confirm_off_title') }}"
                        data-confirm-label="{{ __('site_ops.auto_deploy.off_button') }}"
                    >
                        @csrf
                        <input type="hidden" name="enabled" value="0">
                        <button type="submit" class="btn btn-ghost btn-sm" aria-pressed="{{ $autoDeployOn === true ? 'true' : 'mixed' }}" data-pending-label="{{ __('ops.actions.working') }}">{{ __('site_ops.auto_deploy.off_button') }}</button>
                    </form>
                @endif
                @if ($canOps && $autoDeployOn !== true)
                    <form
                        method="POST"
                        action="{{ route('ops.sites.auto-deploy', $site) }}"
                        data-ops-pending
                        data-confirm="{{ __('site_ops.auto_deploy.confirm_on', ['name' => $site->name]) }}"
                        data-confirm-title="{{ __('site_ops.auto_deploy.confirm_on_title') }}"
                        data-confirm-label="{{ __('site_ops.auto_deploy.on_button') }}"
                        data-confirm-danger="false"
                    >
                        @csrf
                        <input type="hidden" name="enabled" value="1">
                        <button type="submit" class="btn btn-ghost btn-sm" aria-pressed="{{ $autoDeployOn === false ? 'false' : 'mixed' }}" data-pending-label="{{ __('ops.actions.working') }}">{{ __('site_ops.auto_deploy.on_button') }}</button>
                    </form>
                @endif
            </div>
        </div>

        @if ($snapshot['error'])
            <p class="ops-alert" role="alert">{{ $snapshot['error'] }}</p>
        @endif

        @if ($autoDeployOn === false)
            <p class="site-pin-status">
                @if ($currentSha)
                    {{ __('site_ops.pin.current') }} <code>{{ $currentSha }}</code>
                @else
                    {{ __('site_ops.pin.none') }}
                @endif
            </p>

            @if ($canOps)
                <form
                    method="POST"
                    action="{{ route('ops.sites.pin', $site) }}"
                    class="ops-form"
                    data-ops-pending
                    data-confirm="{{ __('site_ops.pin.confirm', ['name' => $site->name]) }}"
                    data-confirm-title="{{ __('site_ops.pin.confirm_title') }}"
                    data-confirm-label="{{ __('site_ops.pin.pin_selected') }}"
                >
                    @csrf
                    <div class="site-operation-line">
                        <div class="field">
                            <label class="field-label" for="site-pin-ref">{{ __('site_ops.pin.ref') }}</label>
                            @if ($pinCommits->isNotEmpty())
                                <select id="site-pin-ref" class="field-input" name="ref" data-commit-select required>
                                    @foreach ($pinCommits as $sha => $commit)
                                        <option value="{{ $sha }}" @selected($selectedRef === $sha)>
                                            {{ $commit['short'] }}
                                            @if ($currentSha && ($sha === $currentSha || str_starts_with($sha, $currentSha) || str_starts_with($currentSha, $sha)))
                                                · {{ __('site_ops.pin.live') }}
                                            @elseif ($latestSha === $sha)
                                                · {{ __('site_ops.pin.head') }}
                                            @else
                                                · {{ __('site_ops.pin.older') }}
                                            @endif
                                            @if ($commit['started'])
                                                · {{ $commit['started']->timezone(config('app.timezone'))->format('Y-m-d H:i') }}
                                            @endif
                                        </option>
                                    @endforeach
                                </select>
                            @else
                                <input id="site-pin-ref" class="field-input" type="text" name="ref" value="{{ old('ref') }}" maxlength="64" autocomplete="off" spellcheck="false">
                            @endif
                            @error('ref') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="btn btn-secondary" data-pending-label="{{ __('ops.actions.working') }}">{{ __('site_ops.pin.pin_selected') }}</button>
                    </div>
                </form>
                <div class="form-actions">
                    @if ($latestSha)
                        <form
                            method="POST"
                            action="{{ route('ops.sites.pin', $site) }}"
                            data-ops-pending
                            data-confirm="{{ __('site_ops.pin.confirm', ['name' => $site->name]) }}"
                            data-confirm-title="{{ __('site_ops.pin.confirm_title') }}"
                            data-confirm-label="{{ __('site_ops.pin.update_latest') }}"
                        >
                            @csrf
                            <input type="hidden" name="ref" value="{{ $latestSha }}">
                            <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('site_ops.pin.update_latest') }}</button>
                        </form>
                    @endif
                    <form
                        method="POST"
                        action="{{ route('ops.sites.follow-head', $site) }}"
                        data-ops-pending
                        data-confirm="{{ __('site_ops.pin.confirm_follow', ['name' => $site->name]) }}"
                        data-confirm-title="{{ __('site_ops.pin.confirm_follow_title') }}"
                        data-confirm-label="{{ __('site_ops.pin.follow_button') }}"
                        data-confirm-danger="false"
                    >
                        @csrf
                        <button type="submit" class="btn btn-ghost btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('site_ops.pin.follow_button') }}</button>
                    </form>
                </div>
            @endif
        @endif
    </article>
@endif
