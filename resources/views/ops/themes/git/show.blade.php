@extends('layouts.ops')

@section('title', $connection->displayName())

@section('content_class', 'ops-content-wide')

@section('breadcrumbs')
    <a href="{{ route('ops.themes') }}">{{ __('themes.title') }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ $connection->displayName() }}</span>
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.themes') }}">{{ __('themes.back') }}</a>
    @if ($canWrite)
        <form method="POST" action="{{ route('ops.themes.git.sync-repos', $connection) }}" data-ops-pending>
            @csrf
            <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('themes.git.sync_repos') }}</button>
        </form>
    @endif
@endsection

@section('content')
    <header class="site-hero">
        <div class="site-hero-main">
            <div class="site-identity-mark" aria-hidden="true">{{ \App\Support\IdentityMark::letter($connection->displayName()) }}</div>
            <div class="site-identity-copy">
                <div class="site-title-row">
                    <h2>{{ $connection->displayName() }}</h2>
                    <span class="status-chip {{ $connection->status?->chipClass() }}">{{ $connection->status?->label() }}</span>
                </div>
                <div class="site-domain-row">
                    <span>{{ $connection->account_type?->label() }}</span>
                    <span aria-hidden="true">·</span>
                    <code>{{ $connection->account_login }}</code>
                    <span aria-hidden="true">·</span>
                    <span>{{ $connection->kind?->label() }}</span>
                </div>
            </div>
        </div>
    </header>

    @if ($connection->status === \App\Enums\ThemeGitConnectionStatus::Error && filled($connection->last_error))
        <p class="ops-alert site-banner" role="alert">{{ $connection->last_error }}</p>
    @endif

    <section class="site-section" aria-labelledby="theme-git-overview-heading">
        <div class="site-section-heading">
            <div>
                <span class="site-section-kicker">{{ __('themes.git.show.kicker') }}</span>
                <h2 id="theme-git-overview-heading">{{ __('themes.git.show.overview') }}</h2>
            </div>
        </div>
        <dl class="site-fact-list">
            <div>
                <dt>{{ __('themes.git.columns.selection') }}</dt>
                <dd>{{ $connection->selection_mode?->label() }}</dd>
            </div>
            <div>
                <dt>{{ __('themes.git.fields.prefix') }}</dt>
                <dd><code>{{ $connection->normalizedPrefix() ?: __('themes.git.fields.prefix_none') }}</code></dd>
            </div>
            @if ($connection->isGithubApp())
                <div>
                    <dt>{{ __('themes.git.show.installation') }}</dt>
                    <dd><code>{{ $connection->installation_id ?: __('ops.none') }}</code></dd>
                </div>
            @endif
            <div>
                <dt>{{ __('themes.git.columns.repos') }}</dt>
                <dd>{{ __('themes.git.repo_selected_count', ['included' => $connection->repos->where('included', true)->count(), 'total' => $connection->repos->count()]) }}</dd>
            </div>
        </dl>
    </section>

    @if ($canWrite)
        <section class="site-section" aria-labelledby="theme-git-settings-heading">
            <div class="site-section-heading">
                <div>
                    <span class="site-section-kicker">{{ __('themes.git.show.settings_kicker') }}</span>
                    <h2 id="theme-git-settings-heading">{{ __('themes.git.show.settings') }}</h2>
                </div>
            </div>
            <form method="POST" action="{{ route('ops.themes.git.update', $connection) }}" class="ops-form" data-theme-git-settings>
                @csrf
                @method('PATCH')
                <div class="field">
                    <label class="field-label" for="connection-name">{{ __('themes.git.fields.name') }}</label>
                    <p class="field-hint">{{ __('themes.git.fields.name_hint') }}</p>
                    <input id="connection-name" class="field-input" type="text" name="name" value="{{ old('name', $connection->name) }}" autocomplete="off">
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label class="field-label" for="connection-selection">{{ __('themes.git.fields.selection') }}</label>
                    <p class="field-hint">{{ __('themes.git.fields.selection_hint') }}</p>
                    <select id="connection-selection" name="selection_mode" class="field-input" data-theme-git-selection aria-label="{{ __('themes.git.fields.selection') }}">
                        @foreach ($selectionModes as $mode)
                            <option value="{{ $mode->value }}" @selected(old('selection_mode', $connection->selection_mode?->value) === $mode->value)>{{ $mode->label() }}</option>
                        @endforeach
                    </select>
                    @error('selection_mode') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label class="field-label" for="connection-prefix">{{ __('themes.git.fields.prefix') }}</label>
                    <p class="field-hint">{{ __('themes.git.fields.prefix_hint') }}</p>
                    <input id="connection-prefix" class="field-input" type="text" name="repo_name_prefix" value="{{ old('repo_name_prefix', $connection->repo_name_prefix) }}" autocomplete="off">
                    @error('repo_name_prefix') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div class="theme-git-repos" data-theme-git-repos>
                    <h3>{{ __('themes.git.repos.title') }}</h3>
                    <p class="field-hint">{{ __('themes.git.repos.hint') }}</p>
                    @if ($connection->repos->isEmpty())
                        <div class="empty-panel">
                            <h2>{{ __('themes.git.repos.empty_title') }}</h2>
                            <p>{{ __('themes.git.repos.empty_hint') }}</p>
                        </div>
                    @else
                        <div class="sites-table-wrap">
                            <table class="ops-table">
                                <thead>
                                    <tr>
                                        <th class="ops-check-col">{{ __('themes.git.repos.include') }}</th>
                                        <th>{{ __('themes.git.repos.repo') }}</th>
                                        <th>{{ __('themes.git.repos.branch') }}</th>
                                        <th>{{ __('themes.git.repos.visibility') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($connection->repos as $repo)
                                        <tr>
                                            <td class="ops-check-col">
                                                <label class="field-check">
                                                    <span class="visually-hidden">{{ __('themes.git.repos.include_named', ['repo' => $repo->repo_full_name]) }}</span>
                                                    <input
                                                        type="checkbox"
                                                        name="included[]"
                                                        value="{{ $repo->repo_full_name }}"
                                                        @checked($repo->included)
                                                        data-theme-git-include
                                                    >
                                                </label>
                                            </td>
                                            <td>
                                                <code>{{ $repo->repo_full_name }}</code>
                                            </td>
                                            <td><span class="branch-chip">{{ $repo->default_branch ?: __('ops.none') }}</span></td>
                                            <td>
                                                <span class="status-chip">{{ $repo->is_private ? __('themes.git.repos.private') : __('themes.git.repos.public') }}</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">{{ __('themes.git.show.save') }}</button>
                </div>
            </form>
        </section>

        <section class="danger-zone" aria-labelledby="theme-git-disconnect-heading">
            <h2 id="theme-git-disconnect-heading">{{ __('themes.git.disconnect.title') }}</h2>
            <p>{{ __('themes.git.disconnect.hint') }}</p>
            <form method="POST" action="{{ route('ops.themes.git.destroy', $connection) }}" data-confirm="{{ __('themes.git.disconnect.confirm', ['account' => $connection->displayName()]) }}" data-confirm-title="{{ __('themes.git.disconnect.title') }}" data-confirm-label="{{ __('themes.git.disconnect.action') }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">{{ __('themes.git.disconnect.action') }}</button>
            </form>
        </section>
    @endif
@endsection

@section('scripts')
    <script src="{{ asset('js/theme-git.js') }}?v={{ filemtime(public_path('js/theme-git.js')) }}" defer></script>
@endsection
