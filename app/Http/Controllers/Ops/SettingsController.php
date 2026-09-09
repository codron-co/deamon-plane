<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\GithubSetting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class SettingsController extends Controller
{
    public function index(): View
    {
        $github = GithubSetting::current();

        return view('ops.settings.index', [
            'composeFile' => config('ops.deamon.compose_file'),
            'repository' => config('ops.deamon.repository'),
            'canWrite' => request()->user()?->can('ops.write') ?? false,
            'githubOrg' => $github->org ?: config('ops.themes.org'),
            'githubHasToken' => $github->hasToken() || filled(config('ops.github.token')),
            'githubHasApp' => $github->hasAppCredentials()
                || (filled(config('ops.github.app_id')) && filled(config('ops.github.private_key'))),
            'githubHasWebhookSecret' => $github->hasWebhookSecret() || filled(config('ops.github.webhook_secret')),
            'githubAppId' => $github->app_id ?: config('ops.github.app_id'),
            'githubInstallationId' => $github->installation_id ?: config('ops.github.installation_id'),
            'githubWebhookUrl' => url('/webhooks/github'),
            'themeRepoPrefix' => config('ops.themes.repo_prefix'),
        ]);
    }

    public function update(): RedirectResponse
    {
        $this->authorize('ops.write');

        return redirect()
            ->route('ops.coolify.index')
            ->with('status', 'Coolify is under Coolify menu.');
    }

    public function testConnection(): RedirectResponse
    {
        $this->authorize('ops.write');

        return redirect()
            ->route('ops.coolify.index')
            ->with('status', 'Coolify is under Coolify menu.');
    }
}
