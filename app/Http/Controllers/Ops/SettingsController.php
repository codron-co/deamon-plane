<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\CoolifySetting;
use App\Models\GithubSetting;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyClient;
use App\Services\Coolify\CoolifyCredentials;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('ops.write');

        $validated = $request->validate([
            'base_url' => ['nullable', 'string', 'max:255'],
            'api_token' => ['nullable', 'string', 'max:2000'],
            'default_project_uuid' => ['nullable', 'string', 'max:64'],
            'default_server_uuid' => ['nullable', 'string', 'max:64'],
            'github_app_uuid' => ['nullable', 'string', 'max:64'],
            'private_key_uuid' => ['nullable', 'string', 'max:64'],
            'webhook_secret' => ['nullable', 'string', 'max:2000'],
            'ack' => ['sometimes', 'boolean'],
        ]);

        $settings = CoolifySetting::current();
        $settings->base_url = $this->nullableUrl($validated['base_url'] ?? null);
        $settings->default_project_uuid = $this->nullableString($validated['default_project_uuid'] ?? null);
        $settings->default_server_uuid = $this->nullableString($validated['default_server_uuid'] ?? null);
        $settings->github_app_uuid = $this->nullableString($validated['github_app_uuid'] ?? null);
        $settings->private_key_uuid = $this->nullableString($validated['private_key_uuid'] ?? null);

        if (filled($validated['api_token'] ?? null)) {
            $settings->api_token = $validated['api_token'];
        }

        if (filled($validated['webhook_secret'] ?? null)) {
            $settings->webhook_secret = $validated['webhook_secret'];
        }

        $settings->save();

        return back()->with('status', 'Coolify connection settings saved.');
    }

    public function testConnection(Request $request): RedirectResponse
    {
        $this->authorize('ops.write');

        $validated = $request->validate([
            'base_url' => ['nullable', 'string', 'max:255'],
            'api_token' => ['nullable', 'string', 'max:2000'],
        ]);

        $settings = CoolifySetting::current();
        $base = $this->nullableUrl($validated['base_url'] ?? null)
            ?: ($settings->base_url ?: (string) config('ops.coolify.base_url'));
        $token = filled($validated['api_token'] ?? null)
            ? (string) $validated['api_token']
            : (string) ($settings->api_token ?: config('ops.coolify.api_token'));

        if ($base === '' || $token === '') {
            return back()->with('error', 'Coolify base URL and API token are required to test the connection.');
        }

        try {
            $client = new CoolifyClient(new CoolifyCredentials($base, $token));
            $servers = $client->listServers();
        } catch (CoolifyApiException $exception) {
            return back()->with('error', 'Coolify connection failed: '.$exception->getMessage());
        }

        $count = $servers->count();

        return back()->with('status', "Coolify connection OK — {$count} server(s).");
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function nullableUrl(mixed $value): ?string
    {
        $url = $this->nullableString($value);
        if ($url === null) {
            return null;
        }

        return CoolifyCredentials::normalizeBaseUrl($url) ?: null;
    }
}
