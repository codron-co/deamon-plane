<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Site;
use App\Models\SiteMailboxRequest;
use App\Services\Hostinger\HostingerMailClient;
use App\Services\Hostinger\HostingerMailException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class SiteMailProxyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $site = $this->site($request);

        try {
            $mailboxes = HostingerMailClient::listAllMailboxes($this->readySite($site));
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
            'domain' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $this->passwordMeetsRules($validated['password'])) {
            return response()->json([
                'ok' => false,
                'error' => 'validation_failed',
                'message' => 'Password does not meet Hostinger rules.',
            ], 422);
        }

        try {
            $ready = $this->readySite($site);
            $domain = $this->resolvedCreateDomain($ready, isset($validated['domain']) ? (string) $validated['domain'] : null);
            $mailbox = HostingerMailClient::forSite($ready, $domain)
                ->createMailbox($validated['local_part'], $validated['password']);
        } catch (HostingerMailException $exception) {
            return $this->providerError($exception);
        }

        $this->audit($site, $request, 'mail.mailbox_created', [
            'local_part' => $validated['local_part'],
            'domain' => $domain,
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

    public function requests(Request $request): JsonResponse
    {
        $site = $this->site($request);

        return response()->json([
            'ok' => true,
            'requests' => $site->mailboxRequests()->get()->map->toPublicArray()->values(),
        ]);
    }

    public function storeRequest(Request $request): JsonResponse
    {
        $site = $this->site($request);
        $validated = $request->validate([
            'local_part' => ['required', 'string', 'max:50', 'regex:'.HostingerMailClient::LOCAL_PART_PATTERN],
            'domain' => ['required', 'string', 'max:255', 'regex:/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}\z/'],
            'note' => ['nullable', 'string', 'max:500'],
            'requester_kind' => ['nullable', 'string', Rule::in(['admin', 'customer'])],
            'requester_ref' => ['nullable', 'string', 'max:64'],
        ]);

        $domain = strtolower(trim($validated['domain']));
        $mailboxRequest = $site->mailboxRequests()->create([
            'local_part' => $validated['local_part'],
            'domain' => $domain,
            'note' => $validated['note'] ?? null,
            'requester_kind' => $validated['requester_kind'] ?? 'admin',
            'requester_ref' => $validated['requester_ref'] ?? null,
            'status' => SiteMailboxRequest::STATUS_PENDING,
        ]);

        $this->audit($site, $request, 'mail.mailbox_requested', [
            'request_id' => $mailboxRequest->id,
            'local_part' => $mailboxRequest->local_part,
            'domain' => $mailboxRequest->domain,
        ]);

        return response()->json([
            'ok' => true,
            'request' => $mailboxRequest->toPublicArray(),
        ], 201);
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
        $site->loadMissing(['mailServer', 'mailBindings']);
        $server = $site->mailServer;
        if ($server === null || ! $server->isHostingerReady() || ! $site->hasHostingerMailOrder()) {
            throw new HostingerMailException('mail_not_configured', 422, 'Mail is not configured for this site.');
        }

        return $site;
    }

    private function resolvedCreateDomain(Site $site, ?string $domain): string
    {
        $domains = $site->mailDomains();
        $wanted = strtolower(trim((string) $domain));
        if ($wanted !== '') {
            if (! in_array($wanted, $domains, true)) {
                throw new HostingerMailException('mail_not_configured', 422, 'Mail domain is not bound to this site.');
            }

            return $wanted;
        }

        if (count($domains) === 1) {
            return $domains[0];
        }

        throw new HostingerMailException('mail_not_configured', 422, 'Mail domain is required.');
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
