<?php

namespace App\Http\Controllers\Ops;

use App\Enums\Channel;
use App\Enums\CoolifyEnvKind;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\AutomationSetting;
use App\Models\CoolifyEnvCatalogSource;
use App\Models\CoolifyEnvDefault;
use App\Models\GithubSetting;
use App\Services\Coolify\EnvCatalog\CoolifyEnvCatalogException;
use App\Services\Coolify\EnvCatalog\CoolifyEnvCatalogSync;
use App\Services\Coolify\EnvCatalog\DeamonRepo;
use App\Services\Ops\AutomationGuard;
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
            'automationRules' => $this->automationRules(),
            'canEditAutomation' => request()->user()?->can('ops.danger') ?? false,
        ]);
    }

    /**
     * Turn one automatic action on or off at runtime. The env flag stays the
     * floor: a rule it disables cannot be turned on here.
     */
    public function toggleAutomation(Request $request, string $rule, AutomationGuard $guard): RedirectResponse
    {
        $this->authorize('ops.danger');
        abort_unless(array_key_exists($rule, AutomationGuard::RULES), 404);

        $enabled = $request->boolean('enabled');
        $before = $guard->switchedOn($rule);

        $setting = AutomationSetting::query()->updateOrCreate(
            ['rule' => $rule],
            ['enabled' => $enabled, 'updated_by_user_id' => $request->user()?->id],
        );

        AuditLog::query()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => 'automation.toggled',
            'subject_type' => AutomationSetting::class,
            'subject_id' => $setting->id,
            'before' => ['rule' => $rule, 'enabled' => $before],
            'after' => ['rule' => $rule, 'enabled' => $enabled, 'env_allows' => $guard->envAllows($rule)],
            'ip' => $request->ip(),
        ]);

        return redirect()
            ->to(route('ops.settings').'#automation-heading')
            ->with('status', __($enabled ? 'settings.automation.flash_on' : 'settings.automation.flash_off', [
                'rule' => __('settings.automation.rules.'.$rule.'.name'),
            ]));
    }

    /**
     * @return list<array{rule: string, env_allows: bool, switched_on: bool, paused_until: ?int, per_site_per_day: int, fleet_per_hour: int}>
     */
    private function automationRules(): array
    {
        $guard = app(AutomationGuard::class);
        $rows = [];

        foreach (AutomationGuard::RULES as $rule => $limits) {
            $rows[] = [
                'rule' => $rule,
                'env_allows' => $guard->envAllows($rule),
                'switched_on' => $guard->switchedOn($rule),
                'paused_until' => $guard->pausedUntil($rule),
                'per_site_per_day' => $limits['per_site_per_day'],
                'fleet_per_hour' => $limits['fleet_per_hour'],
            ];
        }

        return $rows;
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
