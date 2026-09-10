<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Site;
use App\Services\Hostinger\HostingerMailClient;
use App\Services\Hostinger\HostingerMailException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SiteMailProxyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $site = $this->site($request);

        try {
            $mailboxes = HostingerMailClient::forSite($this->readySite($site))->listMailboxes();
        } catch (HostingerMailException $exception) {
            return $this->providerError($exception);
        }

        return response()->json([
            'ok' => true,
            'mailboxes' => $mailboxes,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $site = $this->site($request);
        $validated = $request->validate([
            'local_part' => ['required', 'string', 'max:50', 'regex:'.HostingerMailClient::LOCAL_PART_PATTERN],
            'password' => ['required', 'string', 'min:8', 'max:50'],
        ]);

        if (! $this->passwordMeetsRules($validated['password'])) {
            return response()->json([
                'ok' => false,
                'error' => 'validation_failed',
                'message' => 'Password does not meet Hostinger rules.',
            ], 422);
        }

        try {
            $mailbox = HostingerMailClient::forSite($this->readySite($site))
                ->createMailbox($validated['local_part'], $validated['password']);
        } catch (HostingerMailException $exception) {
            return $this->providerError($exception);
        }

        $this->audit($site, $request, 'mail.mailbox_created', [
            'local_part' => $validated['local_part'],
            'mailbox_id' => $mailbox['id'],
        ]);

        return response()->json([
            'ok' => true,
            'mailbox' => $mailbox,
        ], 201);
    }

    public function password(Request $request, string $mailboxId): JsonResponse
    {
        $site = $this->site($request);
        if (! $this->validMailboxId($mailboxId)) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'max:50'],
        ]);

        if (! $this->passwordMeetsRules($validated['password'])) {
            return response()->json([
                'ok' => false,
                'error' => 'validation_failed',
                'message' => 'Password does not meet Hostinger rules.',
            ], 422);
        }

        try {
            HostingerMailClient::forSite($this->readySite($site))
                ->changePassword($mailboxId, $validated['password']);
        } catch (HostingerMailException $exception) {
            return $this->providerError($exception);
        }

        $this->audit($site, $request, 'mail.mailbox_password_reset', [
            'mailbox_id' => $mailboxId,
        ]);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, string $mailboxId): JsonResponse
    {
        $site = $this->site($request);
        if (! $this->validMailboxId($mailboxId)) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        try {
            HostingerMailClient::forSite($this->readySite($site))->deleteMailbox($mailboxId);
        } catch (HostingerMailException $exception) {
            return $this->providerError($exception);
        }

        $this->audit($site, $request, 'mail.mailbox_deleted', [
            'mailbox_id' => $mailboxId,
        ]);

        return response()->json(['ok' => true]);
    }

    private function site(Request $request): Site
    {
        $site = $request->attributes->get('mailSite');
        if (! $site instanceof Site) {
            abort(401);
        }

        return $site;
    }

    private function readySite(Site $site): Site
    {
        $server = $site->mailServer;
        if ($server === null || ! $server->isHostingerReady() || ! $site->hasHostingerMailOrder()) {
            throw new HostingerMailException('mail_not_configured', 422, 'Mail is not configured for this site.');
        }

        return $site;
    }

    private function providerError(HostingerMailException $exception): JsonResponse
    {
        Log::warning('site.mail_proxy_failed', [
            'error' => $exception->error,
            'status' => $exception->status,
        ]);

        return response()->json([
            'ok' => false,
            'error' => $exception->error,
            'message' => 'Mail request failed.',
        ], $exception->status >= 400 ? $exception->status : 502);
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(Site $site, Request $request, string $action, array $after): void
    {
        AuditLog::query()->create([
            'actor_user_id' => null,
            'action' => $action,
            'subject_type' => $site->getMorphClass(),
            'subject_id' => $site->id,
            'after' => $after + ['site_id' => $site->id],
            'ip' => $request->ip(),
        ]);
    }

    private function validMailboxId(string $mailboxId): bool
    {
        return preg_match('/\A[A-Za-z0-9._-]{1,128}\z/', $mailboxId) === 1;
    }

    private function passwordMeetsRules(string $password): bool
    {
        $length = mb_strlen($password);
        if ($length < 8 || $length > 50) {
            return false;
        }

        return (bool) preg_match('/[A-Z]/', $password)
            && (bool) preg_match('/[a-z]/', $password)
            && (bool) preg_match('/[0-9]/', $password)
            && (bool) preg_match('/[^A-Za-z0-9]/', $password);
    }
}
