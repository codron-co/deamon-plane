<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Jobs\SyncSiteTurnstileJob;
use App\Models\Site;
use App\Models\SiteTurnstile;
use App\Services\Turnstile\SiteTurnstileSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Site detail → Altyapı → Bot koruması (Cloudflare Turnstile): açık/kapalı,
 * görünüm ve korunan formlar. Kayıt widget'ları ayırır (Cloudflare) ve CMS'e
 * iter. Audit satırında gizli anahtar yoktur.
 */
class SiteTurnstileController extends Controller
{
    public function update(Request $request, Site $site, SiteTurnstileSync $sync): RedirectResponse
    {
        $this->authorize('update', $site);

        $validator = Validator::make($request->all(), [
            'enabled' => ['nullable', 'boolean'],
            'mode' => ['required', 'string', Rule::in(SiteTurnstile::MODES)],
            'forms' => ['nullable', 'array'],
            'forms.*' => ['string', Rule::in(SiteTurnstile::FORMS)],
        ], [], __('turnstile.fields'));

        if ($validator->fails()) {
            return redirect()->to($this->sectionUrl($site))->withErrors($validator, 'turnstile')->withInput();
        }

        $record = $site->turnstile()->first() ?? $sync->enroll($site, $request->user()?->id);
        $before = $this->auditValues($record);

        $record->fill([
            'enabled' => $request->boolean('enabled'),
            'mode' => (string) $request->input('mode'),
            'forms' => array_values(array_intersect(SiteTurnstile::FORMS, (array) $request->input('forms', []))),
        ]);

        if (! $record->isDirty() && $record->widgetIdMap() !== []) {
            return redirect()->to($this->sectionUrl($site))->with('status', __('turnstile.flash.unchanged'));
        }

        $record->changed_at = now();
        $record->updated_by_user_id = $request->user()?->id;
        $record->save();

        $site->auditLogs()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => 'site.turnstile.updated',
            'before' => $before,
            'after' => $this->auditValues($record),
            'ip' => $request->ip(),
        ]);

        SyncSiteTurnstileJob::dispatch((string) $site->id);

        return redirect()->to($this->sectionUrl($site))->with('status', __($site->hasAgentSecret() ? 'turnstile.flash.saved' : 'turnstile.flash.saved_no_agent'));
    }

    /**
     * Widget'ları alan adlarıyla yeniden eşitle ve CMS'e yeniden it.
     */
    public function sync(Request $request, Site $site, SiteTurnstileSync $sync): RedirectResponse
    {
        $this->authorize('update', $site);

        $sync->enroll($site, $request->user()?->id);
        SyncSiteTurnstileJob::dispatch((string) $site->id);

        return redirect()->to($this->sectionUrl($site))->with('status', __('turnstile.flash.sync_queued'));
    }

    /**
     * @return array<string, mixed>
     */
    private function auditValues(SiteTurnstile $record): array
    {
        return [
            'enabled' => (bool) $record->enabled,
            'mode' => $record->mode,
            'forms' => $record->formList(),
        ];
    }

    private function sectionUrl(Site $site): string
    {
        return route('ops.sites.show', $site).'#infrastructure';
    }
}
