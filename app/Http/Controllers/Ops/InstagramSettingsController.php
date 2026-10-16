<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Jobs\DispatchInstagramPushJob;
use App\Models\AuditLog;
use App\Models\InstagramSetting;
use App\Models\Site;
use App\Services\Instagram\InstagramAgentClient;
use App\Services\Instagram\InstagramOAuthService;
use App\Support\PublicAppUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class InstagramSettingsController extends Controller
{
    public function edit(Request $request, InstagramAgentClient $agent): View
    {
        $sites = Site::query()->orderBy('name')->get();

        return view('ops.instagram.edit', [
            'settings' => InstagramSetting::current(),
            'canWrite' => $request->user()?->can('ops.write') ?? false,
            'canEdit' => $request->user()?->can('ops.danger') ?? false,
            'redirectUri' => InstagramOAuthService::redirectUri(),
            'dataDeletionUrl' => PublicAppUrl::forAgents().'/instagram/veri-silme',
            'supportedCount' => $sites->filter(fn (Site $site): bool => $agent->supports($site))->count(),
            'connectedSites' => $sites->filter(fn (Site $site): bool => (bool) data_get($site->last_health_payload, 'instagram.connected', false)),
        ]);
    }

    public function push(Request $request): RedirectResponse
    {
        $this->authorize('ops.write');

        DispatchInstagramPushJob::dispatch();

        return redirect()
            ->route('ops.instagram.edit')
            ->with('status', __('instagram.flash.push_queued'));
    }

    public function update(Request $request): RedirectResponse
    {
        // The app secret brokers every customer's Instagram login.
        $this->authorize('ops.danger');

        $validated = $request->validate([
            'app_id' => ['nullable', 'string', 'max:64', 'regex:/\A[0-9]+\z/'],
            'app_secret' => ['nullable', 'string', 'max:255', 'regex:/\A[0-9A-Za-z]+\z/'],
        ]);

        $settings = InstagramSetting::current();
        $before = $this->auditSnapshot($settings);

        $settings->app_id = trim((string) ($validated['app_id'] ?? '')) ?: null;

        // Blank secret keeps the stored value; it is never echoed back.
        if (filled($validated['app_secret'] ?? null)) {
            $settings->app_secret = trim((string) $validated['app_secret']);
        }

        $settings->save();

        AuditLog::query()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => 'instagram.settings_updated',
            'subject_type' => InstagramSetting::class,
            'subject_id' => $settings->id,
            'before' => $before,
            'after' => $this->auditSnapshot($settings),
            'ip' => $request->ip(),
        ]);

        DispatchInstagramPushJob::dispatch();

        return redirect()
            ->route('ops.instagram.edit')
            ->with('status', __('instagram.flash.saved'));
    }

    /**
     * @return array{app_id: ?string, has_app_secret: bool}
     */
    private function auditSnapshot(InstagramSetting $settings): array
    {
        return [
            'app_id' => $settings->app_id,
            'has_app_secret' => $settings->exists && $settings->hasAppSecret(),
        ];
    }
}
