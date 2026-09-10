@php
    $deployments = $deployments ?? collect();
    $coolifyAppUrl = $coolifyAppUrl ?? null;
@endphp
<section class="deployments-panel" aria-labelledby="deployments-heading">
    <div class="deployments-panel-head">
        <div>
            <h2 id="deployments-heading">{{ __('sites.deployments.title') }}</h2>
            <p>{{ __('sites.deployments.lede', ['count' => $deployments->count()]) }}</p>
        </div>
        @if ($coolifyAppUrl)
            <a class="btn btn-ghost btn-sm" href="{{ $coolifyAppUrl }}" target="_blank" rel="noopener noreferrer">{{ __('sites.deployments.open_coolify') }}</a>
        @endif
    </div>

    @if ($deployments->isEmpty())
        <p class="muted">{{ __('sites.deployments.empty') }}</p>
    @else
        <div class="sites-table-wrap">
            <table class="ops-table">
                <thead>
                    <tr>
                        <th>{{ __('sites.deployments.columns.status') }}</th>
                        <th>{{ __('sites.deployments.columns.branch') }}</th>
                        <th>{{ __('sites.deployments.columns.trigger') }}</th>
                        <th>{{ __('sites.deployments.columns.commit') }}</th>
                        <th>{{ __('sites.deployments.columns.duration') }}</th>
                        <th>{{ __('sites.deployments.columns.started') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($deployments as $deployment)
                        <tr>
                            <td>
                                <span class="status-chip status-{{ $deployment->status->value }}">{{ $deployment->status->label() }}</span>
                                @if (filled($deployment->error_message))
                                    <div class="site-slug">{{ $deployment->error_message }}</div>
                                @endif
                            </td>
                            <td><span class="channel-chip">{{ $deployment->channel->value }}</span></td>
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
