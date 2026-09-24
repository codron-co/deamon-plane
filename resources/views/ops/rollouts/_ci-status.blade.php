@php
    $ciStatus = (string) ($head->ci_status ?? '');
    $ciKey = 'rollouts.ci_status.'.$ciStatus;
    $ciLabel = $ciStatus === '' ? __('rollouts.ci_status.unknown') : (\Illuminate\Support\Facades\Lang::has($ciKey) ? __($ciKey) : $ciStatus);
    $ciClass = match ($ciStatus) {
        'success' => 'status-active',
        'failure', 'timed_out', 'startup_failure' => 'status-error',
        default => '',
    };
@endphp
<span class="status-chip {{ $ciClass }}">{{ __('rollouts.heads.ci') }}: {{ $ciLabel }}</span>
@if ($head->ci_sha && $head->ci_sha !== $head->head_sha)
    <span class="muted">({{ substr($head->ci_sha, 0, 7) }})</span>
@endif
