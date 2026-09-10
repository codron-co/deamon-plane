<?php

namespace App\Http\Controllers\Ops;

use App\Enums\CoolifyEnvKind;
use App\Enums\CoolifyEnvPack;
use App\Http\Controllers\Controller;
use App\Models\CoolifyEnvDefault;
use App\Models\GithubSetting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function index(): View
    {
        $github = GithubSetting::current();
        $envDefaults = CoolifyEnvDefault::query()
            ->orderBy('pack')
            ->orderBy('sort')
            ->orderBy('key')
            ->get()
            ->groupBy(static fn (CoolifyEnvDefault $row): string => $row->pack->value);

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
            'envPacks' => CoolifyEnvPack::cases(),
            'envKinds' => CoolifyEnvKind::cases(),
            'envDefaults' => $envDefaults,
        ]);
    }

    public function update(): RedirectResponse
    {
        $this->authorize('ops.write');

        return redirect()->route('ops.coolify.index');
    }

    public function testConnection(): RedirectResponse
    {
        $this->authorize('ops.write');

        return redirect()->route('ops.coolify.index');
    }

    public function updateEnvDefaults(Request $request): RedirectResponse
    {
        $this->authorize('ops.write');

        $validated = $request->validate([
            'pack' => ['required', Rule::enum(CoolifyEnvPack::class)],
            'rows' => ['required', 'array', 'max:200'],
            'rows.*.key' => ['nullable', 'string', 'max:120', 'regex:/^[A-Z][A-Z0-9_]*$/'],
            'rows.*.kind' => ['required', Rule::enum(CoolifyEnvKind::class)],
            'rows.*.value' => ['nullable', 'string', 'max:4000'],
            'rows.*.is_secret' => ['sometimes', 'boolean'],
            'rows.*.notes' => ['nullable', 'string', 'max:64'],
        ]);

        $pack = CoolifyEnvPack::from($validated['pack']);
        $seen = [];
        $rows = [];
        $sort = 10;

        foreach ($validated['rows'] as $row) {
            $key = strtoupper(trim((string) ($row['key'] ?? '')));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $rows[] = [
                'pack' => $pack->value,
                'key' => $key,
                'kind' => $row['kind'],
                'value' => $this->nullableString($row['value'] ?? null),
                'is_secret' => filter_var($row['is_secret'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'sort' => $sort,
                'notes' => $this->nullableString($row['notes'] ?? null),
            ];
            $sort += 10;
        }

        if ($rows === []) {
            return back()->with('error', __('settings.env.empty'));
        }

        DB::transaction(function () use ($pack, $rows): void {
            CoolifyEnvDefault::query()->where('pack', $pack->value)->delete();

            $now = now();
            foreach ($rows as $row) {
                CoolifyEnvDefault::query()->create([
                    ...$row,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        return back()->with('status', __('settings.env.saved', ['pack' => $pack->label()]));
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
