@extends('layouts.ops')

@section('title', $theme->displayName())

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.themes') }}">Back to catalog</a>
@endsection

@section('content')
    <p class="page-lede">
        Git repo <code>{{ $theme->repo_full_name }}</code>.
        Installs go through the signed CMS theme agent — not Coolify volume copy, not ZIP.
    </p>

    <section class="settings-panel" aria-labelledby="theme-manifest-heading">
        <h2 id="theme-manifest-heading">Manifest</h2>
        <dl class="spec-list">
            <div>
                <dt>Theme id</dt>
                <dd><code>{{ $theme->theme_id }}</code></dd>
            </div>
            <div>
                <dt>Default ref</dt>
                <dd><code>{{ $theme->default_ref }}</code></dd>
            </div>
            <div>
                <dt>Latest SHA</dt>
                <dd><code>{{ $theme->latest_sha ?: '—' }}</code></dd>
            </div>
            <div>
                <dt>Minimum Deamon</dt>
                <dd>{{ $theme->minimum_deamon_version ?: 'none' }}</dd>
            </div>
            <div>
                <dt>Last synced</dt>
                <dd>{{ $theme->last_synced_at?->toDateTimeString() ?? 'never' }}</dd>
            </div>
        </dl>
        @if ($theme->description)
            <p class="field-hint">{{ $theme->description }}</p>
        @endif
    </section>

    <section class="settings-panel" aria-labelledby="theme-visibility-heading">
        <h2 id="theme-visibility-heading">Visibility</h2>
        <form method="POST" action="{{ route('ops.themes.update', $theme) }}" class="ops-form settings-form">
            @csrf
            @method('PUT')
            <div class="field">
                <label class="field-label" for="theme-visibility">Catalog visibility</label>
                <p class="field-hint">public_catalog = any managed site. allowlist = theme_site_access. private = operator can still assign explicitly.</p>
                <select id="theme-visibility" class="field-input" name="visibility" @disabled(! $canWrite)>
                    @foreach ($visibilities as $option)
                        <option value="{{ $option->value }}" @selected(old('visibility', $theme->visibility?->value) === $option->value)>{{ $option->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label class="field-label" for="theme-default-ref">Default ref</label>
                <input id="theme-default-ref" class="field-input" type="text" name="default_ref" value="{{ old('default_ref', $theme->default_ref) }}" @disabled(! $canWrite)>
            </div>
            @if ($canWrite)
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Save visibility</button>
                </div>
            @endif
        </form>
    </section>

    <section class="settings-panel" aria-labelledby="theme-access-heading">
        <h2 id="theme-access-heading">Allowlist</h2>
        @if ($theme->allowedSites->isEmpty())
            <p class="field-hint">No sites on the allowlist. Required only when visibility is allowlist.</p>
        @else
            <table class="ops-table">
                <thead>
                    <tr>
                        <th>Site</th>
                        <th>Slug</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($theme->allowedSites as $allowed)
                        <tr>
                            <td><a href="{{ route('ops.sites.edit', $allowed) }}">{{ $allowed->name }}</a></td>
                            <td><code>{{ $allowed->slug }}</code></td>
                            <td class="ops-row-actions">
                                @if ($canWrite)
                                    <form
                                        method="POST"
                                        action="{{ route('ops.themes.access.destroy', [$theme, $allowed]) }}"
                                        data-confirm="Remove {{ $allowed->name }} from this theme allowlist?"
                                        data-confirm-title="Revoke access"
                                        data-confirm-label="Remove"
                                    >
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-ghost btn-sm btn-danger-text">Remove</button>
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
                    <label class="field-label" for="theme-access-site">Add site</label>
                    <select id="theme-access-site" class="field-input" name="site_id" required>
                        <option value="">Select a site</option>
                        @foreach ($sites as $candidate)
                            <option value="{{ $candidate->id }}">{{ $candidate->name }} ({{ $candidate->slug }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-secondary">Grant access</button>
                </div>
            </form>
        @endif
    </section>

    <section class="settings-panel" aria-labelledby="theme-installs-heading">
        <h2 id="theme-installs-heading">Installations</h2>
        @if ($theme->installations->isEmpty())
            <p class="field-hint">No site has this theme yet. Assign from a site Themes tab.</p>
        @else
            <table class="ops-table">
                <thead>
                    <tr>
                        <th>Site</th>
                        <th>Ref</th>
                        <th>Status</th>
                        <th>Active</th>
                        <th>Auto-update</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($theme->installations as $installation)
                        <tr>
                            <td>
                                @if ($installation->site)
                                    <a href="{{ route('ops.sites.edit', $installation->site) }}">{{ $installation->site->name }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td><code>{{ $installation->ref }}</code></td>
                            <td>{{ $installation->status?->value }}</td>
                            <td>{{ $installation->is_active ? 'yes' : 'no' }}</td>
                            <td>{{ $installation->auto_update ? 'on' : 'off' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endsection
