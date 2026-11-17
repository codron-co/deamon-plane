<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Agent\SiteTwoFactorPolicyAgent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Site detay → Altyapı → "Yönetici 2FA zorunluluğu": sitenin adminleri için iki adımlı doğrulamayı
 * zorunlu kılar/kaldırır (CMS ayarı, imzalı agent ucu). Audit satırında gizli veri yoktur.
 */
class SiteTwoFactorPolicyController extends Controller
{
    public function update(Request $request, Site $site, SiteTwoFactorPolicyAgent $agent): RedirectResponse
    {
        $this->authorize('update', $site);

        $validator = Validator::make($request->all(), [
            'required' => ['required', 'boolean'],
            'grace_days' => ['nullable', 'integer', 'min:0', 'max:60'],
        ], [], __('two_factor.fields'));

        if ($validator->fails()) {
            return redirect()->to($this->sectionUrl($site))->withErrors($validator, 'two_factor');
        }

        $payload = ['required' => $request->boolean('required')];
        if ($request->filled('grace_days')) {
            $payload['grace_days'] = (int) $request->input('grace_days');
        }

        $before = $agent->read($site);
        $result = $agent->apply($site, $payload);

        if (! $result['ok']) {
            return redirect()->to($this->sectionUrl($site))->withErrors(
                ['agent' => __('two_factor.flash.failed', ['reason' => (string) $result['error']])],
                'two_factor',
            );
        }

        $site->auditLogs()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => 'site.two_factor_policy.updated',
            'before' => $before['ok'] ? $this->auditValues($before['policy']) : null,
            'after' => $this->auditValues($result['policy']),
            'ip' => $request->ip(),
        ]);

        $policy = (array) $result['policy'];

        return redirect()->to($this->sectionUrl($site))->with('status', __('two_factor.flash.saved', [
            'state' => __('two_factor.states.'.(($policy['required'] ?? false) ? 'required' : 'optional')),
            'with' => (int) ($policy['admins_with_two_factor'] ?? 0),
            'total' => (int) ($policy['admins_total'] ?? 0),
        ]));
    }

    /**
     * Mevcut durumu CMS'ten okur ve gösterir (kart "Durumu getir").
     */
    public function pull(Request $request, Site $site, SiteTwoFactorPolicyAgent $agent): RedirectResponse
    {
        $this->authorize('update', $site);

        $result = $agent->read($site);

        if (! $result['ok']) {
            return redirect()->to($this->sectionUrl($site))->withErrors(
                ['agent' => __('two_factor.flash.failed', ['reason' => (string) $result['error']])],
                'two_factor',
            );
        }

        $policy = (array) $result['policy'];

        return redirect()->to($this->sectionUrl($site))->with('status', __('two_factor.flash.state', [
            'state' => __('two_factor.states.'.(($policy['required'] ?? false) ? 'required' : 'optional')),
            'grace' => (int) ($policy['grace_days'] ?? 0),
            'with' => (int) ($policy['admins_with_two_factor'] ?? 0),
            'total' => (int) ($policy['admins_total'] ?? 0),
        ]));
    }

    /**
     * @param  array<string, mixed>|null  $policy
     * @return array<string, mixed>
     */
    private function auditValues(?array $policy): array
    {
        return [
            'required' => (bool) ($policy['required'] ?? false),
            'grace_days' => (int) ($policy['grace_days'] ?? 0),
        ];
    }

    private function sectionUrl(Site $site): string
    {
        return route('ops.sites.show', $site).'#infrastructure';
    }
}
