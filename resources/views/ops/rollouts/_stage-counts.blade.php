@php($counts = $rollout->stageCounts($stage))
@if ($counts['targets'] === 0 && ! array_key_exists('site_ids', $rollout->stage($stage)))
    <span class="muted">{{ __('ops.none') }}</span>
@else
    <span title="{{ __('rollouts.counts.title') }}">
        {{ __($stage === 'canary' ? 'rollouts.counts.canary' : 'rollouts.counts.fanout', $counts) }}
    </span>
@endif
