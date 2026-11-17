<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Security\FleetSecurityResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Fleet response writes: block an IP on every site, release it, manage the fleet
 * allowlist. The service queues one background job (canary first) and writes an
 * audit row; this controller only validates and answers.
 */
class SecurityResponseController extends Controller
{
    public function block(Request $request, FleetSecurityResponse $response): RedirectResponse
    {
        $this->authorize('create', Site::class);

        $data = $request->validate([
            'ip' => ['required', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:255'],
            'hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
        ]);

        try {
            $result = $response->block(
                $data['ip'],
                $data['reason'] ?? null,
                isset($data['hours']) ? now()->addHours((int) $data['hours']) : null,
                $request->user(),
                $request->ip(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('status', __($result['reused'] ? 'security.flash.block_again' : 'security.flash.blocked', ['ip' => $result['block']->ip]));
    }

    public function release(Request $request, FleetSecurityResponse $response): RedirectResponse
    {
        $this->authorize('create', Site::class);

        $data = $request->validate(['ip' => ['required', 'string', 'max:64']]);

        try {
            $result = $response->unblock($data['ip'], $request->user(), $request->ip());
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return $result['block'] === null
            ? back()->with('warning', __('security.flash.not_blocked', ['ip' => $data['ip']]))
            : back()->with('status', __('security.flash.released', ['ip' => $result['block']->ip]));
    }

    public function allow(Request $request, FleetSecurityResponse $response): RedirectResponse
    {
        $this->authorize('create', Site::class);

        $data = $request->validate([
            'ip' => ['required', 'string', 'max:64'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $result = $response->allow($data['ip'], $data['label'] ?? null, $request->user(), $request->ip());
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('status', __('security.flash.allowed', ['ip' => $result['entry']->ip]));
    }

    public function removeAllow(Request $request, FleetSecurityResponse $response): RedirectResponse
    {
        abort_unless((bool) $request->user()?->canRunDangerousOps(), 403);

        $data = $request->validate(['ip' => ['required', 'string', 'max:64']]);

        try {
            $result = $response->removeAllowed($data['ip'], $request->user(), $request->ip());
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return $result['entry'] === null
            ? back()->with('warning', __('security.flash.not_allowed', ['ip' => $data['ip']]))
            : back()->with('status', __('security.flash.allow_removed', ['ip' => $result['entry']->ip]));
    }
}
