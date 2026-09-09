<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\GithubSetting;
use App\Services\GitHub\GitHubApiException;
use App\Services\GitHub\GitHubAppClient;
use App\Services\GitHub\GitHubCredentialsException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GithubSettingsController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $this->authorize('ops.write');

        $validated = $request->validate([
            'org' => ['nullable', 'string', 'max:120'],
            'token' => ['nullable', 'string', 'max:4000'],
            'app_id' => ['nullable', 'string', 'max:64'],
            'installation_id' => ['nullable', 'string', 'max:64'],
            'private_key' => ['nullable', 'string', 'max:16000'],
            'webhook_secret' => ['nullable', 'string', 'max:2000'],
        ]);

        $settings = GithubSetting::current();
        $settings->org = $this->nullableString($validated['org'] ?? null) ?? $settings->org;
        $settings->app_id = $this->nullableString($validated['app_id'] ?? null);
        $settings->installation_id = $this->nullableString($validated['installation_id'] ?? null);

        if (filled($validated['token'] ?? null)) {
            $settings->token = $validated['token'];
        }

        if (filled($validated['private_key'] ?? null)) {
            $settings->private_key = str_replace("\r\n", "\n", (string) $validated['private_key']);
        }

        if (filled($validated['webhook_secret'] ?? null)) {
            $settings->webhook_secret = $validated['webhook_secret'];
        }

        $settings->save();

        return back()->with('status', 'GitHub catalog credentials saved. Secrets are encrypted and never shown again.');
    }

    public function testConnection(Request $request): RedirectResponse
    {
        $this->authorize('ops.write');

        try {
            $count = count(GitHubAppClient::fromSettings(GithubSetting::current())->listThemeRepos());
        } catch (GitHubCredentialsException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (GitHubApiException $exception) {
            return back()->with('error', 'GitHub connection failed: '.$exception->getMessage());
        }

        return back()->with('status', 'GitHub connection OK — '.$count.' theme repo(s) with prefix '.config('ops.themes.repo_prefix').'.');
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
