<div
    class="ops-jobs"
    data-ops-jobs
    hidden
    data-jobs-index="{{ route('ops.jobs') }}"
    data-jobs-show="{{ url('/jobs') }}"
    data-can-write="{{ auth()->user()?->canWriteOps() ? '1' : '0' }}"
    data-copy-title="{{ __('ops.jobs.title') }}"
    data-copy-minimize="{{ __('ops.jobs.minimize') }}"
    data-copy-expand="{{ __('ops.jobs.expand') }}"
    data-copy-close="{{ __('ops.jobs.close') }}"
    data-copy-queued="{{ __('ops.jobs.status.queued') }}"
    data-copy-running="{{ __('ops.jobs.status.running') }}"
    data-copy-completed="{{ __('ops.jobs.status.completed') }}"
    data-copy-failed="{{ __('ops.jobs.status.failed') }}"
    data-copy-request-failed="{{ __('ops.jobs.request_failed') }}"
    data-copy-session="{{ __('ops.jobs.session_expired') }}"
>
    <div class="ops-jobs-panel">
        <div class="ops-jobs-header">
            <button type="button" class="ops-jobs-summary" data-ops-jobs-toggle aria-expanded="true">
                <span data-ops-jobs-summary>{{ __('ops.jobs.title') }}</span>
                <span class="ops-jobs-count" data-ops-jobs-count hidden></span>
            </button>
            <div class="ops-jobs-header-actions">
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
