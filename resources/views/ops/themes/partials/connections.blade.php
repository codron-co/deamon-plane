<section class="site-section" aria-labelledby="theme-git-heading">
    <div class="site-section-heading">
        <div>
            <span class="site-section-kicker">{{ __('themes.git.kicker') }}</span>
            <h2 id="theme-git-heading">{{ __('themes.git.title') }} <button class="site-hint" type="button" aria-label="{{ __('themes.git.lede') }}"><span aria-hidden="true">i</span><span role="tooltip">{{ __('themes.git.lede') }}</span></button></h2>
        </div>
    </div>

    @if (! $appUrlIsPublic)
        <p class="ops-alert ops-alert-warning" role="status">{{ __('themes.git.errors.public_url') }}</p>
    @endif

    @if ($connections->isEmpty())
        <div class="empty-panel">
            <h2>{{ __('themes.git.empty.title') }}</h2>
            <p>{{ __('themes.git.empty.hint') }}</p>
            @if ($canWriteGit)
                <div class="form-actions">
                    @if ($hasGithubApp)
                        <form method="POST" action="{{ route('ops.themes.git.connect-another') }}">
                            @csrf
                            <button type="submit" class="btn btn-primary">{{ __('themes.git.connect_another') }}</button>
                        </form>
                    @elseif ($appUrlIsPublic)
                        <form method="POST" action="{{ route('ops.themes.git.connect') }}">
                            @csrf
                            <button type="submit" class="btn btn-primary">{{ __('themes.git.connect') }}</button>
                        </form>
                    @endif
                </div>
            @endif
        </div>
    @else
        <div class="sites-table-wrap">
            <table class="ops-table">
                <thead>
                    <tr>
                        <th>{{ __('themes.git.columns.account') }}</th>
                        <th>{{ __('themes.git.columns.kind') }}</th>
                        <th>{{ __('themes.git.columns.selection') }}</th>
                        <th>{{ __('themes.git.columns.repos') }}</th>
                        <th>{{ __('themes.git.columns.status') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($connections as $connection)
                        <tr data-href="{{ route('ops.themes.git.show', $connection) }}" tabindex="0">
                            <td>
                                <a class="site-name" href="{{ route('ops.themes.git.show', $connection) }}">{{ $connection->displayName() }}</a>
                                <div class="field-hint">{{ $connection->account_type?->label() }} · {{ $connection->account_login }}</div>
                            </td>
                            <td>{{ $connection->kind?->label() }}</td>
                            <td>{{ $connection->selection_mode?->label() }}</td>
                            <td>
                                @if ($connection->usesSelectedRepos())
                                    {{ __('themes.git.repo_selected_count', ['included' => $connection->included_repos_count, 'total' => $connection->repos_count]) }}
                                @else
                                    {{ __('themes.git.repo_all_count', ['count' => $connection->repos_count]) }}
                                @endif
                            </td>
                            <td>
                                <span class="status-chip {{ $connection->status?->chipClass() }}">{{ $connection->status?->label() }}</span>
                            </td>
                            <td class="ops-row-actions">
                                <a class="btn btn-ghost btn-sm" href="{{ route('ops.themes.git.show', $connection) }}">{{ __('ops.actions.open') }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($canWriteGit)
        <details class="coolify-advanced theme-git-pat" @if (! $appUrlIsPublic || $errors->has('account_login') || $errors->has('token')) open @endif>
            <summary>{{ __('themes.git.pat.summary') }}</summary>
            <p class="field-hint">{{ __('themes.git.pat.hint') }}</p>
            <form method="POST" action="{{ route('ops.themes.git.pat') }}" class="ops-form">
                @csrf
                <div class="field">
                    <label class="field-label" for="pat-account-login">{{ __('themes.git.pat.account') }}</label>
                    <p class="field-hint">{{ __('themes.git.pat.account_hint') }}</p>
                    <input id="pat-account-login" class="field-input" type="text" name="account_login" value="{{ old('account_login') }}" autocomplete="off" required>
                    @error('account_login') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label class="field-label" for="pat-account-type">{{ __('themes.git.pat.account_type') }}</label>
                    <select id="pat-account-type" name="account_type" class="field-input" aria-label="{{ __('themes.git.pat.account_type') }}">
                        @foreach ($accountTypes as $type)
                            <option value="{{ $type->value }}" @selected(old('account_type', 'organization') === $type->value)>{{ $type->label() }}</option>
                        @endforeach
                    </select>
                    @error('account_type') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label class="field-label" for="pat-token">{{ __('themes.git.pat.token') }}</label>
                    <p class="field-hint">{{ __('themes.git.pat.token_hint') }}</p>
                    <input id="pat-token" class="field-input" type="password" name="token" value="" autocomplete="new-password" required>
                    @error('token') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label class="field-label" for="pat-name">{{ __('themes.git.fields.name') }}</label>
                    <p class="field-hint">{{ __('themes.git.fields.name_hint') }}</p>
                    <input id="pat-name" class="field-input" type="text" name="name" value="{{ old('name') }}" autocomplete="off">
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label class="field-label" for="pat-prefix">{{ __('themes.git.fields.prefix') }}</label>
                    <p class="field-hint">{{ __('themes.git.fields.prefix_hint') }}</p>
                    <input id="pat-prefix" class="field-input" type="text" name="repo_name_prefix" value="{{ old('repo_name_prefix') }}" autocomplete="off">
                    @error('repo_name_prefix') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label class="field-label" for="pat-selection">{{ __('themes.git.fields.selection') }}</label>
                    <select id="pat-selection" name="selection_mode" class="field-input" aria-label="{{ __('themes.git.fields.selection') }}">
                        @foreach ($selectionModes as $mode)
                            <option value="{{ $mode->value }}" @selected(old('selection_mode', 'all') === $mode->value)>{{ $mode->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-secondary">{{ __('themes.git.pat.submit') }}</button>
                </div>
            </form>
        </details>
    @endif
</section>
