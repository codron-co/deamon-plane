<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MailServer;
use App\Models\Site;
use App\Models\SiteComailDomain;
use App\Services\Comail\ComailDirectory;
use App\Services\Comail\ComailException;
use App\Services\Comail\ComailSecurity;
use App\Services\Comail\ComailSpam;
use App\Services\Comail\MailcowClient;
use App\Services\Comail\MailcowException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * CoMail v3 site API (`/internal/site/v1/comail/*`): mail security overview,
 * DMARC policy, mailbox spam filter and quarantine. Same signature, error
 * shape and ownership rules as SiteComailController: every domain, mailbox,
 * list entry and quarantine item must belong to the calling site.
 */
class SiteComailSecurityController extends Controller
{
    private const LISTS = ['allow' => 'wl', 'block' => 'bl'];

    public function __construct(
        private readonly ComailSecurity $security,
        private readonly ComailSpam $spam,
    ) {}

    // ── Security overview + DMARC ───────────────────────────────────────

    public function show(Request $request): JsonResponse
    {
        return $this->run($request, function (Site $site, ComailDirectory $directory): JsonResponse {
            return $this->ok($this->security->overview($site, $directory->client));
        });
    }

    public function dmarc(Request $request, string $domain): JsonResponse
    {
        return $this->run($request, function (Site $site, ComailDirectory $directory) use ($request, $domain): JsonResponse {
            $row = $this->ownedDomain($site, $domain);
            $policy = strtolower(trim((string) $request->input('policy', '')));
            if (! in_array($policy, SiteComailDomain::DMARC_POLICIES, true)) {
                throw ComailException::validation('Politika none, quarantine ya da reject olmalı.');
            }

            $result = $this->security->setDmarcPolicy($row, $policy, $directory->client);
            $this->audit($site, $request, 'comail.dmarc_policy', [
                'domain' => $row->domain,
                'policy' => $policy,
                'written' => $result['written'],
            ]);

            return $this->ok($result['row']);
        });
    }

    // ── Mailbox spam filter ─────────────────────────────────────────────

    public function spam(Request $request, string $address): JsonResponse
    {
        return $this->run($request, function (Site $site, ComailDirectory $directory) use ($address): JsonResponse {
            $username = $this->ownedMailbox($directory, $address);

            return $this->ok($this->spam->state($directory->client, $username));
        });
    }

    public function updateSpam(Request $request, string $address): JsonResponse
    {
        return $this->run($request, function (Site $site, ComailDirectory $directory) use ($request, $address): JsonResponse {
            $username = $this->ownedMailbox($directory, $address);
            $level = strtolower(trim((string) $request->input('level', '')));

            $this->spam->setLevel($directory->client, $username, $level);
            $this->audit($site, $request, 'comail.spam_level', ['address' => $username, 'level' => $level]);

            return $this->ok($this->spam->state($directory->client, $username));
        });
    }

    public function storeSpamEntry(Request $request, string $address, string $list): JsonResponse
    {
        return $this->run($request, function (Site $site, ComailDirectory $directory) use ($request, $address, $list): JsonResponse {
            $username = $this->ownedMailbox($directory, $address);
            $value = $request->input('value');
            if (! is_string($value) || trim($value) === '') {
                throw ComailException::validation('Adres zorunlu.');
            }

            $added = $this->spam->add($directory->client, $username, self::LISTS[$list], $value);
            $this->audit($site, $request, 'comail.spam_list', [
                'address' => $username,
                'list' => $list,
                'change' => 'added',
                'value' => $added,
            ]);

            return $this->ok($this->spam->state($directory->client, $username), 201);
        });
    }

    public function destroySpamEntry(Request $request, string $address, string $list, string $id): JsonResponse
    {
        return $this->run($request, function (Site $site, ComailDirectory $directory) use ($request, $address, $list, $id): JsonResponse {
            $username = $this->ownedMailbox($directory, $address);
            $removed = $this->spam->remove($directory->client, $username, self::LISTS[$list], $id);
            if ($removed === null) {
                $this->denied($site, 'spam_'.$list, $id);

                throw ComailException::notFound('Liste kaydı bulunamadı.');
            }

            $this->audit($site, $request, 'comail.spam_list', [
                'address' => $username,
                'list' => $list,
                'change' => 'removed',
                'value' => $removed,
            ]);

            return $this->ok($this->spam->state($directory->client, $username));
        });
    }

