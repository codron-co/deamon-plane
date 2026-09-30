<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Jobs\PushSiteSearchIntegrationsJob;
use App\Models\Site;
use App\Models\SiteSearchIntegration;
use App\Services\SearchIntegrations\SiteSearchIntegrationsAgent;
use App\Services\SearchIntegrations\SiteSearchIntegrationsPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Site detail → Arama & Analitik: Search Console / Bing / Yandex verification
 * and GA4 / GTM / Metrica / Clarity ids. Plane keeps the desired state and
 * pushes it over the signed agent; "Siteden çek" imports what the site has.
 * Nothing here is secret, so audit rows carry the values.
 */
class SiteSearchIntegrationsController extends Controller
{
    public function update(Request $request, Site $site): RedirectResponse
    {
        $this->authorize('update', $site);

        $values = SiteSearchIntegration::normalizeInput($request->all());
        $enableModule = $request->boolean('enable_module');

        $validator = Validator::make(
            $values + ['enable_module' => $enableModule],
            SiteSearchIntegration::rules(),
            [
                'gtm_id.regex' => __('search_integrations.validation.gtm'),
                'ga4_id.regex' => __('search_integrations.validation.ga4'),
                'gtm_id.required_if' => __('search_integrations.validation.gtm_required'),
                'ga4_id.required_if' => __('search_integrations.validation.ga4_required'),
                'google_verification.regex' => __('search_integrations.validation.token'),
                'bing_verification.regex' => __('search_integrations.validation.token'),
                'yandex_verification.regex' => __('search_integrations.validation.token'),
                'google_file_token.regex' => __('search_integrations.validation.file_token'),
                'yandex_metrica_id.regex' => __('search_integrations.validation.metrica'),
                'clarity_id.regex' => __('search_integrations.validation.clarity'),
            ],
            __('search_integrations.fields'),
        );

        if ($validator->fails()) {
            return redirect()->to($this->sectionUrl($site))->withErrors($validator, 'searchIntegrations')->withInput();
        }

        $record = $site->searchIntegration()->firstOrNew();
        $before = $this->auditValues($record);
        $previousManaged = is_array($record->managed_fields) ? $record->managed_fields : [];

        $record->fill($values);
        $record->enable_module = $enableModule;
        $record->managed_fields = SiteSearchIntegration::managedAfter($values, $previousManaged);

        if ($record->exists && ! $record->isDirty()) {
            return redirect()->to($this->sectionUrl($site))->with('status', __('search_integrations.flash.unchanged'));
        }

        $record->changed_at = now();
        $record->updated_by_user_id = $request->user()?->id;
        $record->save();

        $this->audit($request, $site, 'site.search_integrations.updated', $before, $this->auditValues($record));

        if (! $site->hasAgentSecret()) {
            return redirect()->to($this->sectionUrl($site))->with('status', __('search_integrations.flash.saved_no_agent'));
        }

        PushSiteSearchIntegrationsJob::dispatch((string) $site->id);

        return redirect()->to($this->sectionUrl($site))->with('status', __('search_integrations.flash.saved'));
    }

    public function pull(Request $request, Site $site, SiteSearchIntegrationsAgent $agent): RedirectResponse
    {
        $this->authorize('update', $site);

        $result = $agent->pull($site);
        if (! $result['ok']) {
            return redirect()->to($this->sectionUrl($site))->with('error', __('search_integrations.flash.pull_failed', [
                'reason' => SiteSearchIntegrationsPresenter::errorLabel($result['error'] ?? 'http_error'),
            ]));
        }

        $record = $site->searchIntegration()->firstOrNew();
        $before = $this->auditValues($record);

        $record->fill($result['values'] ?? []);
        // After a pull Plane equals the site, so every field is Plane's from here on.
        $record->managed_fields = array_values(SiteSearchIntegration::FIELDS);
        $record->pulled_at = now();
        $record->changed_at = null;
        $record->push_failed_at = null;
        $record->push_error = null;
        $record->site_module_enabled = $result['module_enabled'] ?? null;
        $record->updated_by_user_id = $request->user()?->id;
        $record->save();

        $this->audit($request, $site, 'site.search_integrations.pulled', $before, $this->auditValues($record));

        return redirect()->to($this->sectionUrl($site))->with('status', __('search_integrations.flash.pulled'));
    }

    public function push(Request $request, Site $site): RedirectResponse
    {
        $this->authorize('update', $site);

        if (! $site->searchIntegration()->exists()) {
            return redirect()->to($this->sectionUrl($site))->with('error', __('search_integrations.flash.nothing_to_push'));
        }

        if (! $site->hasAgentSecret()) {
            return redirect()->to($this->sectionUrl($site))->with('error', __('search_integrations.errors.no_agent_secret'));
        }

        PushSiteSearchIntegrationsJob::dispatch((string) $site->id);
        $this->audit($request, $site, 'site.search_integrations.push_requested', null, null);

        return redirect()->to($this->sectionUrl($site))->with('status', __('search_integrations.flash.push_queued'));
    }

    private function sectionUrl(Site $site): string
    {
        return route('ops.sites.show', $site).'#search';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function auditValues(SiteSearchIntegration $record): ?array
    {
        if (! $record->exists) {
            return null;
        }

        $values = [];
        foreach (array_keys(SiteSearchIntegration::FIELDS) as $column) {
            $values[$column] = (string) ($record->getAttribute($column) ?? '');
        }
        $values['enable_module'] = (bool) $record->enable_module;

        return $values;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function audit(Request $request, Site $site, string $action, ?array $before, ?array $after): void
    {
        $site->auditLogs()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'ip' => $request->ip(),
        ]);
    }
}
