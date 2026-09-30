<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Sites\SiteEnvEditor;
use App\Support\SecretRedactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Coolify application env for one site: compare with a branch catalog, fix by
 * branch, and set or delete single keys. Values never reach logs or audit rows.
 */
class SiteEnvController extends Controller
{
    /**
     * Loaded after the detail page so a slow Coolify never holds the page open.
     */
    public function panel(Request $request, Site $site, SiteEnvEditor $editor): Response
    {
        $this->authorize('view', $site);

        $channel = $editor->resolveChannel($site, $request->query('channel'));
        $rows = [];
        $error = null;

        if (filled($site->coolify_app_uuid)) {
            try {
                $rows = $editor->rows($site, CoolifyApplicationService::forSite($site), $channel);
            } catch (Throwable $exception) {
                Log::warning('site.env_panel_failed', [
                    'site_id' => $site->id,
                    'site_slug' => $site->slug,
                    'error' => $exception::class,
                ]);
                $error = __('site_env.errors.load_failed');
            }
        }

        return response()->view('ops.sites._env-body', [
            'site' => $site,
            'channel' => $channel,
            'channels' => $editor->channels(),
            'siteChannel' => $editor->resolveChannel($site, null),
            'rows' => $rows,
            'counts' => $editor->counts($rows),
            'error' => $error,
            'canOps' => $request->user()?->can('update', $site) ?? false,
        ]);
    }

    public function fix(Request $request, Site $site, SiteEnvEditor $editor): RedirectResponse
    {
        $this->authorize('update', $site);
        $channel = $editor->resolveChannel($site, (string) $request->input('channel'));

        if (blank($site->coolify_app_uuid)) {
            return back()->with('error', __('site_env.errors.no_app'));
        }

        try {
            $keys = $editor->fix($site, CoolifyApplicationService::forSite($site), $channel);
        } catch (Throwable $exception) {
            return back()->with('error', __('site_env.errors.write_failed', ['error' => $exception->getMessage()]));
        }

        $this->audit($request, $site, 'site.env_fixed', ['channel' => $channel->value, 'keys' => $keys]);

        return back()->with('status', $keys === []
            ? __('site_env.fix.none', ['branch' => $channel->value])
            : __('site_env.fix.done', ['branch' => $channel->value, 'keys' => implode(', ', $keys)]).' '.__('site_env.redeploy_needed'));
    }

    public function set(Request $request, Site $site, SiteEnvEditor $editor): RedirectResponse
    {
        $this->authorize('update', $site);
        $data = $request->validate([
            'key' => ['required', 'string', 'max:128'],
            'value' => ['nullable', 'string', 'max:8192'],
        ]);
        $key = strtoupper(trim((string) $data['key']));
        $value = (string) ($data['value'] ?? '');

        if ($refusal = $editor->refusal($key)) {
            return back()->withErrors(['env' => $refusal]);
        }
        if (blank($site->coolify_app_uuid)) {
            return back()->with('error', __('site_env.errors.no_app'));
        }

        try {
            $editor->set($site, CoolifyApplicationService::forSite($site), $key, $value);
        } catch (Throwable $exception) {
            $message = SecretRedactor::redactSensitive($exception->getMessage(), $value !== '' ? [$value] : []);

            return back()->with('error', __('site_env.errors.write_failed', ['error' => $message]));
        }

        $this->audit($request, $site, 'site.env_set', ['key' => $key]);

        return back()->with('status', __('site_env.set.done', ['key' => $key]).' '.__('site_env.redeploy_needed'));
    }

    public function destroy(Request $request, Site $site, SiteEnvEditor $editor): RedirectResponse
    {
        $this->authorize('update', $site);
        $key = strtoupper(trim((string) $request->validate(['key' => ['required', 'string', 'max:128']])['key']));

        if ($refusal = $editor->refusal($key)) {
            return back()->withErrors(['env' => $refusal]);
        }
        if (blank($site->coolify_app_uuid)) {
            return back()->with('error', __('site_env.errors.no_app'));
        }

        try {
            $deleted = $editor->delete($site, CoolifyApplicationService::forSite($site), $key);
        } catch (Throwable $exception) {
            return back()->with('error', __('site_env.errors.write_failed', ['error' => $exception->getMessage()]));
        }

        if (! $deleted) {
            return back()->with('error', __('site_env.delete.not_found', ['key' => $key]));
        }

        $this->audit($request, $site, 'site.env_deleted', ['key' => $key]);

        return back()->with('status', __('site_env.delete.done', ['key' => $key]).' '.__('site_env.redeploy_needed'));
    }

    /**
     * @param  array<string, mixed>  $after  keys and channel only, never values
     */
    private function audit(Request $request, Site $site, string $action, array $after): void
    {
        $site->auditLogs()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => $action,
            'before' => null,
            'after' => $after,
            'ip' => $request->ip(),
        ]);
    }
}
