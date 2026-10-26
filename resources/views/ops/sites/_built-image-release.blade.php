{{--
    Release card of a site that runs built images (build once, run many;
    docs/modules/ops-sites.md "Deploys target the latest built image"). Takes the
    place of the auto-deploy / pin / HEAD card: auto-deploy is always off, the commit
    picker lists the channel builder's finished builds, "Deploy newest image" is the
    one way forward (follow HEAD and update HEAD do the same for these sites).
--}}
@php
    /** @var \App\Models\Site $site */
    /** @var list<array{sha: string, finished_at: ?string}> $builds */
    $builds = $builds ?? [];
    $autoDeployOn = $snapshot['is_auto_deploy'] ?? null;
    $rawSha = filled($snapshot['git_commit_sha'] ?? null) ? strtolower(trim((string) $snapshot['git_commit_sha'])) : null;
    $currentSha = ($rawSha !== null && ! \App\Services\Coolify\Dto\CoolifyApplication::isHeadRef($rawSha)) ? $rawSha : null;
    $currentShort = $currentSha ? substr($currentSha, 0, 7) : null;
    $latestSha = $builds[0]['sha'] ?? null;
    $latestShort = $latestSha ? substr($latestSha, 0, 7) : null;
    $currentIndex = null;
    if ($currentSha !== null) {
        foreach ($builds as $index => $build) {
            if (str_starts_with($build['sha'], $currentSha)) {
                $currentIndex = $index;
                break;
            }
        }
    }
    $autoDeployLabel = match ($autoDeployOn) {
        true => __('site_ops.auto_deploy.status_on'),
        false => __('site_ops.auto_deploy.status_off'),
        default => __('site_ops.auto_deploy.status_unknown'),
    };
    if ($currentSha === null) {
        $releaseStatus = __('site_ops.release.status_none');
    } elseif ($currentIndex === 0) {
        $releaseStatus = __('site_ops.release.status_latest', ['sha' => $currentShort]);
    } elseif ($currentIndex !== null) {
        $releaseStatus = __('site_ops.release.status_behind', ['sha' => $currentShort, 'latest' => $latestShort, 'count' => $currentIndex]);
    } else {
        $releaseStatus = __('site_ops.release.status_known', ['sha' => $currentShort]);
    }
    $held = $currentSha !== null && $site->holdsPinnedCommit();
    $selectedRef = old('ref', $currentSha !== null && $currentIndex !== null ? $builds[$currentIndex]['sha'] : $latestSha);
    $redeployConfirm = $currentSha
        ? __('site_ops.release.confirm_redeploy', ['name' => $site->name, 'sha' => $currentShort])
        : __('site_ops.release.confirm_redeploy_none', ['name' => $site->name]);
    $hint = __('site_ops.release.lede').' '.__('site_ops.pin.volume_warning');
@endphp

<article class="site-card site-operation" data-autodeploy data-built-image aria-labelledby="coolify-ops-heading">
    <div class="site-card-head">
        <h3 id="coolify-ops-heading">{{ __('site_ops.release.title') }} @include('ops.dashboard._hint', ['text' => $hint])</h3>
        <div class="branch-version">
            <span class="status-chip" title="{{ __('site_ops.release.auto_off_note') }}">{{ __('site_ops.auto_deploy.title') }}: {{ $autoDeployLabel }}</span>
            {{-- Off is the only direction left; it repairs an app someone turned on in Coolify. --}}
            @if ($canOps && $autoDeployOn !== false)
                <form
                    method="POST"
                    action="{{ route('ops.sites.auto-deploy', $site) }}"
                    data-ops-pending
                    data-confirm="{{ __('site_ops.auto_deploy.confirm_off', ['name' => $site->name]) }}"
                    data-confirm-title="{{ __('site_ops.auto_deploy.confirm_off_title') }}"
                    data-confirm-label="{{ __('site_ops.auto_deploy.off_button') }}"
                    data-confirm-danger="false"
                >
                    @csrf
                    <input type="hidden" name="enabled" value="0">
                    <button type="submit" class="btn btn-ghost btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('site_ops.auto_deploy.off_button') }}</button>
                </form>
            @endif
        </div>
    </div>

    @if ($snapshot['error'])
        <p class="ops-alert" role="alert">{{ $snapshot['error'] }}</p>
    @endif

    <p class="site-pin-status">{{ $releaseStatus }}</p>
    @if ($held)
        <p class="field-hint">{{ __('site_ops.release.held') }}</p>
    @endif

    @if ($canOps)
        <div class="site-operation-pin">
            <form
                method="POST"
                action="{{ route('ops.sites.pin', $site) }}"
                class="ops-form"
                data-ops-pending
                data-confirm="{{ __('site_ops.release.confirm_pin', ['name' => $site->name]) }}"
                data-confirm-title="{{ __('site_ops.pin.confirm_title') }}"
                data-confirm-label="{{ __('site_ops.pin.pin_selected') }}"
                data-confirm-danger="false"
            >
                @csrf
                <div class="site-operation-line">
                    <div class="field">
                        <label class="field-label" for="site-pin-ref">{{ __('site_ops.pin.ref') }}</label>
                        @if ($builds !== [])
                            <select id="site-pin-ref" class="field-input" name="ref" data-commit-select required>
                                @foreach ($builds as $index => $build)
                                    <option value="{{ $build['sha'] }}" @selected($selectedRef === $build['sha'])>
                                        {{ substr($build['sha'], 0, 7) }}
                                        @if ($currentIndex === $index)
                                            · {{ __('site_ops.pin.live') }}
                                        @elseif ($index === 0)
                                            · {{ __('site_ops.release.latest') }}
                                        @else
                                            · {{ __('site_ops.pin.older') }}
                                        @endif
                                        @if ($build['finished_at'])
                                            · {{ \Illuminate\Support\Carbon::parse($build['finished_at'])->timezone(config('app.timezone'))->format('Y-m-d H:i') }}
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                        @else
                            <input id="site-pin-ref" class="field-input" type="text" name="ref" value="{{ old('ref') }}" maxlength="64" autocomplete="off" spellcheck="false" required>
                            <p class="field-hint">{{ __('site_ops.release.no_builds') }}</p>
                        @endif
                        @error('ref') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="btn btn-secondary" data-pending-label="{{ __('ops.actions.working') }}">{{ __('site_ops.pin.pin_selected') }}</button>
                </div>
            </form>
            <div class="form-actions">
                <form
                    method="POST"
                    action="{{ route('ops.sites.update-head', $site) }}"
                    data-ops-pending
                    data-confirm="{{ __('site_ops.release.confirm_latest', ['name' => $site->name]) }}"
                    data-confirm-title="{{ __('site_ops.release.confirm_latest_title') }}"
                    data-confirm-label="{{ __('site_ops.release.deploy_latest') }}"
                    data-confirm-danger="false"
                >
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('site_ops.release.deploy_latest') }}</button>
                </form>
                <form
                    method="POST"
                    action="{{ route('ops.sites.deploy', $site) }}"
                    data-ops-pending
                    data-confirm="{{ $redeployConfirm }}"
                    data-confirm-title="{{ __('site_ops.redeploy.confirm_title') }}"
                    data-confirm-label="{{ __('site_ops.redeploy.button') }}"
                    data-confirm-danger="false"
                >
                    @csrf
                    <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('site_ops.redeploy.working') }}">{{ __('site_ops.redeploy.button') }}</button>
                </form>
            </div>
        </div>
    @endif
</article>
