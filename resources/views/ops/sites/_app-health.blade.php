@php
    $appHealth = $appHealth ?? $site->appHealth();
    $appHealthView = $appHealth->toView($site);
    $canFix = $canEdit ?? false;
@endphp
<article
    class="site-card site-operation"
    data-app-health-root
    data-site-id="{{ $site->id }}"
    data-can-fix="{{ ($canEdit ?? false) ? '1' : '0' }}"
    data-fix-action="{{ route('ops.sites.app-health.fix', $site) }}"
    aria-labelledby="app-health-heading"
>
    <div class="site-card-head">
        <h3 id="app-health-heading">{{ __('sites.app_health.title') }} @include('ops.dashboard._hint', ['text' => __('sites.app_health.hint')])</h3>
        <form method="POST" action="{{ route('ops.sites.app-health', $site) }}" data-ops-pending data-app-health-refresh>
            @csrf
            <button type="submit" class="btn btn-ghost btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.app_health.refresh') }}</button>
        </form>
    </div>
    <p class="ops-app-health-status">
        <span class="status-chip status-{{ $appHealthView['tone'] === 'ok' ? 'ok' : ($appHealthView['tone'] === 'error' ? 'error' : 'unknown') }}" data-app-health-label>{{ $appHealthView['label'] }}</span>
        <button type="button" class="btn btn-ghost btn-sm" data-app-health-copy data-copy-text="{{ $appHealthView['copy_text'] }}">{{ __('sites.app_health.copy') }}</button>
    </p>
    <ul class="ops-app-health-issues" data-app-health-issues @if ($appHealthView['issues'] === []) hidden @endif>
        @foreach ($appHealthView['issues'] as $issue)
            <li>
                <span>{{ $issue['message'] }}</span>
                @if ($canFix && filled($issue['fix']))
                    <form method="POST" action="{{ route('ops.sites.app-health.fix', $site) }}" data-ops-pending data-app-health-fix>
                        @csrf
                        <input type="hidden" name="fix" value="{{ $issue['fix'] }}">
                        <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ $issue['fix_label'] }}</button>
                    </form>
                @endif
            </li>
        @endforeach
    </ul>
    @if ($appHealthView['issues'] === [])
        <p class="muted" data-app-health-empty>{{ $appHealthView['copy_text'] }}</p>
    @endif
    @if ($canFix && $appHealthView['issues'] !== [])
        @php($detailFixes = \App\Services\Sites\SiteAppHealthFixer::orderedUniqueFixes($appHealth))
        @if ($detailFixes !== [])
            <form
                method="POST"
                action="{{ route('ops.sites.app-health.fix', $site) }}"
                class="ops-app-health-fix-all"
                data-ops-pending
                data-app-health-fix
                data-confirm="{{ __('sites.app_health.confirm_fix_all', ['name' => $site->name]) }}"
                data-confirm-title="{{ __('sites.app_health.fix_all') }}"
                data-confirm-label="{{ __('sites.app_health.fix_all') }}"
                data-confirm-danger="true"
            >
                @csrf
                <input type="hidden" name="fix" value="all">
                <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.app_health.fix_all') }}</button>
            </form>
        @endif
    @endif
</article>
