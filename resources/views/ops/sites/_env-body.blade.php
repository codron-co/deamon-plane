@php
    /** @var \App\Models\Site $site */
    /** @var \App\Enums\Channel $channel */
    /** @var \App\Enums\Channel $siteChannel */
    $canOps = $canOps ?? false;
    $rows = $rows ?? [];
    $counts = $counts ?? [];
    $pending = ($counts['missing'] ?? 0) + ($counts['differs'] ?? 0) + ($counts['extra'] ?? 0);
@endphp
{{-- Fetched by public/js/ops-lazy-panels.js. No <script>: injected HTML does not run it. --}}
<article class="site-card site-operation" id="site-env" aria-labelledby="site-env-heading" data-site-env>
    <div class="site-card-head">
        <h3 id="site-env-heading">{{ __('site_env.title') }} @include('ops.dashboard._hint', ['text' => __('site_env.lede')])</h3>
        <div class="branch-version">
            <span class="status-chip">{{ $channel->value }}</span>
            @if ($rows !== [])
                <span class="status-chip" data-env-summary>{{ $pending === 0 ? __('site_env.in_sync') : __('site_env.summary', [
                    'missing' => $counts['missing'] ?? 0,
                    'differs' => $counts['differs'] ?? 0,
                    'extra' => $counts['extra'] ?? 0,
                ]) }}</span>
            @endif
        </div>
    </div>

    @if ($error)
        <p class="ops-alert" role="alert">{{ $error }}</p>
    @endif

    <div class="site-operation-line">
        <form method="GET" action="{{ route('ops.sites.show', $site) }}#infrastructure" class="ops-inline-form">
            <div class="field">
                <label class="field-label" for="site-env-channel">{{ __('site_env.branch') }}</label>
                <select id="site-env-channel" class="field-input" name="env_channel">
                    @foreach ($channels as $option)
                        <option value="{{ $option->value }}" @selected($option === $channel)>
                            {{ $option === $siteChannel ? __('site_env.branch_site', ['branch' => $option->value]) : $option->value }}
                        </option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-ghost btn-sm">{{ __('site_env.compare') }}</button>
        </form>

        @if ($canOps && $error === null)
            <form
                method="POST"
                action="{{ route('ops.sites.env.fix', $site) }}"
                class="ops-inline-form"
                data-ops-pending
                data-confirm="{{ __('site_env.fix.confirm', ['name' => $site->name, 'branch' => $channel->value]) }}"
                data-confirm-title="{{ __('site_env.fix.confirm_title') }}"
                data-confirm-label="{{ __('site_env.fix.button') }}"
                data-confirm-danger="{{ ($counts['extra'] ?? 0) > 0 ? 'true' : 'false' }}"
            >
                @csrf
                <input type="hidden" name="channel" value="{{ $channel->value }}">
                <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('site_env.fix.button') }}</button>
            </form>
        @endif
    </div>

    @if ($rows === [] && $error === null)
        <p class="site-note">{{ __('site_env.empty') }}</p>
    @elseif ($rows !== [])
        <div class="ops-table-wrap">
            <table class="ops-table" data-env-table>
                <thead>
                    <tr>
                        <th>{{ __('site_env.columns.key') }}</th>
                        <th>{{ __('site_env.columns.status') }}</th>
                        <th>{{ __('site_env.columns.value') }}</th>
                        @if ($canOps)
                            <th>{{ __('site_env.columns.actions') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr data-env-key="{{ $row['key'] }}" data-env-status="{{ $row['status'] }}">
                            <td>
                                <code class="ops-mono">{{ $row['key'] }}</code>
                                @if ($row['bootstrap'] && $row['status'] !== 'bootstrap')
                                    <span class="status-chip">{{ __('site_env.bootstrap_badge') }}</span>
                                @endif
                            </td>
                            <td><span class="status-chip">{{ __('site_env.statuses.'.$row['status']) }}</span></td>
                            <td>
                                @if ($row['secret'])
                                    <span class="site-note">{{ $row['has_value'] ? __('site_env.masked') : __('site_env.masked_empty') }}</span>
                                @elseif ($row['value'] === null)
                                    <span class="site-note">{{ __('site_env.not_set') }}</span>
                                @elseif ($row['value'] === '')
                                    <span class="site-note">{{ __('site_env.empty_value') }}</span>
                                @else
                                    <code class="ops-mono">{{ \Illuminate\Support\Str::limit($row['value'], 120) }}</code>
                                @endif
                                @if ($row['expected'] !== null && $row['status'] !== 'ok')
                                    <p class="site-note">{{ __('site_env.expected', ['value' => \Illuminate\Support\Str::limit($row['expected'], 120)]) }}</p>
                                @endif
                            </td>
                            @if ($canOps)
                                <td class="ops-table-actions">
                                    @if ($row['editable'])
                                        <details class="site-env-edit">
                                            <summary class="btn btn-ghost btn-sm">{{ __('site_env.set.edit') }}</summary>
                                            <form
                                                method="POST"
                                                action="{{ route('ops.sites.env.set', $site) }}"
                                                class="ops-inline-form"
                                                data-ops-pending
                                                data-confirm="{{ __('site_env.set.confirm', ['name' => $site->name]) }}"
                                                data-confirm-title="{{ __('site_env.set.confirm_title') }}"
                                                data-confirm-label="{{ __('site_env.set.button') }}"
                                                data-confirm-danger="false"
                                            >
                                                @csrf
                                                <input type="hidden" name="key" value="{{ $row['key'] }}">
                                                <label class="visually-hidden" for="site-env-value-{{ $row['key'] }}">{{ __('site_env.set.value') }}</label>
                                                <input id="site-env-value-{{ $row['key'] }}" class="field-input" type="{{ $row['secret'] ? 'password' : 'text' }}" name="value" value="{{ $row['secret'] ? '' : ($row['value'] ?? $row['expected'] ?? '') }}" autocomplete="off" spellcheck="false">
                                                <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('site_env.set.button') }}</button>
                                            </form>
                                        </details>
                                    @endif
                                    @if ($row['deletable'])
                                        <form
                                            method="POST"
                                            action="{{ route('ops.sites.env.destroy', $site) }}"
                                            class="ops-inline-form"
                                            data-ops-pending
                                            data-confirm="{{ __('site_env.delete.confirm', ['key' => $row['key']]) }}"
                                            data-confirm-title="{{ __('site_env.delete.confirm_title') }}"
                                            data-confirm-label="{{ __('site_env.delete.button') }}"
                                            data-confirm-danger="true"
                                        >
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="key" value="{{ $row['key'] }}">
                                            <button type="submit" class="btn btn-ghost btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('site_env.delete.button') }}</button>
                                        </form>
                                    @endif
                                    @if (! $row['editable'] && ! $row['deletable'])
                                        <span class="site-note">{{ __('site_env.locked') }}</span>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($canOps && $error === null)
        <form
            method="POST"
            action="{{ route('ops.sites.env.set', $site) }}"
            class="ops-form"
            data-ops-pending
            data-confirm="{{ __('site_env.set.confirm', ['name' => $site->name]) }}"
            data-confirm-title="{{ __('site_env.set.confirm_title') }}"
            data-confirm-label="{{ __('site_env.set.button') }}"
            data-confirm-danger="false"
        >
            @csrf
            <h4>{{ __('site_env.set.title') }}</h4>
            <p class="site-note">{{ __('site_env.set.help') }}</p>
            <div class="site-operation-line">
                <div class="field">
                    <label class="field-label" for="site-env-new-key">{{ __('site_env.set.key') }}</label>
                    <input id="site-env-new-key" class="field-input" type="text" name="key" pattern="[A-Z][A-Z0-9_]*" maxlength="128" autocomplete="off" spellcheck="false" required>
                </div>
                <div class="field">
                    <label class="field-label" for="site-env-new-value">{{ __('site_env.set.value') }}</label>
                    <input id="site-env-new-value" class="field-input" type="text" name="value" maxlength="8192" autocomplete="off" spellcheck="false">
                </div>
                <button type="submit" class="btn btn-secondary" data-pending-label="{{ __('ops.actions.working') }}">{{ __('site_env.set.button') }}</button>
            </div>
        </form>

        <div class="form-actions">
            <p class="site-note">{{ __('site_env.redeploy_hint') }}</p>
            <form
                method="POST"
                action="{{ route('ops.sites.deploy', $site) }}"
                data-ops-pending
                data-confirm="{{ __('site_ops.redeploy.confirm', ['name' => $site->name]) }}"
                data-confirm-title="{{ __('site_ops.redeploy.confirm_title') }}"
                data-confirm-label="{{ __('site_ops.redeploy.button') }}"
                data-confirm-danger="false"
            >
                @csrf
                <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('site_ops.redeploy.working') }}">{{ __('site_ops.redeploy.button') }}</button>
            </form>
        </div>
    @endif
</article>
