@extends('layouts.ops')

@section('title', $theme->displayName())

@section('breadcrumbs')
    <a href="{{ route('ops.themes') }}">{{ __('themes.title') }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ $theme->displayName() }}</span>
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.themes') }}">{{ __('themes.back') }}</a>
@endsection

@section('content')
    <p class="page-lede">{{ __('themes.show.lede', ['repo' => $theme->repo_full_name]) }}</p>

    <section class="settings-panel" aria-labelledby="theme-manifest-heading">
        <h2 id="theme-manifest-heading">{{ __('themes.show.manifest') }}</h2>
        <dl class="spec-list">
            <div>
                <dt>{{ __('themes.show.theme_id') }}</dt>
                <dd><code>{{ $theme->theme_id }}</code></dd>
            </div>
            <div>
                <dt>{{ __('themes.show.default_ref') }}</dt>
                <dd><code>{{ $theme->default_ref }}</code></dd>
            </div>
            <div>
                <dt>{{ __('themes.show.sha') }}</dt>
                <dd><code>{{ $theme->latest_sha ?: __('ops.none') }}</code></dd>
            </div>
            <div>
                <dt>{{ __('themes.show.min') }}</dt>
                <dd>{{ $theme->minimum_deamon_version ?: __('themes.show.min_none') }}</dd>
            </div>
            <div>
                <dt>{{ __('themes.show.synced') }}</dt>
                <dd>{{ $theme->last_synced_at?->toDateTimeString() ?? __('ops.never') }}</dd>
            </div>
        </dl>
        @if ($theme->description)
            <p class="field-hint">{{ $theme->description }}</p>
        @endif
    </section>

    <section class="settings-panel" aria-labelledby="theme-visibility-heading">
        <h2 id="theme-visibility-heading">{{ __('themes.show.visibility') }}</h2>
        <form method="POST" action="{{ route('ops.themes.update', $theme) }}" class="ops-form settings-form">
            @csrf
            @method('PUT')
            <div class="field">
                <label class="field-label" for="theme-visibility">{{ __('themes.show.catalog_visibility') }}</label>
                <p class="field-hint">{{ __('themes.show.visibility_hint') }}</p>
                <select id="theme-visibility" class="field-input" name="visibility" @disabled(! $canWrite)>
                    @foreach ($visibilities as $option)
                        <option value="{{ $option->value }}" @selected(old('visibility', $theme->visibility?->value) === $option->value)>{{ $option->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label class="field-label" for="theme-default-ref">{{ __('themes.show.default_ref') }}</label>
                <input id="theme-default-ref" class="field-input" type="text" name="default_ref" value="{{ old('default_ref', $theme->default_ref) }}" @disabled(! $canWrite)>
            </div>
            @if ($canWrite)
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">{{ __('themes.show.save') }}</button>
                </div>
            @endif
        </form>
    </section>

    <section class="settings-panel" aria-labelledby="theme-access-heading">
        <h2 id="theme-access-heading">{{ __('themes.show.allowlist') }}</h2>
        @if ($theme->allowedSites->isEmpty())
            <p class="field-hint">{{ __('themes.show.allowlist_empty') }}</p>
        @else
            <table class="ops-table">
                <thead>
                    <tr>
                        <th>{{ __('themes.show.site') }}</th>
                        <th>{{ __('themes.show.slug') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($theme->allowedSites as $allowed)
                        <tr>
                            <td><a href="{{ route('ops.sites.show', $allowed) }}">{{ $allowed->name }}</a></td>
                            <td><code>{{ $allowed->slug }}</code></td>
                            <td class="ops-row-actions">
                                @if ($canWrite)
                                    <form
                                        method="POST"
                                        action="{{ route('ops.themes.access.destroy', [$theme, $allowed]) }}"
                                        data-confirm="{{ __('themes.show.revoke_confirm', ['name' => $allowed->name]) }}"
                                        data-confirm-title="{{ __('themes.show.revoke_title') }}"
                                        data-confirm-label="{{ __('themes.show.remove') }}"
                                    >
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-ghost btn-sm btn-danger-text">{{ __('themes.show.remove') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if ($canWrite)
            <form method="POST" action="{{ route('ops.themes.access.store', $theme) }}" class="ops-form settings-form">
                @csrf
                <div class="field">
                    <label class="field-label" for="theme-access-site">{{ __('themes.show.add_site') }}</label>
                    <select id="theme-access-site" class="field-input" name="site_id" required>
                        <option value="">{{ __('themes.show.select_site') }}</option>
                        @foreach ($sites as $candidate)
                            <option value="{{ $candidate->id }}">{{ $candidate->name }} ({{ $candidate->slug }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-secondary">{{ __('themes.show.grant') }}</button>
                </div>
            </form>
        @endif
    </section>

    <section class="settings-panel" aria-labelledby="theme-installs-heading">
        <h2 id="theme-installs-heading">{{ __('themes.show.installs') }}</h2>
        @if ($theme->installations->isEmpty())
            <p class="field-hint">{{ __('themes.show.installs_empty') }}</p>
        @else
            <table class="ops-table">
                <thead>
                    <tr>
                        <th>{{ __('themes.show.site') }}</th>
                        <th>{{ __('themes.show.ref') }}</th>
                        <th>{{ __('themes.show.status') }}</th>
                        <th>{{ __('themes.show.active') }}</th>
                        <th>{{ __('themes.show.auto_update') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($theme->installations as $installation)
                        <tr>
                            <td>
                                @if ($installation->site)
                                    <a href="{{ route('ops.sites.show', $installation->site) }}">{{ $installation->site->name }}</a>
                                @else
                                    {{ __('ops.none') }}
                                @endif
                            </td>
                            <td><code>{{ $installation->ref }}</code></td>
                            <td>{{ $installation->status?->label() ?? $installation->status?->value }}</td>
                            <td>{{ $installation->is_active ? __('ops.yes') : __('ops.no') }}</td>
                            <td>{{ $installation->auto_update ? __('ops.on') : __('ops.off') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endsection
