<div class="ops-modal" data-ops-confirm-modal hidden>
    <div class="ops-modal-backdrop" data-ops-confirm-cancel tabindex="-1"></div>
    <div
        class="ops-modal-panel"
        data-ops-confirm-panel
        role="dialog"
        aria-modal="true"
        aria-labelledby="ops-confirm-title"
        tabindex="-1"
    >
        <div class="ops-modal-header">
            <strong id="ops-confirm-title" data-ops-confirm-title>Confirm</strong>
            <button type="button" class="ops-icon-btn" data-ops-confirm-cancel aria-label="Close">
                <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
            </button>
        </div>
        <div class="ops-modal-body">
            <p class="ops-confirm-message" data-ops-confirm-message></p>
        </div>
        <div class="ops-modal-footer">
            <button type="button" class="btn btn-ghost" data-ops-confirm-cancel>Cancel</button>
            <button type="button" class="btn btn-danger" data-ops-confirm-accept>Delete</button>
        </div>
    </div>
</div>
