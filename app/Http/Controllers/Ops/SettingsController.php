<?php

namespace App\Http\Controllers\Ops;

use App\Enums\Channel;
use App\Enums\CoolifyEnvKind;
use App\Http\Controllers\Controller;
use App\Models\CoolifyEnvCatalogSource;
use App\Models\CoolifyEnvDefault;
use App\Models\GithubSetting;
use App\Services\Coolify\EnvCatalog\CoolifyEnvCatalogException;
use App\Services\Coolify\EnvCatalog\CoolifyEnvCatalogSync;
use App\Services\Coolify\EnvCatalog\DeamonRepo;
use App\Support\Ops\SettingsJump;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function index(): View
    {
        $channels = array_values(array_filter(
            Channel::cases(),
            static fn (Channel $channel): bool => $channel->isAllowed(),
        ));

        $envDefaults = CoolifyEnvDefault::query()
            ->orderBy('channel')
            ->orderBy('sort')
            ->orderBy('key')
            ->get()
            ->groupBy(static fn (CoolifyEnvDefault $row): string => $row->channel->value);

        $envSources = CoolifyEnvCatalogSource::query()
            ->get()
            ->keyBy(static fn (CoolifyEnvCatalogSource $row): string => $row->channel->value);

        $envKeyHaystack = $envDefaults
            ->flatten()
            ->pluck('key')
            ->filter()
            ->unique()
            ->implode(' ');

        return view('ops.settings.index', [
            'composeFile' => config('ops.deamon.compose_file'),
            'repository' => config('ops.deamon.repository'),
            'canWrite' => request()->user()?->can('ops.write') ?? false,
            'githubWebhookUrl' => url('/webhooks/github'),
            'githubSetting' => GithubSetting::current(),
            'envChannels' => $channels,
            'envKinds' => CoolifyEnvKind::cases(),
            'envDefaults' => $envDefaults,
            'envSources' => $envSources,
            'envRepo' => DeamonRepo::fullName(),
            'envPath' => DeamonRepo::ENV_EXAMPLE_PATH,
            'settingsJump' => SettingsJump::sections(),
            'envKeyHaystack' => $envKeyHaystack,
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

    /**
     * Pull one branch's (or every branch's) catalog from the CMS repo now.
     */
    public function syncEnvCatalog(Request $request, CoolifyEnvCatalogSync $sync): RedirectResponse
    {
        $this->authorize('ops.write');

        $validated = $request->validate([
            'channel' => ['nullable', Rule::enum(Channel::class)],
        ]);

        $channels = filled($validated['channel'] ?? null)
            ? [Channel::from((string) $validated['channel'])]
            : array_values(array_filter(Channel::cases(), static fn (Channel $channel): bool => $channel->isAllowed()));

        $status = [];
        $errors = [];

        foreach ($channels as $channel) {
            try {
                $source = $sync->sync($channel);
                $status[] = __('settings.env.synced', [
                    'branch' => $channel->value,
                    'count' => (int) $source->row_count,
                    'sha' => $source->shortSha() ?? $channel->value,
                ]);
            } catch (CoolifyEnvCatalogException $exception) {
                $errors[] = __('settings.env.sync_failed', [
                    'branch' => $channel->value,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $redirect = back();
        if ($status !== []) {
            $redirect->with('status', implode(' ', $status));
        }
        if ($errors !== []) {
            $redirect->with('error', implode(' ', $errors));
        }

        return $redirect;
    }
}
