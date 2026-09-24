{{-- Audit P13: runtime switches for the actions Plane takes without a human. --}}
<section
    class="settings-panel"
    aria-labelledby="automation-heading"
    data-settings-section
    data-settings-haystack="{{ \App\Support\Ops\SettingsJump::haystackFor('automation') }}"
>
    <h2 id="automation-heading">{{ __('settings.automation.title') }}</h2>
    <p class="field-hint">{{ __('settings.automation.lede') }}</p>

    <div class="ops-table-wrap">
        <table class="ops-table">
            <thead>
                <tr>
                    <th scope="col">{{ __('settings.automation.rule') }}</th>
                    <th scope="col">{{ __('settings.automation.state') }}</th>
                    <th scope="col">{{ __('settings.automation.budget') }}</th>
                    @if ($canEditAutomation)
                        <th scope="col"><span class="sr-only">{{ __('settings.automation.action') }}</span></th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($automationRules as $row)
                    @php
                        $ruleName = __('settings.automation.rules.'.$row['rule'].'.name');
                    @endphp
                    <tr>
                        <td>
                            <strong>{{ $ruleName }}</strong>
                            <div class="field-hint">{{ __('settings.automation.rules.'.$row['rule'].'.hint') }}</div>
                        </td>
                        <td>
                            @if (! $row['env_allows'])
                                <span class="status-chip status-stopped">{{ __('settings.automation.env_off') }}</span>
                            @elseif (! $row['switched_on'])
                                <span class="status-chip status-warning">{{ __('settings.automation.off') }}</span>
                            @elseif ($row['paused_until'] !== null)
                                <span class="status-chip status-warning">{{ __('settings.automation.paused', ['time' => \Illuminate\Support\Carbon::createFromTimestamp($row['paused_until'])->timezone(config('app.timezone'))->format('H:i')]) }}</span>
                            @else
                                <span class="status-chip status-active">{{ __('settings.automation.on') }}</span>
                            @endif
                        </td>
                        <td>{{ __('settings.automation.budget_value', ['site' => $row['per_site_per_day'], 'fleet' => $row['fleet_per_hour']]) }}</td>
                        @if ($canEditAutomation)
                            <td>
                                @if ($row['env_allows'])
                                    <form
                                        method="POST"
                                        action="{{ route('ops.settings.automation', $row['rule']) }}"
                                        data-confirm="{{ __($row['switched_on'] ? 'settings.automation.confirm_off' : 'settings.automation.confirm_on', ['rule' => $ruleName]) }}"
                                        data-confirm-title="{{ __('settings.automation.title') }}"
                                        data-confirm-label="{{ __($row['switched_on'] ? 'settings.automation.turn_off' : 'settings.automation.turn_on') }}"
                                        data-confirm-danger="false"
                                    >
                                        @csrf
                                        <input type="hidden" name="enabled" value="{{ $row['switched_on'] ? '0' : '1' }}">
                                        <button type="submit" class="btn btn-secondary btn-sm">{{ __($row['switched_on'] ? 'settings.automation.turn_off' : 'settings.automation.turn_on') }}</button>
                                    </form>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @unless ($canEditAutomation)
        <p class="field-hint">{{ __('ops.super_admin_only') }}</p>
    @endunless
</section>
