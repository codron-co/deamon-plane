<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\CoolifySetting;
use App\Models\GithubSetting;
use App\Services\Coolify\CoolifyCredentials;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class SettingsController extends Controller
{
    public function index(): View
    {
        $settings = CoolifySetting::current();
        $credentials = CoolifyCredentials::resolve($settings);
        $github = GithubSetting::current();

        return view('ops.settings.index', [
            'settings' => $settings,
            'coolifyBaseUrl' => $settings->base_url ?: config('ops.coolify.base_url'),
            'projectUuid' => $settings->default_project_uuid ?: config('ops.coolify.default_project_uuid'),
            'serverUuid' => $settings->default_server_uuid ?: config('ops.coolify.default_server_uuid'),
            'githubAppUuid' => $settings->github_app_uuid,
            'privateKeyUuid' => $settings->private_key_uuid,
            'hasToken' => $credentials->hasToken(),
            'hasWebhookSecret' => $settings->hasWebhookSecret() || filled(config('ops.coolify.webhook_secret')),
            'webhookUrl' => url('/webhooks/coolify'),
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
            ->with('status', 'Coolify ayarları Coolify menüsüne taşındı.');
    }

    public function testConnection(): RedirectResponse
    {
        $this->authorize('ops.write');

        return redirect()
            ->route('ops.coolify.index')
            ->with('status', 'Bağlantı testi Coolify menüsünden yapılır.');
    }
}
