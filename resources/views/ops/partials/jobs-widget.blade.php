<div
    class="ops-jobs"
    data-ops-jobs
    hidden
    data-jobs-index="{{ route('ops.jobs') }}"
    data-jobs-show="{{ url('/jobs') }}"
    {{-- A poll is a Coolify read, so a long wait must cost less than a short one. --}}
    data-poll-fast-ms="{{ (int) config('ops.jobs.poll.fast_ms', 1500) }}"
    data-poll-slow-ms="{{ (int) config('ops.jobs.poll.slow_ms', 5000) }}"
    data-poll-max-ms="{{ (int) config('ops.jobs.poll.max_ms', 10000) }}"
    data-poll-slow-after="{{ (int) config('ops.jobs.poll.slow_after', 8) }}"
    data-can-write="{{ auth()->user()?->canWriteOps() ? '1' : '0' }}"
    data-copy-title="{{ __('ops.jobs.title') }}"
    data-copy-minimize="{{ __('ops.jobs.minimize') }}"
    data-copy-expand="{{ __('ops.jobs.expand') }}"
    data-copy-close="{{ __('ops.jobs.close') }}"
    data-copy-queued="{{ __('ops.jobs.status.queued') }}"
    data-copy-running="{{ __('ops.jobs.status.running') }}"
    data-copy-completed="{{ __('ops.jobs.status.completed') }}"
    data-copy-failed="{{ __('ops.jobs.status.failed') }}"
    data-copy-cancelled="{{ __('ops.jobs.status.cancelled') }}"
    data-copy-request-failed="{{ __('ops.jobs.request_failed') }}"
    data-copy-session="{{ __('ops.jobs.session_expired') }}"
    data-copy-copied="{{ __('sites.app_health.copied') }}"
    data-copy-deploy="{{ __('ops.jobs.deployment') }}"
    data-copy-dismiss="{{ __('ops.jobs.dismiss') }}"
    data-copy-stop="{{ __('ops.jobs.stop') }}"
    data-copy-force-start="{{ __('ops.jobs.force_start') }}"
    data-copy-force-started="{{ __('ops.jobs.force_started') }}"
    data-copy-failed-only="{{ __('ops.jobs.failed_only') }}"
    data-jobs-destroy="{{ url('/jobs') }}"
    data-jobs-deploy-cancel="{{ url('/jobs/deployments') }}"
    data-jobs-deploy-force="{{ url('/jobs/deployments') }}"
    data-jobs-coolify-cancel="{{ url('/jobs/coolify-deployments') }}"
    data-jobs-coolify-force="{{ url('/jobs/coolify-deployments') }}"
>
    <div class="ops-jobs-panel">
        <div class="ops-jobs-header">
            <button type="button" class="ops-jobs-summary" data-ops-jobs-toggle aria-expanded="true">
                <span class="ops-jobs-summary-text" data-ops-jobs-summary>{{ __('ops.jobs.title') }}</span>
                {{-- One build per Coolify server, so the header says how deep the line is. --}}
                <span class="ops-jobs-queue" data-ops-jobs-queue hidden></span>
                <span class="ops-jobs-count" data-ops-jobs-count hidden></span>
            </button>
            <div class="ops-jobs-header-actions">
                <a class="ops-jobs-filter ops-jobs-history" href="{{ route('ops.activity') }}">{{ __('ops.jobs.history') }}</a>
                <button
                    type="button"
                    class="ops-jobs-filter"
                    data-ops-jobs-failed
                    aria-pressed="false"
                >{{ __('ops.jobs.failed_only') }}</button>
                <button type="button" class="ops-icon-btn" data-ops-jobs-minimize aria-label="{{ __('ops.jobs.minimize') }}">
                    <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><path d="M3.5 8h9" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                </button>
                <button type="button" class="ops-icon-btn" data-ops-jobs-close aria-label="{{ __('ops.jobs.close') }}">
                    <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                </button>
            </div>
        </div>
        <div class="ops-jobs-body" data-ops-jobs-body>
            <ul class="ops-jobs-list" data-ops-jobs-list></ul>
        </div>
    </div>
</div>
