@php
    $deployments = $deployments ?? collect();
    $coolifyAppUrl = $coolifyAppUrl ?? null;
@endphp
<section class="deployments-panel" aria-labelledby="deployments-heading">
    <div class="deployments-panel-head">
        <div>
            <h2 id="deployments-heading">{{ __('sites.deployments.title') }} @include('ops.dashboard._hint', ['text' => __('sites.deployments.lede', ['count' => $deployments->count()])])</h2>
        </div>
        <div class="form-actions">
            @if ($canSyncCoolify ?? false)
                <form
                    method="POST"
                    action="{{ route('ops.sites.sync', $site) }}"
                    data-ops-pending
                    data-confirm="{{ __('sites.detail.sync_confirm', ['name' => $site->name]) }}"
                    data-confirm-title="{{ __('sites.detail.sync_title') }}"
                    data-confirm-label="{{ __('sites.detail.sync') }}"
                    data-confirm-danger="false"
                >
                    @csrf
                    <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('sites.detail.sync') }}</button>
                </form>
            @endif
            @if ($coolifyAppUrl)
                <a class="btn btn-ghost btn-sm" href="{{ $coolifyAppUrl }}" target="_blank" rel="noopener noreferrer">{{ __('sites.deployments.open_coolify') }}</a>
            @endif
        </div>
    </div>

    @if ($deployments->isEmpty())
        <p class="muted">{{ __('sites.deployments.empty') }}</p>
    @else
        <div class="sites-table-wrap">
            <table class="ops-table">
                <thead>
                    <tr>
                        <th><span class="ops-th-label">{{ __('sites.deployments.columns.status') }} @include('ops.dashboard._hint', ['text' => __('sites.deployments.column_hints.status')])</span></th>
                        <th><span class="ops-th-label">{{ __('sites.deployments.columns.branch') }} @include('ops.dashboard._hint', ['text' => __('sites.deployments.column_hints.branch')])</span></th>
                        <th><span class="ops-th-label">{{ __('sites.deployments.columns.trigger') }} @include('ops.dashboard._hint', ['text' => __('sites.deployments.column_hints.trigger')])</span></th>
                        <th><span class="ops-th-label">{{ __('sites.deployments.columns.commit') }} @include('ops.dashboard._hint', ['text' => __('sites.deployments.column_hints.commit')])</span></th>
                        <th><span class="ops-th-label">{{ __('sites.deployments.columns.duration') }} @include('ops.dashboard._hint', ['text' => __('sites.deployments.column_hints.duration')])</span></th>
                        <th><span class="ops-th-label">{{ __('sites.deployments.columns.started') }} @include('ops.dashboard._hint', ['text' => __('sites.deployments.column_hints.started')])</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($deployments as $deployment)
                        <tr data-href="{{ route('ops.sites.deployments.show', [$site, $deployment]) }}" tabindex="0">
                            <td>
                                <span class="status-chip status-{{ $deployment->status->value }}">{{ $deployment->status->label() }}</span>
                                @if (filled($deployment->error_message))
                                    <div class="site-slug">{{ $deployment->error_message }}</div>
                                @endif
                            </td>
                            <td><span class="branch-chip">{{ $deployment->channel->value }}</span></td>
                            <td>{{ $deployment->trigger->label() }}</td>
                            <td>
                                @if ($deployment->shortSha() !== '')
                                    <code>{{ $deployment->shortSha() }}</code>
                                @else
                                    <span class="muted">{{ __('ops.none') }}</span>
                                @endif
                            </td>
                            <td>{{ $deployment->durationLabel() }}</td>
                            <td class="muted">{{ $deployment->started_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? __('ops.none') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
