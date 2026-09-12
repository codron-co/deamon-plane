@extends('layouts.ops')

@section('title', __('platform_mail.title'))

@section('breadcrumbs')
    <a href="{{ route('ops.mail-servers.index') }}">{{ __('mail.title') }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ __('platform_mail.title') }}</span>
@endsection

@section('actions')
    @if ($canWrite)
        <form method="POST" action="{{ route('ops.platform-mail.push') }}" data-ops-pending>
            @csrf
            <button type="submit" class="btn btn-ghost btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('platform_mail.push') }}</button>
        </form>
    @endif
@endsection

@section('content')
    <p class="page-lede">{{ __('platform_mail.lede') }}</p>

    <div class="ops-tabs" style="margin-bottom: 1rem;">
        <a class="btn btn-ghost btn-sm" href="{{ route('ops.mail-servers.index') }}">{{ __('mail.title') }}</a>
        <a class="btn btn-primary btn-sm" href="{{ route('ops.platform-mail.edit') }}">{{ __('platform_mail.title') }}</a>
    </div>

    <form method="POST" action="{{ route('ops.platform-mail.update') }}" class="ops-form ops-form-stack">
        @csrf
        @method('PUT')

        @if ($errors->any())
            <p class="ops-alert" role="alert">{{ __('platform_mail.form_errors') }}</p>
            <ul class="ops-alert-list">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        <article class="site-card">
            <h2>{{ __('platform_mail.smtp.title') }}</h2>
            <p class="field-hint">{{ __('platform_mail.smtp.hint') }}</p>

            <div class="field">
                <label class="field-check">
                    <input type="hidden" name="enabled" value="0">
                    <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $settings->enabled)) @disabled(! $canWrite)>
                    <span>{{ __('platform_mail.fields.enabled') }}</span>
                </label>
            </div>

            <div class="field">
                <label class="field-label" for="pm-host">{{ __('platform_mail.fields.host') }}</label>
                <input id="pm-host" class="field-input" type="text" name="host" value="{{ old('host', $settings->host) }}" @disabled(! $canWrite)>
            </div>
            <div class="field">
                <label class="field-label" for="pm-port">{{ __('platform_mail.fields.port') }}</label>
                <input id="pm-port" class="field-input" type="number" name="port" value="{{ old('port', $settings->port ?: 465) }}" @disabled(! $canWrite)>
            </div>
            <div class="field">
                <label class="field-label" for="pm-encryption">{{ __('platform_mail.fields.encryption') }}</label>
                <select id="pm-encryption" name="encryption" @disabled(! $canWrite)>
                    @foreach (['ssl' => 'SSL / SMTPS (465)', 'tls' => 'STARTTLS', 'none' => 'None'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('encryption', $settings->encryption ?: 'ssl') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label class="field-label" for="pm-username">{{ __('platform_mail.fields.username') }}</label>
                <input id="pm-username" class="field-input" type="text" name="username" value="{{ old('username', $settings->username) }}" autocomplete="off" @disabled(! $canWrite)>
            </div>
            <div class="field">
                <label class="field-label" for="pm-password">{{ __('platform_mail.fields.password') }}</label>
                <p class="field-hint">{{ $settings->exists && $settings->hasPassword() ? __('platform_mail.fields.password_saved') : __('platform_mail.fields.password_hint') }}</p>
                <input id="pm-password" class="field-input" type="password" name="password" value="" autocomplete="new-password" @disabled(! $canWrite)>
            </div>
            <div class="field">
                <label class="field-label" for="pm-from-address">{{ __('platform_mail.fields.from_address') }}</label>
                <input id="pm-from-address" class="field-input" type="email" name="from_address" value="{{ old('from_address', $settings->from_address) }}" @disabled(! $canWrite)>
            </div>
            <div class="field">
                <label class="field-label" for="pm-from-name">{{ __('platform_mail.fields.from_name') }}</label>
                <input id="pm-from-name" class="field-input" type="text" name="from_name" value="{{ old('from_name', $settings->from_name) }}" @disabled(! $canWrite)>
            </div>
            <div class="field">
                <label class="field-label" for="pm-recipient">{{ __('platform_mail.fields.default_admin_recipient') }}</label>
                <p class="field-hint">{{ __('platform_mail.fields.default_admin_recipient_hint') }}</p>
                <input id="pm-recipient" class="field-input" type="email" name="default_admin_recipient" value="{{ old('default_admin_recipient', $settings->default_admin_recipient) }}" @disabled(! $canWrite)>
            </div>
        </article>

        <article class="site-card">
            <h2>{{ __('platform_mail.notifications.title') }}</h2>
            <p class="field-hint">{{ __('platform_mail.notifications.hint') }}</p>

            <div class="sites-table-wrap">
                <table class="ops-table">
                    <thead>
                        <tr>
                            <th>{{ __('platform_mail.notifications.columns.type') }}</th>
                            <th>{{ __('platform_mail.notifications.columns.scope') }}</th>
                            <th>{{ __('platform_mail.notifications.columns.enabled') }}</th>
                            <th>{{ __('platform_mail.notifications.columns.options') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($definitions as $key => $definition)
                            @php($row = $notifications[$key] ?? ['enabled' => false])
                            <tr>
                                <td>
                                    <strong>{{ $definition['label'] }}</strong>
                                    <div class="muted">{{ $definition['description'] }}</div>
                                    <input type="hidden" name="notifications[{{ $key }}][enabled]" value="0">
                                </td>
                                <td class="muted">{{ $definition['scope'] }}</td>
                                <td>
                                    <input type="checkbox" name="notifications[{{ $key }}][enabled]" value="1" @checked(old('notifications.'.$key.'.enabled', $row['enabled'] ?? false)) @disabled(! $canWrite)>
                                </td>
                                <td>
                                    @if ($key === 'weekly_visitor_report')
                                        <label class="muted">{{ __('platform_mail.fields.day') }}
                                            <input class="field-input" type="number" min="0" max="6" name="notifications[{{ $key }}][day]" value="{{ old('notifications.'.$key.'.day', $row['day'] ?? 1) }}" @disabled(! $canWrite)>
                                        </label>
                                        <label class="muted">{{ __('platform_mail.fields.hour') }}
                                            <input class="field-input" type="number" min="0" max="23" name="notifications[{{ $key }}][hour]" value="{{ old('notifications.'.$key.'.hour', $row['hour'] ?? 8) }}" @disabled(! $canWrite)>
                                        </label>
                                    @elseif ($key === 'site_version_update')
                                        <select name="notifications[{{ $key }}][on]" @disabled(! $canWrite)>
                                            @foreach (['patch' => 'patch (x.y.Z)', 'minor' => 'minor (x.Y.z)', 'major' => 'major (X.y.z)'] as $value => $label)
                                                <option value="{{ $value }}" @selected(old('notifications.'.$key.'.on', $row['on'] ?? 'patch') === $value)>{{ $label }}</option>
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
        </article>

        @if ($canWrite)
            <button type="submit" class="btn btn-primary">{{ __('platform_mail.save') }}</button>
        @endif
    </form>

    @if ($canWrite)
        <form method="POST" action="{{ route('ops.platform-mail.test') }}" class="ops-form ops-form-stack" data-ops-pending style="margin-top: 1.5rem;">
            @csrf
            <article class="site-card">
                <h2>{{ __('platform_mail.test.button') }}</h2>
                <div class="field">
                    <label class="field-label" for="pm-test-to">{{ __('platform_mail.test.to') }}</label>
                    <p class="field-hint">{{ __('platform_mail.test.to_hint') }}</p>
                    <input id="pm-test-to" class="field-input" type="email" name="to" value="{{ old('to') }}" placeholder="{{ $settings->default_admin_recipient }}">
                </div>
                <button type="submit" class="btn btn-ghost" data-pending-label="{{ __('ops.actions.working') }}">{{ __('platform_mail.test.button') }}</button>
            </article>
        </form>
    @endif
@endsection
