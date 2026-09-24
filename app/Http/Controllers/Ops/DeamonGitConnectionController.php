<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\GithubSetting;
use App\Services\GitHub\DeamonGitConnectionService;
use App\Services\GitHub\GitHubApiException;
use App\Services\GitHub\GitHubCredentialsException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DeamonGitConnectionController extends Controller
{
    public function __construct(
        private readonly DeamonGitConnectionService $deamonGit,
    ) {}

    public function connect(Request $request): View|RedirectResponse
    {
        $this->authorize('ops.write');

        if (GithubSetting::current()->hasManifestApp()) {
            return $this->connectInstall($request);
        }

        try {
            $state = $this->deamonGit->beginManifest();
        } catch (GitHubCredentialsException $exception) {
            return redirect()
                ->route('ops.settings')
                ->withFragment('deamon-git-heading')
                ->with('error', $exception->getMessage());
        }

        return view('ops.settings.deamon-git.manifest', [
            'action' => $this->deamonGit->githubWebBase().'/settings/apps/new?state='.rawurlencode($state),
            'manifest' => json_encode($this->deamonGit->manifestPayload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'state' => $state,
        ]);
    }

    public function callback(Request $request): RedirectResponse
    {
        $this->authorize('ops.write');

        try {
            $settings = $this->deamonGit->convertManifest($request);
            $state = $this->deamonGit->beginInstall();
            $url = $this->deamonGit->installUrl($settings, $state);
        } catch (GitHubCredentialsException|GitHubApiException $exception) {
            return redirect()
                ->route('ops.settings')
                ->withFragment('deamon-git-heading')
                ->with('error', $exception->getMessage());
        }

        return redirect()->away($url);
    }

    public function connectInstall(Request $request): RedirectResponse
    {
        $this->authorize('ops.write');

        try {
            $state = $this->deamonGit->beginInstall();
            $url = $this->deamonGit->installUrl(GithubSetting::current(), $state);
        } catch (GitHubCredentialsException $exception) {
            return redirect()
                ->route('ops.settings')
                ->withFragment('deamon-git-heading')
                ->with('error', $exception->getMessage());
        }

        return redirect()->away($url);
    }

    public function installed(Request $request): RedirectResponse
    {
        $this->authorize('ops.write');

        try {
            $settings = $this->deamonGit->recordInstallation($request);
        } catch (GitHubCredentialsException|GitHubApiException $exception) {
            return redirect()
                ->route('ops.settings')
                ->withFragment('deamon-git-heading')
                ->with('error', $exception->getMessage());
        }

        $account = $settings->deamonGitAccountLabel() ?? __('settings.deamon_git.unknown_account');

        return redirect()
            ->route('ops.settings')
            ->withFragment('deamon-git-heading')
            ->with('status', __('settings.deamon_git.flash.installed', ['account' => $account]));
    }

    public function disconnect(Request $request): RedirectResponse
    {
        // The env catalog of every channel is read through this connection.
        $this->authorize('ops.danger');

        $this->deamonGit->disconnect();

        return redirect()
            ->route('ops.settings')
            ->withFragment('deamon-git-heading')
            ->with('status', __('settings.deamon_git.flash.disconnected'));
    }

    public function storePat(Request $request): RedirectResponse
    {
        $this->authorize('ops.write');

        $validated = $request->validate([
            'token' => ['required', 'string', 'max:4000'],
        ]);

        $this->deamonGit->savePat($validated);

        return redirect()
            ->route('ops.settings')
            ->withFragment('deamon-git-heading')
            ->with('status', __('settings.deamon_git.flash.pat'));
    }

    public function clearPat(Request $request): RedirectResponse
    {
        $this->authorize('ops.danger');

        $this->deamonGit->clearPat();

        return redirect()
            ->route('ops.settings')
            ->withFragment('deamon-git-heading')
            ->with('status', __('settings.deamon_git.flash.pat_cleared'));
    }
}
