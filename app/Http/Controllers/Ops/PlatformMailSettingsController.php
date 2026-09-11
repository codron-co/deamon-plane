<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformMailSetting;
use App\Services\Mail\PlatformMailConfigurer;
use App\Services\Mail\PlatformNotificationCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PlatformMailSettingsController extends Controller
{
    public function edit(): View
    {
        $settings = PlatformMailSetting::current();

        return view('ops.platform-mail.edit', [
            'settings' => $settings,
            'definitions' => PlatformNotificationCatalog::definitions(),
            'notifications' => $this->mergedNotifications($settings),
            'canWrite' => request()->user()?->can('ops.write') ?? false,
        ]);
    }

    public function update(Request $request, PlatformMailConfigurer $configurer): RedirectResponse
    {
        $this->authorize('ops.write');

        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['nullable', 'string', 'max:16'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:2000'],
            'from_address' => ['nullable', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'default_admin_recipient' => ['nullable', 'email', 'max:255'],
            'notifications' => ['nullable', 'array'],
        ]);

        $settings = PlatformMailSetting::current();
        if (! $settings->exists) {
            $settings->save();
            $settings = PlatformMailSetting::current();
        }

        $before = $this->auditSnapshot($settings);
        $enabled = $request->boolean('enabled');

        $settings->enabled = $enabled;
        $settings->host = trim((string) ($validated['host'] ?? '')) ?: null;
        $settings->port = (int) ($validated['port'] ?? 465);
        $settings->encryption = trim((string) ($validated['encryption'] ?? 'ssl')) ?: 'ssl';
        $settings->username = trim((string) ($validated['username'] ?? '')) ?: null;
        if (filled($validated['password'] ?? null)) {
            $settings->password = (string) $validated['password'];
        }
        $settings->from_address = trim((string) ($validated['from_address'] ?? '')) ?: null;
        $settings->from_name = trim((string) ($validated['from_name'] ?? '')) ?: null;
        $settings->default_admin_recipient = trim((string) ($validated['default_admin_recipient'] ?? '')) ?: null;
        $settings->notifications = $this->normalizeNotifications(
            is_array($validated['notifications'] ?? null) ? $validated['notifications'] : [],
        );
        $settings->save();

        AuditLog::query()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => 'platform_mail.updated',
            'subject_type' => PlatformMailSetting::class,
            'subject_id' => $settings->id,
            'before' => $before,
            'after' => $this->auditSnapshot($settings),
            'ip' => $request->ip(),
        ]);

        $pushed = $configurer->syncAllSites();

        return redirect()
            ->route('ops.platform-mail.edit')
            ->with('status', __('platform_mail.flash.saved', ['count' => $pushed]));
    }

    public function push(Request $request, PlatformMailConfigurer $configurer): RedirectResponse
    {
        $this->authorize('ops.write');
        $count = $configurer->syncAllSites();

        return redirect()
            ->route('ops.platform-mail.edit')
            ->with('status', __('platform_mail.flash.pushed', ['count' => $count]));
    }

    /**
     * @return array<string, mixed>
     */
    private function mergedNotifications(PlatformMailSetting $settings): array
    {
        $stored = is_array($settings->notifications) ? $settings->notifications : [];

        return array_replace_recursive(PlatformNotificationCatalog::defaultNotifications(), $stored);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalizeNotifications(array $input): array
    {
        $defaults = PlatformNotificationCatalog::defaultNotifications();
        $out = $defaults;

        foreach ($defaults as $key => $defaultRow) {
            if (! array_key_exists($key, $input) || ! is_array($input[$key])) {
                $out[$key] = $defaultRow;

                continue;
            }

            $row = $input[$key];
            $merged = array_merge($defaultRow, [
                'enabled' => array_key_exists('enabled', $row)
                    ? (bool) $row['enabled']
                    : (bool) ($defaultRow['enabled'] ?? false),
            ]);

            if ($key === PlatformNotificationCatalog::WEEKLY_VISITOR_REPORT) {
                $merged['day'] = max(0, min(6, (int) ($row['day'] ?? $defaultRow['day'] ?? 1)));
                $merged['hour'] = max(0, min(23, (int) ($row['hour'] ?? $defaultRow['hour'] ?? 8)));
            }

            if ($key === PlatformNotificationCatalog::SITE_VERSION_UPDATE) {
                $on = (string) ($row['on'] ?? $defaultRow['on'] ?? 'patch');
                $merged['on'] = in_array($on, ['major', 'minor', 'patch'], true) ? $on : 'patch';
            }

            $out[$key] = $merged;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(PlatformMailSetting $settings): array
    {
        return [
            'enabled' => $settings->enabled,
            'host' => $settings->host,
            'port' => $settings->port,
            'from_address' => $settings->from_address,
            'has_password' => $settings->hasPassword(),
            'notifications' => $settings->notifications,
        ];
    }
}
