<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\MailServer;
use App\Models\Site;
use App\Models\SiteComailDomain;
use App\Services\Comail\ComailDirectory;
use App\Services\Comail\ComailMailboxActions;
use App\Services\Comail\ComailWebmailSso;
use App\Services\Comail\MailcowClient;
use App\Services\Comail\MailcowException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * CoMail mailboxes in ops: the compact list inside the site's CoMail card
 * (loaded after the page, so the detail page never waits on mailcow), the
 * Super Admin mailbox page and "Webmail'e gir" (one-click webmail login as
 * the mailbox, docs: CONTRACT-v2 "Webmail SSO").
 *
 * The mailbox page lists every mailbox on the CoMail server, also those on
 * domains that belong to no site, and runs the bulk actions on the ticked
 * ones: temporary password (optionally "change on next webmail login"),
 * activate, deactivate, delete (docs: CONTRACT-bulk-mailboxes). Everything
 * but the card is Super Admin only (`ops.danger`), checked here on every
 * request; the views only hide the controls. New passwords are shown once
 * (session flash) and never logged or audited.
 */
class ComailMailboxOpsController extends Controller
{
    public const SESSION_PASSWORDS = 'comail_ops_passwords';

    /** One bulk submit covers every mailbox on the page. */
    private const MAX_BULK = 500;

    public function __construct(
        private readonly ComailWebmailSso $sso,
        private readonly ComailMailboxActions $actions,
    ) {}

    public function panel(Request $request, Site $site): Response
    {
        $this->authorize('view', $site);

        [$mailboxes, $error] = $this->siteMailboxes($site);

        return response()->view('ops.sites._comail-mailboxes', [
            'site' => $site,
            'mailboxes' => $mailboxes,
            'mailboxError' => $error,
            'canWebmail' => $request->user()?->can('ops.danger') ?? false,
        ]);
    }

