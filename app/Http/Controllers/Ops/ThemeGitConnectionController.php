<?php

namespace App\Http\Controllers\Ops;

use App\Enums\ThemeGitAccountType;
use App\Enums\ThemeGitSelectionMode;
use App\Http\Controllers\Controller;
use App\Models\GithubSetting;
use App\Models\ThemeGitConnection;
use App\Services\GitHub\GitHubApiException;
use App\Services\GitHub\GitHubCredentialsException;
use App\Services\Themes\ThemeGitConnectionService;
use App\Support\PublicAppUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ThemeGitConnectionController extends Controller
{
    public function __construct(
        private readonly ThemeGitConnectionService $connections,
    ) {}

    public function connect(Request $request): View|RedirectResponse
    {
        $this->authorize('create', ThemeGitConnection::class);

        if (GithubSetting::current()->hasManifestApp()) {
            return $this->connectAnother($request);
        }

        try {
            $state = $this->connections->beginManifest();
        } catch (GitHubCredentialsException $exception) {
            return redirect()
                ->route('ops.themes')
                ->with('error', $exception->getMessage());
        }

        return view('ops.themes.git.manifest', [
            'action' => $this->connections->githubWebBase().'/settings/apps/new?state='.rawurlencode($state),
            'manifest' => json_encode($this->connections->manifestPayload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'state' => $state,
        ]);
    }

    public function callback(Request $request): RedirectResponse
    {
        $this->authorize('create', ThemeGitConnection::class);

        try {
            $settings = $this->connections->convertManifest($request);
            $state = $this->connections->beginInstall();
            $url = $this->connections->installUrl($settings, $state);
        } catch (GitHubCredentialsException|GitHubApiException $exception) {
            return redirect()
                ->route('ops.themes')
                ->with('error', $exception->getMessage());
        }

        return redirect()->away($url);
    }

    public function connectAnother(Request $request): RedirectResponse
    {
        $this->authorize('create', ThemeGitConnection::class);

        try {
            $state = $this->connections->beginInstall();
            $url = $this->connections->installUrl(GithubSetting::current(), $state);
        } catch (GitHubCredentialsException $exception) {
            return redirect()
                ->route('ops.themes')
                ->with('error', $exception->getMessage());
        }

        return redirect()->away($url);
    }

    public function installed(Request $request): RedirectResponse
    {
        $this->authorize('create', ThemeGitConnection::class);

        try {
            $connection = $this->connections->recordInstallation($request);
        } catch (GitHubCredentialsException|GitHubApiException $exception) {
            return redirect()
                ->route('ops.themes')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('ops.themes.git.show', $connection)
            ->with('status', __('themes.git.flash.installed', ['account' => $connection->displayName()]));
    }

    public function storePat(Request $request): RedirectResponse
    {
        $this->authorize('create', ThemeGitConnection::class);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'account_login' => ['required', 'string', 'max:120'],
            'account_type' => ['required', Rule::enum(ThemeGitAccountType::class)],
            'token' => ['required', 'string', 'max:4000'],
            'repo_name_prefix' => ['nullable', 'string', 'max:80'],
            'selection_mode' => ['nullable', Rule::enum(ThemeGitSelectionMode::class)],
        ]);

        $connection = $this->connections->connectPat($validated);

        return redirect()
            ->route('ops.themes.git.show', $connection)
            ->with('status', __('themes.git.flash.pat', ['account' => $connection->displayName()]));
    }

    public function show(Request $request, ThemeGitConnection $connection): View
    {
        $this->authorize('view', $connection);

        $connection->load(['repos' => static fn ($query) => $query->orderBy('repo_full_name')]);

        return view('ops.themes.git.show', [
            'connection' => $connection,
            'selectionModes' => ThemeGitSelectionMode::cases(),
            'canWrite' => $request->user()?->can('update', $connection) ?? false,
            'appIsPublic' => PublicAppUrl::isPublic(),
        ]);
    }

    public function update(Request $request, ThemeGitConnection $connection): RedirectResponse
    {
        $this->authorize('update', $connection);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'selection_mode' => ['required', Rule::enum(ThemeGitSelectionMode::class)],
            'repo_name_prefix' => ['nullable', 'string', 'max:80'],
            'included' => ['nullable', 'array'],
            'included.*' => ['string', 'max:200'],
        ]);

        $this->connections->updateConnection($connection, $validated);

        return back()->with('status', __('themes.git.flash.updated'));
    }

    public function syncRepos(Request $request, ThemeGitConnection $connection): RedirectResponse
    {
        $this->authorize('update', $connection);

        try {
            $count = $this->connections->syncRepos($connection);
        } catch (GitHubCredentialsException|GitHubApiException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', __('themes.git.flash.repos', ['count' => $count]));
    }

    public function destroy(Request $request, ThemeGitConnection $connection): RedirectResponse
    {
        $this->authorize('delete', $connection);

        $name = $connection->displayName();
        $this->connections->disconnect($connection);

        return redirect()
            ->route('ops.themes')
            ->with('status', __('themes.git.flash.disconnected', ['account' => $name]));
    }
}
