@php
    /** @var \App\Models\Site $site */
    $canPublish = ($canChangePublishStatus ?? false) && $site->canChangePublishStatus();
    $current = $site->publishStatus();
    $target = $current?->opposite() ?? \App\Enums\CmsPublishStatus::Published;
    $publishing = $target === \App\Enums\CmsPublishStatus::Published;
@endphp

<article class="site-card site-operation" aria-labelledby="publish-state-heading">
    <div class="site-card-head">
        <h3 id="publish-state-heading">
            {{ __('sites.publish.title') }} @include('ops.dashboard._hint', ['text' => __('sites.publish.hint')])
        </h3>
        <div class="branch-version">
            <span class="status-chip status-{{ $site->publishTone() }}">{{ $site->publishLabel() }}</span>
        </div>
    </div>

    <dl class="site-fact-list is-compact">
        <div>
            <dt>{{ __('sites.publish.last_confirmed') }}</dt>
            <dd>{{ $site->cms_site_status_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? __('ops.never') }}</dd>
        </div>
    </dl>

    @if ($current === null)
        <p class="field-help">{{ __('sites.publish.unknown_hint') }}</p>
    @endif

    @if (($canChangePublishStatus ?? false) && ! $site->canChangePublishStatus())
        <p class="field-help">{{ __('sites.publish.needs_secret') }}</p>
    @endif

    @if ($canPublish)
        <div class="form-actions">
            <form
                method="POST"
                action="{{ route('ops.sites.publish-status', $site) }}"
                data-ops-pending
                data-confirm="{{ $publishing
                    ? __('sites.publish.confirm_publish', ['name' => $site->name])
                    : __('sites.publish.confirm_unpublish', ['name' => $site->name]) }}"
                data-confirm-title="{{ __('sites.publish.title') }}"
                data-confirm-label="{{ $publishing ? __('sites.publish.publish') : __('sites.publish.unpublish') }}"
                data-confirm-danger="{{ $publishing ? 'false' : 'true' }}"
            >
                @csrf
                <input type="hidden" name="publish_status" value="{{ $target->value }}">
                <button
                    type="submit"
                    class="btn {{ $publishing ? 'btn-primary' : 'btn-danger' }} btn-sm"
                    data-pending-label="{{ __('ops.actions.working') }}"
                >{{ $publishing ? __('sites.publish.publish') : __('sites.publish.unpublish') }}</button>
            </form>
        </div>
    @endif
</article>
