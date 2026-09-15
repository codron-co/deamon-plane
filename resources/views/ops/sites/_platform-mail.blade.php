@php
    /** @var \App\Models\Site $site */
    $canEditMail = $canEdit ?? false;
    $definitions = $platformMailDefinitions ?? \App\Services\Mail\PlatformNotificationCatalog::definitions();
    $effective = $platformMailNotifications ?? \App\Services\Mail\PlatformNotificationCatalog::defaultNotifications();
    $overrides = is_array($site->platform_notification_overrides) ? $site->platform_notification_overrides : [];
@endphp

<article class="site-card site-operation" id="site-platform-mail" aria-labelledby="site-platform-mail-heading">
    <div class="site-card-head">
        <h3 id="site-platform-mail-heading">{{ __('platform_mail.title') }} @include('ops.dashboard._hint', ['text' => __('platform_mail.fields.site_override')])</h3>
        <a class="btn btn-ghost btn-sm" href="{{ route('ops.platform-mail.edit') }}">{{ __('ops.actions.open') }}</a>
    </div>

    @if ($canEditMail)
        <form method="POST" action="{{ route('ops.sites.platform-mail', $site) }}" class="ops-form" data-ops-pending>
            @csrf
            <div class="field">
                <label class="field-label" for="site_platform_recipient">{{ __('platform_mail.fields.site_recipient') }}</label>
                <input id="site_platform_recipient" class="field-input" type="email" name="platform_mail_recipient" value="{{ old('platform_mail_recipient', $site->platform_mail_recipient) }}">
            </div>

            <div class="sites-table-wrap">
                <table class="ops-table">
                    <thead>
                        <tr>
                            <th>{{ __('platform_mail.notifications.columns.type') }}</th>
                            <th>{{ __('platform_mail.notifications.columns.enabled') }}</th>
                            <th>{{ __('platform_mail.notifications.columns.options') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($definitions as $key => $definition)
                            @php
                                $row = $overrides[$key] ?? null;
                                $eff = $effective[$key] ?? ['enabled' => false];
                                $enabledValue = is_array($row) && array_key_exists('enabled', $row)
                                    ? (bool) $row['enabled']
                                    : (bool) ($eff['enabled'] ?? false);
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ $definition['label'] }}</strong>
                                    <div class="muted">{{ $definition['description'] }}</div>
                                    <input type="hidden" name="notifications[{{ $key }}][enabled]" value="0">
                                </td>
                                <td>
                                    <input type="checkbox" name="notifications[{{ $key }}][enabled]" value="1" @checked(old('notifications.'.$key.'.enabled', $enabledValue))>
                                </td>
                                <td>
                                    @if ($key === 'weekly_visitor_report')
                                        @php
                                            $dayValue = (int) old('notifications.'.$key.'.day', $row['day'] ?? $eff['day'] ?? 1);
                                            $hourValue = (int) old('notifications.'.$key.'.hour', $row['hour'] ?? $eff['hour'] ?? 8);
                                        @endphp
                                        {{-- Values stay 0=Sunday … 6=Saturday and 0–23; only the labels are human. --}}
                                        <select class="field-input" name="notifications[{{ $key }}][day]" aria-label="{{ __('platform_mail.fields.weekday') }}">
                                            @foreach ([1, 2, 3, 4, 5, 6, 0] as $day)
                                                <option value="{{ $day }}" @selected($dayValue === $day)>{{ __('platform_mail.weekdays.'.$day) }}</option>
                                            @endforeach
                                        </select>
                                        <select class="field-input" name="notifications[{{ $key }}][hour]" aria-label="{{ __('platform_mail.fields.send_hour') }}">
                                            @foreach (range(0, 23) as $hour)
                                                <option value="{{ $hour }}" @selected($hourValue === $hour)>{{ sprintf('%02d:00', $hour) }}</option>
                                            @endforeach
                                        </select>
                                    @elseif ($key === 'site_version_update')
                                        <select class="field-input" name="notifications[{{ $key }}][on]" aria-label="{{ __('platform_mail.fields.version_threshold') }}">
                                            @foreach (['patch', 'minor', 'major'] as $value)
                                                <option value="{{ $value }}" @selected(old('notifications.'.$key.'.on', $row['on'] ?? $eff['on'] ?? 'patch') === $value)>{{ __('platform_mail.version_thresholds.'.$value) }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <span class="muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('ops.actions.save') }}</button>
        </form>
    @else
        <p class="muted">{{ $site->platform_mail_recipient ?: __('ops.none') }}</p>
    @endif
</article>