    public function search(Request $request): Response
    {
        $this->authorize('ops.danger');

        $query = mb_substr(mb_strtolower(trim((string) $request->query('q', ''))), 0, 320);
        $domain = mb_substr(strtolower(trim((string) $request->query('domain', ''))), 0, 253);
        $scope = in_array($request->query('site'), ['none', 'linked'], true) ? (string) $request->query('site') : '';
        $server = MailServer::comail();
        $mailboxes = [];
        $domains = [];
        $error = null;

        if ($server instanceof MailServer) {
            try {
                $rows = MailcowClient::forServer($server)->allMailboxes();
            } catch (MailcowException $exception) {
                Log::warning('ops.comail_mailboxes_failed', ['error' => $exception->error, 'provider_key' => $exception->providerKey]);
                $rows = [];
                $error = $exception->turkishMessage();
            }

            $sites = self::sitesByDomain();
            foreach ($rows as $row) {
                $mailbox = self::row($row, $sites);
                if ($mailbox['address'] === '') {
                    continue;
                }
                $domains[$mailbox['domain']] = true;
                if (self::matches($mailbox, $query, $domain, $scope)) {
                    $mailboxes[] = $mailbox;
                }
            }
            usort($mailboxes, static fn (array $a, array $b): int => [$a['domain'], $a['address']] <=> [$b['domain'], $b['address']]);
            $domains = array_keys($domains);
            sort($domains);
        }

        $revealed = $request->session()->get(self::SESSION_PASSWORDS);

        // The page may carry passwords shown once.
        return response()->view('ops.comail.mailboxes', [
            'query' => $query,
            'domain' => $domain,
            'scope' => $scope,
            'domains' => $domains,
            'mailboxes' => $mailboxes,
            'mailboxError' => $error,
            'hasServer' => $server instanceof MailServer,
            'revealed' => is_array($revealed) ? $revealed : [],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function bulk(Request $request): RedirectResponse
    {
        $this->authorize('ops.danger');

        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(ComailMailboxActions::ACTIONS)],
            'mailbox_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_BULK],
            'mailbox_ids.*' => ['required', 'string', 'max:320'],
            'password_mode' => ['nullable', 'string', 'in:each,same'],
            'password' => ['nullable', 'string', 'max:128'],
            'force_password_change' => ['sometimes', 'boolean'],
        ], [
            'mailbox_ids.required' => __('comail.mailboxes.bulk.none_selected'),
        ]);

        $server = MailServer::comail();
        abort_unless($server instanceof MailServer, 404);

        $action = (string) $data['action'];
        $isPassword = $action === ComailMailboxActions::PASSWORD;
        $force = $isPassword ? $request->boolean('force_password_change') : null;
        $same = null;
        if ($isPassword && ($data['password_mode'] ?? 'each') === 'same') {
            $same = trim((string) ($data['password'] ?? ''));
            if ($same === '') {
                $same = ComailMailboxActions::generatePassword();
            } elseif (($problem = ComailMailboxActions::passwordProblem($same)) !== null) {
                return back()->withInput($request->except('password'))->with('error', $problem);
            }
        }

        $client = MailcowClient::forServer($server);
        $sites = self::sitesByDomain();
        $addresses = array_values(array_unique(array_map(
            static fn (mixed $address): string => strtolower(trim((string) $address)),
            $data['mailbox_ids'],
        )));

        $done = 0;
        $failures = [];
        $passwords = [];
        foreach ($addresses as $address) {
            $password = null;
            try {
                $mailbox = $client->mailbox($address);
                if ($mailbox === null) {
                    $failures[] = __('comail.mailboxes.bulk.not_found', ['address' => $address]);

                    continue;
                }
                $username = strtolower((string) $mailbox['username']);
                $password = $isPassword ? ($same ?? ComailMailboxActions::generatePassword()) : null;
                $this->actions->apply($client, $username, $action, $password, $force);
            } catch (MailcowException $exception) {
                Log::warning('ops.comail_bulk_failed', ['action' => $action, 'error' => $exception->error, 'provider_key' => $exception->providerKey]);
                $failures[] = $address.': '.$exception->turkishMessage();
                if ($exception->isUnreachable()) {
                    break;
                }

                continue;
            }

            $done++;
            if ($password !== null) {
                $passwords[] = ['address' => $username, 'password' => $password];
            }
            $this->audit($request, $server, $sites[ComailDirectory::domainOf($username)] ?? null, $action, [
                'address' => $username,
                'bulk' => true,
            ] + ($isPassword ? ['force_password_change' => $force] : []));
        }

        $redirect = back(fallback: route('ops.comail.mailboxes'))
            ->with('status', __('comail.mailboxes.bulk.done.'.$action, ['count' => $done]));
        if ($failures !== []) {
            $redirect->with('error', __('comail.mailboxes.bulk.failed', ['count' => count($failures)]).' '.implode(' · ', array_slice($failures, 0, 5)));
        }
        if ($passwords !== []) {
            $redirect->with(self::SESSION_PASSWORDS, $passwords);
        }

        return $redirect;
    }

    /**
     * "Webmail'e gir" from the mailbox page: any mailbox on the server, with or without a site.
     */
    public function webmailAny(Request $request, string $address): RedirectResponse
    {
        $this->authorize('ops.danger');

        $server = MailServer::comail();
        abort_unless($server instanceof MailServer, 404);
        $client = MailcowClient::forServer($server);
        $fallback = route('ops.comail.mailboxes');

        try {
            $mailbox = $client->mailbox(strtolower(rawurldecode($address)));
            abort_if($mailbox === null, 404);

            return $this->webmailLogin($request, $server, $client, $mailbox, self::sitesByDomain(), $fallback);
        } catch (MailcowException $exception) {
            return $this->webmailFailed($exception, $fallback);
        }
    }

    /**
     * "Webmail'e gir" from the site's CoMail card: only a mailbox on one of the site's active domains.
     */
    public function webmail(Request $request, Site $site, string $address): RedirectResponse
    {
        $this->authorize('ops.danger');

        $server = MailServer::comail();
        abort_unless($server instanceof MailServer, 404);

        $directory = new ComailDirectory($site, MailcowClient::forServer($server));
        $fallback = route('ops.sites.show', $site).'#site-comail';

        try {
            $mailbox = $directory->mailbox(rawurldecode($address));
            abort_if($mailbox === null, 404);

            return $this->webmailLogin($request, $server, $directory->client, $mailbox, [ComailDirectory::domainOf((string) $mailbox['username']) => $site], $fallback);
        } catch (MailcowException $exception) {
            return $this->webmailFailed($exception, $fallback);
        }
    }

