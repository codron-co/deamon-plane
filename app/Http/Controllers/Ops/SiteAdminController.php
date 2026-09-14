<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Site;
use App\Services\Agent\AdminAgentResult;
use App\Services\Agent\SiteAgentClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class SiteAdminController extends Controller
{
    public function __construct(
        private readonly SiteAgentClient $agent = new SiteAgentClient,
    ) {}

    public function store(Request $request, Site $site): RedirectResponse
    {
        $this->authorize('manageAdmins', $site);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['nullable', 'string', Password::min(12)->mixedCase()->numbers()->symbols()],
            'password_mode' => ['nullable', 'in:generate,manual,invite'],
        ]);

        $invite = ($validated['password_mode'] ?? null) === 'invite';
        $payload = [
            'name' => trim((string) $validated['name']),
            'email' => strtolower(trim((string) $validated['email'])),
        ];

        // Invite: CMS mails a set-password link. Never generate or flash a password.
        $password = null;
        if ($invite) {
            $payload['password_mode'] = 'invite';
        } else {
            $password = $this->resolvePassword($validated);
            $payload['password'] = $password;
        }

        $result = $this->agent->createAdmin($site, $payload);

        if (! $result->ok) {
            return $this->failureRedirect($site, $result);
        }

        $this->audit($request, $site, 'site.admin.created', null, [
            'admin_id' => $result->admin['id'] ?? null,
            'email' => $result->admin['email'] ?? $validated['email'],
            'password_mode' => $invite ? 'invite' : 'password',
        ]);

        return $this->successRedirect(
            $site,
            $invite ? __('sites.admins.flash.created_invite') : __('sites.admins.flash.created'),
            $password,
        );
    }

    public function sendPasswordInvite(Request $request, Site $site, int $remoteAdmin): RedirectResponse
    {
        $this->authorize('manageAdmins', $site);

        $validated = $request->validate([
            'admin_email' => ['nullable', 'email', 'max:255'],
        ]);

        $result = $this->agent->sendAdminPasswordInvite($site, $remoteAdmin);

        if (! $result->ok) {
            return $this->failureRedirect($site, $result);
        }

        $this->audit($request, $site, 'site.admin.password_invite_sent', null, [
            'admin_id' => $remoteAdmin,
            'email' => $result->admin['email'] ?? ($validated['admin_email'] ?? null),
        ]);

        return $this->successRedirect($site, __('sites.admins.flash.password_invite_sent'), null);
    }

    public function resetPassword(Request $request, Site $site, int $remoteAdmin): RedirectResponse
    {
        $this->authorize('manageAdmins', $site);

        $validated = $request->validate([
            'password' => ['nullable', 'string', Password::min(12)->mixedCase()->numbers()->symbols()],
            'password_mode' => ['nullable', 'in:generate,manual'],
            'admin_email' => ['nullable', 'email', 'max:255'],
        ]);

        $password = $this->resolvePassword($validated);

        $result = $this->agent->resetAdminPassword($site, $remoteAdmin, [
            'password' => $password,
        ]);

        if (! $result->ok) {
            return $this->failureRedirect($site, $result);
        }

        $this->audit($request, $site, 'site.admin.password_reset', null, [
            'admin_id' => $remoteAdmin,
            'email' => $result->admin['email'] ?? ($validated['admin_email'] ?? null),
        ]);

        return $this->successRedirect($site, __('sites.admins.flash.password_reset'), $password);
    }

    public function deactivate(Request $request, Site $site, int $remoteAdmin): RedirectResponse
    {
        $this->authorize('toggleAdminActive', $site);

        return $this->setActive($request, $site, $remoteAdmin, false);
    }

    public function activate(Request $request, Site $site, int $remoteAdmin): RedirectResponse
    {
        $this->authorize('toggleAdminActive', $site);

        return $this->setActive($request, $site, $remoteAdmin, true);
    }

    public function destroy(Request $request, Site $site, int $remoteAdmin): RedirectResponse
    {
        $this->authorize('destroyAdmin', $site);

        $email = $request->string('admin_email')->toString() ?: null;

        $result = $this->agent->deleteAdmin($site, $remoteAdmin);
        if (! $result->ok) {
            return $this->failureRedirect($site, $result);
        }

        $this->audit($request, $site, 'site.admin.deleted', [
            'admin_id' => $remoteAdmin,
            'email' => $email,
        ], null);

        return redirect()
            ->route('ops.sites.show', $site)
            ->with('status', __('sites.admins.flash.deleted'))
            ->withFragment('admins');
    }

    private function setActive(Request $request, Site $site, int $remoteAdmin, bool $active): RedirectResponse
    {
        $result = $this->agent->updateAdmin($site, $remoteAdmin, [
            'is_active' => $active,
        ]);

        if (! $result->ok) {
            return $this->failureRedirect($site, $result);
        }

        $action = $active ? 'site.admin.activated' : 'site.admin.deactivated';
        $this->audit($request, $site, $action, null, [
            'admin_id' => $remoteAdmin,
            'email' => $result->admin['email'] ?? null,
            'is_active' => $active,
        ]);

        return redirect()
            ->route('ops.sites.show', $site)
            ->with('status', $active ? __('sites.admins.flash.activated') : __('sites.admins.flash.deactivated'))
            ->withFragment('admins');
    }

    /**
     * @param  array{password?: string|null, password_mode?: string|null}  $validated
     */
    private function resolvePassword(array $validated): string
    {
        $mode = (string) ($validated['password_mode'] ?? 'generate');
        $manual = trim((string) ($validated['password'] ?? ''));

        if ($mode === 'manual' && $manual !== '') {
            return $manual;
        }

        if ($manual !== '') {
            return $manual;
        }

        return Str::password(20, symbols: true);
    }

    private function failureRedirect(Site $site, AdminAgentResult $result): RedirectResponse
    {
        $message = $result->outdated
            ? __('sites.admins.errors.outdated')
            : $result->safeMessage;

        return redirect()
            ->route('ops.sites.show', $site)
            ->withErrors(['admins' => $message])
            ->withFragment('admins');
    }

    private function successRedirect(Site $site, string $status, ?string $password): RedirectResponse
    {
        $redirect = redirect()
            ->route('ops.sites.show', $site)
            ->with('status', $status)
            ->withFragment('admins');

        if ($password !== null) {
            $redirect->with('admin_password_once', $password);
        }

        return $redirect;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function audit(Request $request, Site $site, string $action, ?array $before, ?array $after): void
    {
        AuditLog::query()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => $action,
            'subject_type' => Site::class,
            'subject_id' => $site->id,
            'before' => $before,
            'after' => $after,
            'ip' => $request->ip(),
        ]);
    }
}