    // ── Quarantine ──────────────────────────────────────────────────────

    public function quarantine(Request $request): JsonResponse
    {
        return $this->run($request, function (Site $site, ComailDirectory $directory): JsonResponse {
            return $this->ok(['items' => $this->spam->quarantine($directory)]);
        });
    }

    public function releaseQuarantine(Request $request, string $id): JsonResponse
    {
        return $this->run($request, function (Site $site, ComailDirectory $directory) use ($request, $id): JsonResponse {
            $item = $this->ownedQuarantineItem($directory, $id);
            $directory->client->releaseQuarantineItem((int) $item['id']);
            $this->audit($site, $request, 'comail.quarantine_release', [
                'quarantine_id' => (int) $item['id'],
                'mailbox' => strtolower((string) ($item['rcpt'] ?? '')),
            ]);

            return $this->ok([]);
        });
    }

    public function destroyQuarantine(Request $request, string $id): JsonResponse
    {
        return $this->run($request, function (Site $site, ComailDirectory $directory) use ($request, $id): JsonResponse {
            $item = $this->ownedQuarantineItem($directory, $id);
            $directory->client->deleteQuarantineItem((int) $item['id']);
            $this->audit($site, $request, 'comail.quarantine_deleted', [
                'quarantine_id' => (int) $item['id'],
                'mailbox' => strtolower((string) ($item['rcpt'] ?? '')),
            ]);

            return $this->ok([]);
        });
    }

    // ── Plumbing (same rules as SiteComailController) ───────────────────

    /**
     * @param  Closure(Site, ComailDirectory, MailServer): JsonResponse  $handler
     */
    private function run(Request $request, Closure $handler): JsonResponse
    {
        $site = $request->attributes->get('mailSite');
        if (! $site instanceof Site) {
            abort(401);
        }

        try {
            $server = MailServer::comail();
            if (! $server instanceof MailServer) {
                throw ComailException::notConfigured();
            }

            return $handler($site, new ComailDirectory($site, MailcowClient::forServer($server)), $server);
        } catch (ComailException $exception) {
            return $this->error($exception->error, $exception->getMessage(), $exception->status);
        } catch (MailcowException $exception) {
            Log::warning('site.comail_failed', [
                'site_id' => $site->id,
                'error' => $exception->error,
                'provider_key' => $exception->providerKey,
            ]);

            return $exception->isUnreachable()
                ? $this->error('provider_unreachable', $exception->turkishMessage(), 502)
                : $this->error('provider_error', $exception->turkishMessage(), 422);
        }
    }

    private function ownedDomain(Site $site, string $domain): SiteComailDomain
    {
        $row = $site->comailDomains()->where('domain', strtolower(trim($domain)))->first();
        if (! $row instanceof SiteComailDomain) {
            $this->denied($site, 'domain', $domain);

            throw ComailException::notFound('Alan adı bulunamadı.');
        }

        return $row;
    }

    private function ownedMailbox(ComailDirectory $directory, string $address): string
    {
        $mailbox = $directory->mailbox(rawurldecode($address));
        if ($mailbox === null) {
            $this->denied($directory->site, 'mailbox', $address);

            throw ComailException::notFound('Posta kutusu bulunamadı.');
        }

        return strtolower((string) $mailbox['username']);
    }

    /**
     * @return array<string, mixed>
     */
    private function ownedQuarantineItem(ComailDirectory $directory, string $id): array
    {
        $item = $this->spam->quarantineItem($directory, $id);
        if ($item === null) {
            $this->denied($directory->site, 'quarantine', $id);

            throw ComailException::notFound('Karantinadaki ileti bulunamadı.');
        }

        return $item;
    }

    private function denied(Site $site, string $kind, string $reference): void
    {
        Log::notice('site.comail_foreign_object', ['site_id' => $site->id, 'kind' => $kind]);
        AuditLog::query()->create([
            'actor_user_id' => null,
            'action' => 'comail.access_denied',
            'subject_type' => $site->getMorphClass(),
            'subject_id' => $site->id,
            'after' => ['kind' => $kind, 'reference' => mb_substr($reference, 0, 120), 'site_id' => $site->id],
            'ip' => request()->ip(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function ok(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['ok' => true] + $data, $status);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => $code, 'message' => $message], $status);
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
            'after' => $after + ['site_id' => $site->id, 'source' => 'cms'],
            'ip' => $request->ip(),
        ]);
    }
}