    /**
     * @param  array<string, mixed>  $mailbox
     * @param  array<string, Site>  $sites
     *
     * @throws MailcowException
     */
    private function webmailLogin(Request $request, MailServer $server, MailcowClient $client, array $mailbox, array $sites, string $fallback): RedirectResponse
    {
        $username = strtolower((string) $mailbox['username']);
        if (! ComailDirectory::isActive($mailbox)) {
            return redirect()->back(fallback: $fallback)->with('error', __('comail.mailboxes.errors.inactive', ['address' => $username]));
        }

        $login = $this->sso->issue($server, $client, $username, ComailWebmailSso::OPS_APP_NAME);

        // Never the token or the app password.
        $this->audit($request, $server, $sites[ComailDirectory::domainOf($username)] ?? null, 'webmail_login', ['address' => $username]);

        return redirect()->away($login['url'])->withHeaders([
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function webmailFailed(MailcowException $exception, string $fallback): RedirectResponse
    {
        Log::warning('ops.comail_webmail_failed', [
            'error' => $exception->error,
            'provider_key' => $exception->providerKey,
        ]);

        return redirect()->back(fallback: $fallback)->with('error', __('comail.site.errors.failed', ['message' => $exception->turkishMessage()]));
    }

    /**
     * On the site when the domain has one, otherwise on the CoMail server.
     *
     * @param  array<string, mixed>  $after
     */
    private function audit(Request $request, MailServer $server, ?Site $site, string $action, array $after): void
    {
        $name = match ($action) {
            'webmail_login' => 'comail.webmail_login_ops',
            ComailMailboxActions::DELETE => 'comail.mailbox_deleted',
            ComailMailboxActions::PASSWORD => 'comail.mailbox_password_reset',
            default => 'comail.mailbox_'.$action.'d',
        };
        $subject = $site instanceof Site ? $site : $server;

        $subject->auditLogs()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => $name,
            'after' => $after + ['source' => 'ops'] + ($site instanceof Site ? ['site_id' => $site->id] : []),
            'ip' => $request->ip(),
        ]);
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    private function siteMailboxes(Site $site): array
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

        $mailboxes = array_map(static fn (array $row): array => self::row($row, []), $rows);
        usort($mailboxes, static fn (array $a, array $b): int => strcmp($a['address'], $b['address']));

        return [$mailboxes, null];
    }

    /**
     * Active CoMail domains → their site.
     *
     * @return array<string, Site>
     */
    private static function sitesByDomain(): array
    {
        $map = [];
        foreach (SiteComailDomain::query()->with('site')->where('status', SiteComailDomain::STATUS_ACTIVE)->get() as $row) {
            if ($row->site instanceof Site) {
                $map[strtolower((string) $row->domain)] = $row->site;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, Site>  $sites
     * @return array{address: string, name: string, domain: string, quota_mb: int, used_bytes: int, active: bool, must_change_password: bool, site: Site|null}
     */
    private static function row(array $row, array $sites): array
    {
        $address = strtolower((string) ($row['username'] ?? ''));
        $domain = ComailDirectory::domainOf($address);

        return [
            'address' => $address,
            'name' => (string) ($row['name'] ?? ''),
            'domain' => $domain,
            'quota_mb' => ComailDirectory::bytesToMb($row['quota'] ?? 0),
            'used_bytes' => (int) ($row['quota_used'] ?? 0),
            'active' => ComailDirectory::isActive($row),
            'must_change_password' => ComailDirectory::mustChangePassword($row),
            'site' => $sites[$domain] ?? null,
        ];
    }

    /**
     * @param  array{address: string, name: string, domain: string, site: Site|null}  $mailbox
     */
    private static function matches(array $mailbox, string $query, string $domain, string $scope): bool
    {
        if ($domain !== '' && $mailbox['domain'] !== $domain) {
            return false;
        }
        if ($scope === 'none' && $mailbox['site'] !== null) {
            return false;
        }
        if ($scope === 'linked' && $mailbox['site'] === null) {
            return false;
        }

        return $query === '' || str_contains($mailbox['address'].' '.mb_strtolower($mailbox['name']), $query);
    }
}
