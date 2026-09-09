@php
    $deployments = $deployments ?? collect();
    $coolifyAppUrl = $coolifyAppUrl ?? null;
@endphp
<section class="deployments-panel" aria-labelledby="deployments-heading">
    <div class="deployments-panel-head">
        <div>
            <h2 id="deployments-heading">Deployments</h2>
            <p>Last {{ $deployments->count() }} recorded deploys. Status comes from Coolify webhooks or the poll job.</p>
        </div>
        @if ($coolifyAppUrl)
            <a class="btn btn-ghost btn-sm" href="{{ $coolifyAppUrl }}" target="_blank" rel="noopener noreferrer">Open in Coolify</a>
        @endif
    </div>

    @if ($deployments->isEmpty())
        <p class="muted">No deployments yet. Provision or a Coolify webhook will create the first row.</p>
    @else
        <div class="sites-table-wrap">
            <table class="ops-table">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Channel</th>
                        <th>Trigger</th>
                        <th>Commit</th>
                        <th>Duration</th>
                        <th>Started</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($deployments as $deployment)
                        <tr>
                            <td>
                                <span class="status-chip status-{{ $deployment->status->value }}">{{ $deployment->status->value }}</span>
                                @if (filled($deployment->error_message))
                                    <div class="site-slug">{{ $deployment->error_message }}</div>
                                @endif
                            </td>
                            <td><span class="channel-chip">{{ $deployment->channel->value }}</span></td>
                            <td>{{ $deployment->trigger->value }}</td>
                            <td>
                                @if ($deployment->shortSha() !== '')
                                    <code>{{ $deployment->shortSha() }}</code>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                            <td>{{ $deployment->durationLabel() }}</td>
                            <td class="muted">{{ $deployment->started_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
