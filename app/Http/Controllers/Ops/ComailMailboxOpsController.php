<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\MailServer;
use App\Models\Site;
use App\Models\SiteComailDomain;
use App\Services\Comail\ComailDirectory;
use App\Services\Comail\ComailWebmailSso;
use App\Services\Comail\MailcowClient;
use App\Services\Comail\MailcowException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * CoMail mailboxes in ops: the compact list inside the site's CoMail card
 * (loaded after the page, so the detail page never waits on mailcow), the
 * Super Admin mailbox search and "Webmail'e gir" (one-click webmail login as
 * the mailbox, docs: CONTRACT-v2 "Webmail SSO"). The login is Super Admin only
 * (`ops.danger`), checked here on every request; the views only hide the button.
 */
class ComailMailboxOpsController extends Controller
{
    public function __construct(private readonly ComailWebmailSso $sso) {}

    public function panel(Request $request, Site $site): Response
    {
        $this->authorize('view', $site);

        [$mailboxes, $error] = $this->mailboxes($site);

        return response()->view('ops.sites._comail-mailboxes', [
            'site' => $site,
            'mailboxes' => $mailboxes,
            'mailboxError' => $error,
            'canWebmail' => $request->user()?->can('ops.danger') ?? false,
        ]);
    }

    public function search(Request $request): View
    {
        $this->authorize('ops.danger');

        $query = strtolower(trim((string) $request->query('q', '')));
        $query = mb_substr($query, 0, 320);
        $results = [];
        $error = null;

        if ($query !== '') {
            $domain = str_contains($query, '@') ? ComailDirectory::domainOf($query) : $query;
            $rows = SiteComailDomain::query()
                ->with('site')
                ->where('domain', $domain)
                ->where('status', SiteComailDomain::STATUS_ACTIVE)
                ->get();

            foreach ($rows as $row) {
                if (! $row->site instanceof Site) {
                    continue;
                }
                [$mailboxes, $rowError] = $this->mailboxes($row->site);
                $error ??= $rowError;
                foreach ($mailboxes as $mailbox) {
                    if (ComailDirectory::domainOf($mailbox['address']) === $domain && str_contains($mailbox['address'], $query)) {
                        $results[] = $mailbox + ['site' => $row->site];
                    }
                }
            }
        }

        return view('ops.comail.mailboxes', [
            'query' => $query,
            'results' => $results,
            'mailboxError' => $error,
            'hasServer' => MailServer::comail() instanceof MailServer,
        ]);
    }

    public function webmail(Request $request, Site $site, string $address): RedirectResponse
    {
        $this->authorize('ops.danger');

        $server = MailServer::comail();
        abort_unless($server instanceof MailServer, 404);

        $directory = new ComailDirectory($site, MailcowClient::forServer($server));
        $fallback = route('ops.sites.show', $site).'#site-comail';

        try {
            // Only a mailbox on one of this site's active CoMail domains.
            $mailbox = $directory->mailbox(rawurldecode($address));
            abort_if($mailbox === null, 404);

            $username = strtolower((string) $mailbox['username']);
            if (! self::isActive($mailbox)) {
                return redirect()->back(fallback: $fallback)->with('error', __('comail.mailboxes.errors.inactive', ['address' => $username]));
            }

            $login = $this->sso->issue($server, $directory->client, $username, ComailWebmailSso::OPS_APP_NAME);
        } catch (MailcowException $exception) {
            Log::warning('ops.comail_webmail_failed', [
                'site_id' => $site->id,
                'error' => $exception->error,
                'provider_key' => $exception->providerKey,
            ]);

            return redirect()->back(fallback: $fallback)->with('error', __('comail.site.errors.failed', ['message' => $exception->turkishMessage()]));
        }

        // Never the token or the app password.
        $site->auditLogs()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => 'comail.webmail_login_ops',
            'after' => ['address' => $username, 'site_id' => $site->id, 'source' => 'ops'],
            'ip' => $request->ip(),
        ]);

        return redirect()->away($login['url'])->withHeaders([
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /**
     * @return array{0: list<array{address: string, name: string, quota_mb: int, used_bytes: int, active: bool}>, 1: string|null}
     */
    private function mailboxes(Site $site): array
    {
        $server = MailServer::comail();
        if (! $server instanceof MailServer) {
            return [[], null];
        }

        try {
            $rows = (new ComailDirectory($site, MailcowClient::forServer($server)))->mailboxes();
        } catch (MailcowException $exception) {
            Log::warning('ops.comail_mailboxes_failed', [
                'site_id' => $site->id,
                'error' => $exception->error,
                'provider_key' => $exception->providerKey,
            ]);

            return [[], $exception->turkishMessage()];
        }

        $mailboxes = array_map(static fn (array $row): array => [
            'address' => strtolower((string) ($row['username'] ?? '')),
            'name' => (string) ($row['name'] ?? ''),
            'quota_mb' => ComailDirectory::bytesToMb($row['quota'] ?? 0),
            'used_bytes' => (int) ($row['quota_used'] ?? 0),
            'active' => self::isActive($row),
        ], $rows);
        usort($mailboxes, static fn (array $a, array $b): int => strcmp($a['address'], $b['address']));

        return [$mailboxes, null];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function isActive(array $row): bool
    {
        return (int) ($row['active_int'] ?? $row['active'] ?? 0) === 1;
    }
}
