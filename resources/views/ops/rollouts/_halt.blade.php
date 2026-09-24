{{-- Stop an open rollout before its next deploy. Operator action (ops.write). --}}
<form
    method="POST"
    action="{{ route('ops.rollouts.halt', $rollout) }}"
    data-ops-pending
    data-confirm="{{ __('rollouts.halt.confirm', ['channel' => $rollout->channel->value, 'sha' => $rollout->shortSha()]) }}"
    data-confirm-title="{{ __('rollouts.halt.confirm_title') }}"
    data-confirm-label="{{ __('rollouts.halt.button') }}"
    data-confirm-danger="true"
>
    @csrf
    <button type="submit" class="btn btn-ghost btn-sm is-danger" data-pending-label="{{ __('ops.actions.working') }}">{{ __('rollouts.halt.button') }}</button>
</form>
