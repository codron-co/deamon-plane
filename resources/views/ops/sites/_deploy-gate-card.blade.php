{{--
    Deploy gate (docs/modules/ci-gated-rollout.md): `coolify` = Coolify builds every
    push; `ci` = Plane deploys after GitHub's CI is green (canary first). Switching
    never deploys. Rendered inside the Coolify ops panel.
--}}
@php
    /** @var \App\Models\Site $site */
    $gate = $site->usesCiGate() ? \App\Enums\DeployGate::Ci : \App\Enums\DeployGate::Coolify;
    $nextGate = $gate === \App\Enums\DeployGate::Ci ? \App\Enums\DeployGate::Coolify : \App\Enums\DeployGate::Ci;
@endphp
<article class="site-card site-operation" data-deploy-gate aria-labelledby="deploy-gate-heading">
    <div class="site-card-head">
        <h3 id="deploy-gate-heading">{{ __('rollouts.site.title') }} @include('ops.dashboard._hint', ['text' => __('rollouts.site.hint')])</h3>
        <div class="branch-version">
            <span class="status-chip {{ $gate === \App\Enums\DeployGate::Ci ? 'status-active' : '' }}">{{ $gate->label() }}</span>
            @if ($site->deploy_canary)
                <span class="status-chip">{{ __('rollouts.site.canary_chip') }}</span>
            @endif
        </div>
    </div>

    @if ($site->usesCiGate() && $site->hasPinnedCommit())
        <p class="field-hint">{{ __('rollouts.site.pinned_note') }}</p>
    @endif

    @if ($canOps)
        <div class="form-actions">
            <form
                method="POST"
                action="{{ route('ops.sites.deploy-gate', $site) }}"
                data-ops-pending
                data-confirm="{{ __('rollouts.site.confirm_'.$nextGate->value, ['name' => $site->name]) }}"
                data-confirm-title="{{ __('rollouts.site.confirm_title') }}"
                data-confirm-label="{{ __('rollouts.site.switch_to_'.$nextGate->value) }}"
                data-confirm-danger="false"
            >
                @csrf
                <input type="hidden" name="gate" value="{{ $nextGate->value }}">
                <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('rollouts.site.switch_to_'.$nextGate->value) }}</button>
            </form>
            <form
                method="POST"
                action="{{ route('ops.sites.deploy-canary', $site) }}"
                data-ops-pending
                data-confirm="{{ $site->deploy_canary ? __('rollouts.site.confirm_canary_off', ['name' => $site->name]) : __('rollouts.site.confirm_canary_on', ['name' => $site->name]) }}"
                data-confirm-title="{{ __('rollouts.site.canary_title') }}"
                data-confirm-label="{{ $site->deploy_canary ? __('rollouts.site.canary_off_button') : __('rollouts.site.canary_on_button') }}"
                data-confirm-danger="false"
            >
                @csrf
                <input type="hidden" name="canary" value="{{ $site->deploy_canary ? '0' : '1' }}">
                <button type="submit" class="btn btn-ghost btn-sm" aria-pressed="{{ $site->deploy_canary ? 'true' : 'false' }}" data-pending-label="{{ __('ops.actions.working') }}">{{ $site->deploy_canary ? __('rollouts.site.canary_off_button') : __('rollouts.site.canary_on_button') }}</button>
            </form>
            <a class="btn btn-ghost btn-sm" href="{{ route('ops.rollouts') }}">{{ __('rollouts.site.open_rollouts') }}</a>
        </div>
    @endif
</article>
