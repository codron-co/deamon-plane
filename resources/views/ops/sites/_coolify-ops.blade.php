@php
    /** @var \App\Models\Site $site */
    $canOps = auth()->user()?->can('update', $site) ?? false;
    $snapshot = [
        'build_pack' => null,
        'is_auto_deploy' => false,
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
    $autoDeployOn = (bool) ($snapshot['is_auto_deploy'] ?? false);
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
@endphp

@if ($showPack)
    <article class="site-card site-operation" aria-labelledby="coolify-pack-heading">
        <div class="site-card-head">
            <h3 id="coolify-pack-heading">{{ __('site_ops.pack.title') }}</h3>
        </div>
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
    </article>
@endif

@if (filled($site->coolify_app_uuid) && ($canOps || filled($snapshot['git_commit_sha']) || filled($snapshot['error'])))
    <article class="site-card site-operation" data-autodeploy aria-labelledby="coolify-ops-heading">
        <div class="site-card-head">
            <h3 id="coolify-ops-heading">{{ __('site_ops.auto_deploy.title') }} <button class="site-hint" type="button" aria-label="{{ __('site_ops.auto_deploy.lede') }}"><span aria-hidden="true">i</span><span role="tooltip">{{ __('site_ops.auto_deploy.lede') }}</span></button></h3>
            <div class="branch-version">
                <span class="status-chip">{{ $autoDeployOn ? __('site_ops.auto_deploy.status_on') : __('site_ops.auto_deploy.status_off') }}</span>
                @if ($canOps)
                    @if ($autoDeployOn)
                        <form method="POST" action="{{ route('ops.sites.auto-deploy', $site) }}" data-ops-pending>
                            @csrf
                            <input type="hidden" name="enabled" value="0">
                            <button type="submit" class="btn btn-ghost btn-sm" aria-pressed="true" data-pending-label="{{ __('ops.actions.working') }}">{{ __('site_ops.auto_deploy.off_button') }}</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('ops.sites.auto-deploy', $site) }}" data-ops-pending>
                            @csrf
                            <input type="hidden" name="enabled" value="1">
                            <button type="submit" class="btn btn-ghost btn-sm" aria-pressed="false" data-pending-label="{{ __('ops.actions.working') }}">{{ __('site_ops.auto_deploy.on_button') }}</button>
                        </form>
                    @endif
                @endif
            </div>
        </div>

        @if ($snapshot['error'])
            <p class="ops-alert" role="alert">{{ $snapshot['error'] }}</p>
        @endif

        @if (! $autoDeployOn)
            <p class="field-hint" role="note">{{ __('site_ops.pin.volume_warning') }}</p>
            <p class="site-pin-status">
                @if ($currentSha)
                    {!! __('site_ops.pin.status_pinned', ['sha' => '<b>'.e($currentSha).'</b>']) !!}
                @else
                    {{ __('site_ops.pin.status_unpinned') }}
                @endif
            </p>

            @if ($canOps)
                <form method="POST" action="{{ route('ops.sites.pin', $site) }}" class="ops-form" data-ops-pending>
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
                        <form method="POST" action="{{ route('ops.sites.pin', $site) }}" data-ops-pending>
                            @csrf
                            <input type="hidden" name="ref" value="{{ $latestSha }}">
                            <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('site_ops.pin.update_latest') }}</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('ops.sites.follow-head', $site) }}" data-ops-pending>
                        @csrf
                        <button type="submit" class="btn btn-ghost btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('site_ops.pin.follow_button') }}</button>
                    </form>
                </div>
            @endif
        @endif
    </article>
@endif
