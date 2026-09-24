<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Jobs\DispatchDeskronPushJob;
use App\Models\AuditLog;
use App\Models\DeskronSetting;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DeskronSettingsController extends Controller
{
    public function edit(Request $request): View
    {
        return view('ops.deskron.edit', [
            'settings' => DeskronSetting::current(),
            'canWrite' => $request->user()?->can('ops.write') ?? false,
            'canEdit' => $request->user()?->can('ops.danger') ?? false,
            'pushedSites' => Site::query()->whereNotNull('deskron_pushed_at')->whereNull('deskron_push_failed_at')->count(),
            'failedSites' => Site::query()->whereNotNull('deskron_push_failed_at')->orderBy('name')->get(['id', 'name', 'slug', 'deskron_push_error']),
        ]);
    }

    public function push(Request $request): RedirectResponse
    {
        $this->authorize('ops.write');

        DispatchDeskronPushJob::dispatch();

        return redirect()
            ->route('ops.deskron.edit')
            ->with('status', __('deskron.flash.push_queued'));
    }

    public function update(Request $request): RedirectResponse
    {
        // The DeskRon key is pushed to every CMS in the fleet.
        $this->authorize('ops.danger');

        $validated = $request->validate([
            'application_id' => ['nullable', 'string', 'max:64', 'regex:/\A[0-9A-Za-z]+\z/'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'webhook_secret' => ['nullable', 'string', 'max:500'],
        ]);

        $settings = DeskronSetting::current();
        $before = $this->auditSnapshot($settings);

        $settings->application_id = trim((string) ($validated['application_id'] ?? '')) ?: null;

        // Blank secret fields keep the stored value; secrets are never echoed back.
        if (filled($validated['api_key'] ?? null)) {
            $settings->api_key = trim((string) $validated['api_key']);
        }
        if (filled($validated['webhook_secret'] ?? null)) {
            $settings->webhook_secret = trim((string) $validated['webhook_secret']);
        }

        $settings->save();

        AuditLog::query()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => 'deskron.updated',
            'subject_type' => DeskronSetting::class,
            'subject_id' => $settings->id,
            'before' => $before,
            'after' => $this->auditSnapshot($settings),
            'ip' => $request->ip(),
        ]);

        // Every CMS gets the new application right away; no env, no redeploy.
        DispatchDeskronPushJob::dispatch();

        return redirect()
            ->route('ops.deskron.edit')
            ->with('status', __('deskron.flash.saved'));
    }

    /**
     * @return array{application_id: ?string, has_api_key: bool, has_webhook_secret: bool}
     */
    private function auditSnapshot(DeskronSetting $settings): array
    {
        return [
            'application_id' => $settings->application_id,
            'has_api_key' => $settings->exists && $settings->hasApiKey(),
            'has_webhook_secret' => $settings->exists && $settings->hasWebhookSecret(),
        ];
    }
}
