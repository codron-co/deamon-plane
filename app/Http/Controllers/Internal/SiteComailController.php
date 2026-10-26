<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MailServer;
use App\Models\Site;
use App\Models\SiteComailDomain;
use App\Services\Comail\ComailAutoreply;
use App\Services\Comail\ComailDirectory;
use App\Services\Comail\ComailDns;
use App\Services\Comail\ComailDomainProvisioner;
use App\Services\Comail\ComailException;
use App\Services\Comail\ComailLimits;
use App\Services\Comail\ComailSiteConfigurer;
use App\Services\Comail\MailcowClient;
use App\Services\Comail\MailcowException;
use App\Services\Hostinger\HostingerMailClient;
use App\Services\Hostinger\HostingerMailException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * CMS → Plane CoMail API (`/internal/site/v1/comail/*`), signed per site like
 * the Hostinger proxy (EnsureSiteMailSignature). The mailcow key never leaves
 * Plane. Every object is checked against the calling site's own CoMail domains
 * and every create/edit against its package. Errors: `{error, message}` with a
 * Turkish message; passwords are never echoed, logged or audited.
 */
class SiteComailController extends Controller
{
    private const LOCAL_PART = '/\A[a-z0-9](?:[a-z0-9._+-]{0,62}[a-z0-9])?\z/';

    private const HOSTNAME = '/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/';

    public function __construct(
        private readonly ComailLimits $limits,
        private readonly ComailDns $dns,
        private readonly ComailDomainProvisioner $provisioner,
        private readonly ComailSiteConfigurer $configurer,
    ) {}

    // ── Overview ────────────────────────────────────────────────────────

    public function overview(Request $request): JsonResponse
    {
        return $this->run($request, function (Site $site, ComailDirectory $directory, MailServer $server): JsonResponse {
            $host = $server->comailHostname();
            $base = $server->comailBaseUrl();
            $taken = $site->comailDomains()->pluck('domain')->all();

            return $this->ok([
                'package' => $this->limits->package($site)->toLimitsArray(),
                'usage' => $this->limits->usage($directory),
                // The web root opens Roundcube (the inbox); /?panel=1 is the mail
                // user panel (spam filter, app passwords, 2FA).
                'webmail_url' => $base,
                'panel_url' => $base.'/?panel=1',
                'client' => [
                    'imap' => ['host' => $host, 'port' => 993, 'security' => 'SSL'],
                    'pop3' => ['host' => $host, 'port' => 995, 'security' => 'SSL'],
                    'smtp' => ['host' => $host, 'port' => 465, 'security' => 'SSL'],
                    'smtp_alt' => ['host' => $host, 'port' => 587, 'security' => 'STARTTLS'],
                    'managesieve' => ['host' => $host, 'port' => 4190, 'security' => 'STARTTLS'],
                ],
                'domains' => $site->comailDomains()->get()
                    ->map(fn (SiteComailDomain $row): array => $this->domainRow($row))
                    ->values()
                    ->all(),
                'available_domains' => array_values(array_diff(ComailDomainProvisioner::eligibleHosts($site), $taken)),
            ]);
        });
    }

    // ── Domains ─────────────────────────────────────────────────────────

    public function storeDomain(Request $request): JsonResponse
    {
        return $this->run($request, function (Site $site) use ($request): JsonResponse {
            $data = $this->validated($request, [
                'domain' => ['required', 'string', 'max:253'],
            ]);
            $domain = strtolower(trim((string) $data['domain']));

            $existing = $site->comailDomains()->where('domain', $domain)->first();
            if ($existing instanceof SiteComailDomain && $existing->isActive()) {
                return $this->ok($this->domainRow($existing));
            }

            if (! in_array($domain, ComailDomainProvisioner::eligibleHosts($site), true)) {
                throw ComailException::domainNotOwned();
            }
            if (! $existing instanceof SiteComailDomain) {
                $this->limits->assertCanAddDomain($site);
            }

            $primary = $site->comailDomains()->where('is_primary', true)->doesntExist()
                && ComailDomainProvisioner::primaryHost($site) === $domain;
            $row = $this->provisioner->ensure($site, $domain, $primary);
            $this->applyDnsQuietly($row);
            $this->configurer->queue($site);

            $this->audit($site, $request, 'comail.domain_added', ['domain' => $domain]);

            return $this->ok($this->domainRow($row->fresh() ?? $row), 201);
        });
    }

    public function destroyDomain(Request $request, string $domain): JsonResponse
    {
        return $this->run($request, function (Site $site, ComailDirectory $directory, MailServer $server) use ($request, $domain): JsonResponse {
            $row = $this->ownedDomain($site, $domain);
            if ($row->is_primary) {
                throw ComailException::validation('Birincil alan adı E-posta’dan kaldırılamaz.');
            }

            $client = $directory->client;
            if ($row->isActive() && $client->mailboxes($row->domain) !== []) {
                throw new ComailException('domain_has_mailboxes', 'Bu alan adında posta kutusu var; önce posta kutularını silin.');
            }

            $this->provisioner->remove($row, $client);
            $this->configurer->queue($site);
            $this->audit($site, $request, 'comail.domain_removed', ['domain' => $row->domain]);

            return $this->ok([]);
        });
    }

    public function dns(Request $request, string $domain): JsonResponse
    {
        return $this->run($request, function (Site $site) use ($domain): JsonResponse {
            return $this->ok($this->dns->check($this->ownedDomain($site, $domain)));
        });
    }

    public function applyDns(Request $request, string $domain): JsonResponse
    {
        return $this->run($request, function (Site $site) use ($request, $domain): JsonResponse {
            $row = $this->ownedDomain($site, $domain);
            $result = $this->dns->apply($row);
            $this->audit($site, $request, 'comail.dns_applied', ['domain' => $row->domain]);

            return $this->ok($result);
        });
    }

    public function activate(Request $request, string $domain): JsonResponse
    {
        return $this->run($request, function (Site $site) use ($request, $domain): JsonResponse {
            $row = $this->ownedDomain($site, $domain);
            $result = $this->dns->activate($row);
            $this->audit($site, $request, 'comail.mx_activated', ['domain' => $row->domain]);

            return $this->ok($result);
        });
    }

    // ── Mailboxes ───────────────────────────────────────────────────────

    public function mailboxes(Request $request): JsonResponse
    {
        return $this->run($request, function (Site $site, ComailDirectory $directory): JsonResponse {
            return $this->ok([
                'mailboxes' => array_map(fn (array $row): array => $this->mailboxRow($row), $directory->mailboxes()),
            ]);
        });
    }

    public function storeMailbox(Request $request): JsonResponse
    {
        return $this->run($request, function (Site $site, ComailDirectory $directory) use ($request): JsonResponse {
            $request->merge([
                'local_part' => strtolower(trim((string) $request->input('local_part', ''))),
                'domain' => strtolower(trim((string) $request->input('domain', ''))),
            ]);
            $data = $this->validated($request, [
                'local_part' => ['required', 'string', 'max:64', 'regex:'.self::LOCAL_PART],
                'domain' => ['required', 'string', 'max:253'],
                'name' => ['nullable', 'string', 'max:255'],
                'password' => ['required', 'string', 'min:8', 'max:128'],
                'quota_mb' => ['nullable', 'integer', 'min:1'],
            ]);
            $this->assertPasswordStrength((string) $data['password']);

            if (! $directory->ownsDomain($data['domain'])) {
                throw ComailException::domainNotOwned();
            }

            $address = $data['local_part'].'@'.$data['domain'];
            if ($directory->mailbox($address) !== null) {
                throw ComailException::validation('Bu adresle bir posta kutusu zaten var.');
            }

            $quota = $this->limits->quotaForNewMailbox($directory, isset($data['quota_mb']) ? (int) $data['quota_mb'] : null);
            $name = trim((string) ($data['name'] ?? ''));

            $directory->client->addMailbox([
                'local_part' => $data['local_part'],
                'domain' => $data['domain'],
                'name' => $name !== '' ? $name : $data['local_part'],
                'quota' => $quota,
                'password' => $data['password'],
                'password2' => $data['password'],
                'active' => 1,
                'force_pw_update' => 0,
                'tls_enforce_in' => 0,
                'tls_enforce_out' => 0,
            ]);

            $this->audit($site, $request, 'comail.mailbox_created', ['address' => $address, 'quota_mb' => $quota]);

            $row = $directory->client->mailbox($address) ?? [
                'username' => $address,
                'local_part' => $data['local_part'],
                'domain' => $data['domain'],
                'name' => $name !== '' ? $name : $data['local_part'],
                'quota' => $quota * 1048576,
                'active' => 1,
            ];

            return $this->ok($this->mailboxRow($row), 201, 'mailbox');
        });
    }

    public function updateMailbox(Request $request, string $address): JsonResponse
    {
        return $this->run($request, function (Site $site, ComailDirectory $directory) use ($request, $address): JsonResponse {
            $mailbox = $this->ownedMailbox($directory, $address);
            $data = $this->validated($request, [
                'name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'password' => ['sometimes', 'string', 'min:8', 'max:128'],
                'quota_mb' => ['sometimes', 'integer', 'min:1'],
                'active' => ['sometimes', 'boolean'],
            ]);

            $attributes = [];
            $changed = [];
            if (array_key_exists('name', $data)) {
                $attributes['name'] = trim((string) $data['name']);
                $changed[] = 'name';
            }
            if (array_key_exists('password', $data)) {
                $this->assertPasswordStrength((string) $data['password']);
                $attributes['password'] = $data['password'];
                $attributes['password2'] = $data['password'];
                $changed[] = 'password';
            }
            if (array_key_exists('quota_mb', $data)) {
                $this->limits->assertQuotaChange($directory, $mailbox, (int) $data['quota_mb']);
                $attributes['quota'] = (int) $data['quota_mb'];
                $changed[] = 'quota_mb';
            }
            if (array_key_exists('active', $data)) {
                $attributes['active'] = filter_var($data['active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                $changed[] = 'active';
            }

            $username = (string) $mailbox['username'];
            if ($attributes !== []) {
                $directory->client->editMailbox($username, $attributes);
                $this->audit($site, $request, 'comail.mailbox_updated', [
                    'address' => $username,
                    'changed' => $changed,
                ] + (isset($attributes['quota']) ? ['quota_mb' => $attributes['quota']] : []));
            }

            return $this->ok($this->mailboxRow($directory->client->mailbox($username) ?? $mailbox), key: 'mailbox');
        });
    }

    public function destroyMailbox(Request $request, string $address): JsonResponse
    {
        return $this->run($request, function (Site $site, ComailDirectory $directory) use ($request, $address): JsonResponse {
            $mailbox = $this->ownedMailbox($directory, $address);
            $directory->client->deleteMailbox((string) $mailbox['username']);
            $this->audit($site, $request, 'comail.mailbox_deleted', ['address' => (string) $mailbox['username']]);

            return $this->ok([]);
        });
    }

    // ── Auto-reply ──────────────────────────────────────────────────────

    public function autoreply(Request $request, string $address): JsonResponse
    {
        return $this->run($request, function (Site $site, ComailDirectory $directory) use ($address): JsonResponse {
            $mailbox = $this->ownedMailbox($directory, $address);

            return $this->ok($this->autoreplyState($directory->client, (string) $mailbox['username']));
        });
    }

    public function updateAutoreply(Request $request, string $address): JsonResponse
    {
        return $this->run($request, function (Site $site, ComailDirectory $directory) use ($request, $address): JsonResponse {
            $mailbox = $this->ownedMailbox($directory, $address);
            $data = $this->validated($request, [
                'enabled' => ['required', 'boolean'],
                'subject' => ['required_if:enabled,true,1', 'nullable', 'string', 'max:200'],
                'body' => ['required_if:enabled,true,1', 'nullable', 'string', 'max:4000'],
                'starts_on' => ['nullable', 'date_format:Y-m-d'],
                'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            ]);

            $enabled = filter_var($data['enabled'], FILTER_VALIDATE_BOOLEAN);
            $username = (string) $mailbox['username'];
            $client = $directory->client;
            $script = ComailAutoreply::script([
                'subject' => (string) ($data['subject'] ?? ''),
                'body' => (string) ($data['body'] ?? ''),
                'starts_on' => $data['starts_on'] ?? null,
                'ends_on' => $data['ends_on'] ?? null,
            ]);

            $filter = $this->autoreplyFilter($client, $username);
            if ($filter !== null) {
                $client->editFilter((int) $filter['id'], [
                    'script_data' => $script,
                    'script_desc' => ComailAutoreply::DESCRIPTION,
                    'filter_type' => 'prefilter',
                    'active' => $enabled ? 1 : 0,
                ]);
            } elseif ($enabled) {
                $client->addFilter($username, ComailAutoreply::DESCRIPTION, $script, true);
            }

            $this->audit($site, $request, 'comail.autoreply_updated', ['address' => $username, 'enabled' => $enabled]);

            return $this->ok($this->autoreplyState($client, $username));
        });
    }

    // ── Aliases ─────────────────────────────────────────────────────────

    public function aliases(Request $request): JsonResponse
    {
        return $this->run($request, function (Site $site, ComailDirectory $directory): JsonResponse {
            return $this->ok([
                'aliases' => array_map(fn (array $row): array => $this->aliasRow($directory, $row), $directory->aliases()),
            ]);
        });
    }

    public function storeAlias(Request $request): JsonResponse
    {
        return $this->run($request, function (Site $site, ComailDirectory $directory) use ($request): JsonResponse {
            $request->merge(['address' => strtolower(trim((string) $request->input('address', '')))]);
            $data = $this->validated($request, [
                'address' => ['required', 'string', 'max:320'],
                'goto' => ['required', 'array', 'min:1', 'max:20'],
                'goto.*' => ['required', 'string', 'email', 'max:320'],
            ]);

            $address = (string) $data['address'];
            $catchAll = str_starts_with($address, '@');
            $local = $catchAll ? '' : (string) strstr($address, '@', true);
            $domain = ComailDirectory::domainOf($address);
            if ($domain === '' || (! $catchAll && preg_match(self::LOCAL_PART, $local) !== 1)) {
                throw ComailException::validation('Adres "ad@alanadi" ya da tümünü yakalamak için "@alanadi" biçiminde olmalı.');
            }
            if (! $directory->ownsDomain($domain)) {
                throw ComailException::domainNotOwned();
            }

            foreach ($directory->aliases() as $existing) {
                if (strtolower((string) ($existing['address'] ?? '')) === $address) {
                    throw ComailException::validation('Bu adres için zaten bir yönlendirme var.');
                }
            }
            $this->limits->assertCanAddAlias($directory);

            $goto = ComailDirectory::gotoList($data['goto']);
            $directory->client->addAlias($address, implode(',', $goto));
            $this->audit($site, $request, 'comail.alias_created', ['address' => $address, 'goto' => $goto]);

            $directory->forget();
            foreach ($directory->aliases() as $row) {
                if (strtolower((string) ($row['address'] ?? '')) === $address) {
                    return $this->ok($this->aliasRow($directory, $row), 201, 'alias');
                }
            }

            return $this->ok($this->aliasRow($directory, ['id' => 0, 'address' => $address, 'goto' => implode(',', $goto), 'active' => 1]), 201, 'alias');
        });
    }

    public function updateAlias(Request $request, string $id): JsonResponse
    {
        return $this->run($request, function (Site $site, ComailDirectory $directory) use ($request, $id): JsonResponse {
            $alias = $this->ownedAlias($directory, $id);
            $data = $this->validated($request, [
                'goto' => ['sometimes', 'array', 'min:1', 'max:20'],
                'goto.*' => ['required', 'string', 'email', 'max:320'],
                'active' => ['sometimes', 'boolean'],
            ]);

            $attributes = [];
            if (array_key_exists('goto', $data)) {
                $attributes['goto'] = implode(',', ComailDirectory::gotoList($data['goto']));
            }
            if (array_key_exists('active', $data)) {
                $attributes['active'] = filter_var($data['active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }

            if ($attributes !== []) {
                $directory->client->editAlias((int) $alias['id'], $attributes);
                $this->audit($site, $request, 'comail.alias_updated', ['alias_id' => (int) $alias['id'], 'address' => (string) $alias['address']] + $attributes);
            }

            $directory->forget();

            return $this->ok($this->aliasRow($directory, $directory->alias((int) $alias['id']) ?? ($attributes + $alias)), key: 'alias');
        });
    }

    public function destroyAlias(Request $request, string $id): JsonResponse
    {
        return $this->run($request, function (Site $site, ComailDirectory $directory) use ($request, $id): JsonResponse {
            $alias = $this->ownedAlias($directory, $id);
            $directory->client->deleteAlias((int) $alias['id']);
            $this->audit($site, $request, 'comail.alias_deleted', ['alias_id' => (int) $alias['id'], 'address' => (string) $alias['address']]);

            return $this->ok([]);
        });
    }

    // ── Migrations (imapsync) ───────────────────────────────────────────

    public function migrations(Request $request): JsonResponse
    {
        return $this->run($request, function (Site $site, ComailDirectory $directory): JsonResponse {
            return $this->ok([
                'migrations' => array_map(fn (array $row): array => $this->migrationRow($row), $this->ownedSyncJobs($directory)),
            ]);
        });
    }

    public function storeMigration(Request $request): JsonResponse
    {
        return $this->run($request, function (Site $site, ComailDirectory $directory) use ($request): JsonResponse {
            $request->merge([
                'mailbox' => strtolower(trim((string) $request->input('mailbox', ''))),
                'host' => strtolower(trim((string) $request->input('host', ''))),
                'security' => strtoupper(trim((string) $request->input('security', 'SSL'))),
            ]);
            $data = $this->validated($request, [
                'mailbox' => ['required', 'string', 'email', 'max:320'],
                'host' => ['required', 'string', 'max:253', 'regex:'.self::HOSTNAME],
                'port' => ['required', 'integer', 'between:1,65535'],
                'user' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'max:255'],
                'security' => ['required', 'string', 'in:SSL,TLS,PLAIN'],
            ]);

            if (! $directory->ownsAddress((string) $data['mailbox'])) {
                throw ComailException::domainNotOwned();
            }
            $mailbox = $directory->mailbox((string) $data['mailbox']);
            if ($mailbox === null) {
                throw ComailException::notFound('Posta kutusu bulunamadı.');
            }

            $username = (string) $mailbox['username'];
            $directory->client->addSyncJob(self::syncJobAttributes(
                $username,
                (string) $data['host'],
                (int) $data['port'],
                (string) $data['user'],
                (string) $data['password'],
                (string) $data['security'],
            ));
            $this->audit($site, $request, 'comail.migration_created', [
                'mailbox' => $username,
                'host' => $data['host'],
                'port' => (int) $data['port'],
                'security' => $data['security'],
            ]);

            $created = null;
            foreach ($this->ownedSyncJobs($directory) as $row) {
                if (strtolower((string) ($row['user2'] ?? '')) === $username
                    && strtolower((string) ($row['host1'] ?? '')) === $data['host']
                    && (string) ($row['user1'] ?? '') === (string) $data['user']
                    && ($created === null || (int) $row['id'] > (int) $created['id'])) {
                    $created = $row;
                }
            }

            return $this->ok($this->migrationRow($created ?? [
                'id' => 0,
                'user2' => $username,
                'host1' => $data['host'],
                'port1' => (int) $data['port'],
                'user1' => $data['user'],
                'enc1' => $data['security'],
                'active' => 1,
            ]), 201, 'migration');
        });
    }

    public function destroyMigration(Request $request, string $id): JsonResponse
    {
        return $this->run($request, function (Site $site, ComailDirectory $directory) use ($request, $id): JsonResponse {
            $wanted = ctype_digit($id) ? (int) $id : -1;
            foreach ($this->ownedSyncJobs($directory) as $row) {
                if ((int) ($row['id'] ?? 0) === $wanted) {
                    $directory->client->deleteSyncJob($wanted);
                    $this->audit($site, $request, 'comail.migration_deleted', ['migration_id' => $wanted, 'mailbox' => (string) ($row['user2'] ?? '')]);

                    return $this->ok([]);
                }
            }

            $this->denied($site, 'migration', $id);

            throw ComailException::notFound('Taşıma işi bulunamadı.');
        });
    }

    // ── H-Posta (Hostinger) one-click migration ─────────────────────────

    public function hpostaMailboxes(Request $request): JsonResponse
    {
        return $this->run($request, function (Site $site, ComailDirectory $directory): JsonResponse {
            if (! $this->hasHposta($site)) {
                return $this->ok(['available' => false, 'mailboxes' => []]);
            }

            $jobs = $this->syncJobsByMailbox($directory);
            $out = [];
            foreach ($this->hpostaBoxes($site) as $box) {
                $email = strtolower((string) $box['email']);
                $out[] = [
                    'id' => (string) $box['id'],
                    'email' => $email,
                    'domain' => ComailDirectory::domainOf($email),
                    'in_comail' => $directory->mailbox($email) !== null,
                    'migration_id' => isset($jobs[$email]) ? (string) $jobs[$email] : null,
                ];
            }

            return $this->ok(['available' => true, 'mailboxes' => $out]);
        });
    }

    public function hpostaMigrate(Request $request): JsonResponse
    {
        return $this->run($request, function (Site $site, ComailDirectory $directory) use ($request): JsonResponse {
            $data = $this->validated($request, [
                'mailbox_ids' => ['required', 'array', 'min:1', 'max:50'],
                'mailbox_ids.*' => ['required', 'string', 'max:128'],
            ]);
            if (! $this->hasHposta($site)) {
                throw ComailException::validation('Bu sitede H-Posta (Hostinger) bağlı değil.');
            }

            $boxes = [];
            foreach ($this->hpostaBoxes($site) as $box) {
                $boxes[(string) $box['id']] = $box;
            }

            $selected = [];
            foreach (array_values(array_unique(array_map('strval', $data['mailbox_ids']))) as $id) {
                if (! isset($boxes[$id])) {
                    $this->denied($site, 'hposta_mailbox', $id);

                    throw ComailException::notFound('H-Posta kutusu bulunamadı.');
                }
                $selected[] = $boxes[$id];
            }

            // All or nothing on the domain rule: nothing changes when one box cannot move yet.
            foreach ($selected as $box) {
                if (! $directory->ownsDomain(ComailDirectory::domainOf((string) $box['email']))) {
                    throw new ComailException(
                        'domain_not_in_comail',
                        ComailDirectory::domainOf((string) $box['email']).' alan adı henüz E-posta’da açık değil; önce alan adını ekleyin.',
                    );
                }
            }

            $results = [];
            foreach ($selected as $box) {
                $results[] = $this->migrateHpostaBox($site, $directory, $request, $box);
            }

            return $this->ok(['results' => $results]);
        });
    }

    /**
     * One mailbox: new password on H-Posta and CoMail (the same one), CoMail
     * mailbox created if missing, imapsync job from imap.hostinger.com. The
     * password is returned once in the response and never stored or logged.
     *
     * @param  array<string, mixed>  $box
     * @return array<string, mixed>
     */
    private function migrateHpostaBox(Site $site, ComailDirectory $directory, Request $request, array $box): array
    {
        $email = strtolower((string) $box['email']);
        $password = null;

        try {
            $existing = $directory->mailbox($email);
            // Limits first, so a full package never leaves a changed H-Posta password behind.
            $quota = $existing === null ? $this->limits->quotaForNewMailbox($directory, null) : null;

            $password = self::generatePassword();
            HostingerMailClient::forSite($site, ComailDirectory::domainOf($email))
                ->changePassword((string) $box['id'], $password);

            if ($existing === null) {
                $local = (string) strstr($email, '@', true);
                $directory->client->addMailbox([
                    'local_part' => $local,
                    'domain' => ComailDirectory::domainOf($email),
                    'name' => $local,
                    'quota' => $quota,
                    'password' => $password,
                    'password2' => $password,
                    'active' => 1,
                    'force_pw_update' => 0,
                    'tls_enforce_in' => 0,
                    'tls_enforce_out' => 0,
                ]);
                $directory->forget();
            } else {
                $directory->client->editMailbox((string) $existing['username'], [
                    'password' => $password,
                    'password2' => $password,
                ]);
            }

            if (! isset($this->syncJobsByMailbox($directory)[$email])) {
                $directory->client->addSyncJob(self::syncJobAttributes($email, 'imap.hostinger.com', 993, $email, $password, 'SSL'));
            }
        } catch (ComailException $exception) {
            return $this->hpostaResult($site, $request, $email, false, $password, $exception->error, $exception->getMessage());
        } catch (MailcowException $exception) {
            return $this->hpostaResult($site, $request, $email, false, $password, $exception->isUnreachable() ? 'provider_unreachable' : 'provider_error', $exception->turkishMessage());
        } catch (HostingerMailException) {
            return $this->hpostaResult($site, $request, $email, false, null, 'provider_error', 'H-Posta parolası değiştirilemedi; bu kutuda hiçbir şey değişmedi.');
        }

        return $this->hpostaResult($site, $request, $email, true, $password);
    }

    /**
     * @return array<string, mixed>
     */
    private function hpostaResult(Site $site, Request $request, string $email, bool $ok, ?string $password, ?string $error = null, ?string $message = null): array
    {
        $this->audit($site, $request, 'comail.hposta_migrated', array_filter([
            'email' => $email,
            'ok' => $ok,
            'error' => $error,
            'password_changed' => $password !== null,
        ], static fn (mixed $value): bool => $value !== null));

        return array_filter([
            'email' => $email,
            'ok' => $ok,
            'error' => $error,
            'message' => $message,
            // Once: the new password of both H-Posta and CoMail. Also on a later failure,
            // because the H-Posta password has already changed by then.
            'password' => $password,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function hasHposta(Site $site): bool
    {
        $site->loadMissing(['mailServer', 'mailBindings']);

        return $site->mailServer instanceof MailServer
            && $site->mailServer->isHostingerReady()
            && $site->mailDomains() !== [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function hpostaBoxes(Site $site): array
    {
        try {
            $boxes = HostingerMailClient::listAllMailboxes($site);
        } catch (HostingerMailException) {
            throw new ComailException('provider_unreachable', 'H-Posta kutuları okunamadı. Biraz sonra tekrar deneyin.', 502);
        }

        return array_values(array_filter(
            $boxes,
            static fn (array $box): bool => $box['id'] !== '' && str_contains($box['email'], '@'),
        ));
    }

    /**
     * @return array<string, int> mailbox address => sync job id
     */
    private function syncJobsByMailbox(ComailDirectory $directory): array
    {
        $out = [];
        foreach ($this->ownedSyncJobs($directory) as $row) {
            $out[strtolower((string) ($row['user2'] ?? ''))] ??= (int) ($row['id'] ?? 0);
        }

        return $out;
    }

    /**
     * 16 characters: upper, lower, digit and one of !#%*-_+= (H-Posta and mailcow both accept it).
     */
    public static function generatePassword(): string
    {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $digits = '23456789';
        $special = '!#%*-_+=';
        $all = $upper.$lower.$digits;

        $chars = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $special[random_int(0, strlen($special) - 1)],
        ];
        while (count($chars) < 16) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }

        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    /**
     * mailcow add/syncjob body (imapsync into `username`).
     *
     * @return array<string, mixed>
     */
    private static function syncJobAttributes(string $username, string $host, int $port, string $user, string $password, string $security): array
    {
        return [
            'username' => $username,
            'host1' => $host,
            'port1' => $port,
            'user1' => $user,
            'password1' => $password,
            'enc1' => $security,
            'mins_interval' => 20,
            'subfolder2' => '',
            'maxage' => 0,
            'maxbytespersecond' => 0,
            'timeout1' => 600,
            'timeout2' => 600,
            'exclude' => '(?i)spam|(?i)junk',
            'custom_params' => '',
            'delete2duplicates' => 1,
            'delete1' => 0,
            'delete2' => 0,
            'automap' => 1,
            'skipcrossduplicates' => 0,
            'subscribeall' => 1,
            'active' => 1,
        ];
    }

    // ── Plumbing ────────────────────────────────────────────────────────

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

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules, self::MESSAGES, self::ATTRIBUTES);
        if ($validator->fails()) {
            throw ComailException::validation((string) $validator->errors()->first());
        }

        return $validator->validated();
    }

    private const ATTRIBUTES = [
        'domain' => 'Alan adı',
        'local_part' => 'Adres',
        'name' => 'Ad',
        'password' => 'Parola',
        'quota_mb' => 'Kota',
        'active' => 'Etkin',
        'enabled' => 'Etkin',
        'subject' => 'Konu',
        'body' => 'Mesaj',
        'starts_on' => 'Başlangıç tarihi',
        'ends_on' => 'Bitiş tarihi',
        'address' => 'Adres',
        'goto' => 'Hedef adresler',
        'goto.*' => 'Hedef adres',
        'mailbox' => 'Posta kutusu',
        'host' => 'Sunucu',
        'port' => 'Port',
        'user' => 'Kullanıcı adı',
        'security' => 'Güvenlik',
        'mailbox_ids' => 'Posta kutuları',
    ];

    private const MESSAGES = [
        'required' => ':attribute zorunlu.',
        'required_if' => ':attribute zorunlu.',
        'string' => ':attribute metin olmalı.',
        'array' => ':attribute bir liste olmalı.',
        'boolean' => ':attribute evet ya da hayır olmalı.',
        'integer' => ':attribute bir tam sayı olmalı.',
        'email' => ':attribute geçerli bir e-posta adresi olmalı.',
        'regex' => ':attribute biçimi geçersiz.',
        'in' => ':attribute geçersiz.',
        'between' => ':attribute :min ile :max arasında olmalı.',
        'date_format' => ':attribute YYYY-AA-GG biçiminde olmalı.',
        'after_or_equal' => ':attribute başlangıç tarihinden önce olamaz.',
        'min' => [
            'string' => ':attribute en az :min karakter olmalı.',
            'numeric' => ':attribute en az :min olmalı.',
            'array' => ':attribute en az :min öğe içermeli.',
        ],
        'max' => [
            'string' => ':attribute en fazla :max karakter olabilir.',
            'numeric' => ':attribute en fazla :max olabilir.',
            'array' => ':attribute en fazla :max öğe içerebilir.',
        ],
    ];

    private function assertPasswordStrength(string $password): void
    {
        if (preg_match('/\pL/u', $password) !== 1 || preg_match('/\d/', $password) !== 1) {
            throw ComailException::validation('Parola en az bir harf ve bir rakam içermeli.');
        }
    }

    private function ownedDomain(Site $site, string $domain): SiteComailDomain
    {
        $row = $site->comailDomains()->where('domain', strtolower(trim($domain)))->first();
        if (! $row instanceof SiteComailDomain) {
            throw ComailException::notFound('Alan adı bulunamadı.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function ownedMailbox(ComailDirectory $directory, string $address): array
    {
        $mailbox = $directory->mailbox(rawurldecode($address));
        if ($mailbox === null) {
            $this->denied($directory->site, 'mailbox', $address);

            throw ComailException::notFound('Posta kutusu bulunamadı.');
        }

        return $mailbox;
    }

    /**
     * @return array<string, mixed>
     */
    private function ownedAlias(ComailDirectory $directory, string $id): array
    {
        $alias = ctype_digit($id) ? $directory->alias((int) $id) : null;
        if ($alias === null) {
            $this->denied($directory->site, 'alias', $id);

            throw ComailException::notFound('Yönlendirme bulunamadı.');
        }

        return $alias;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ownedSyncJobs(ComailDirectory $directory): array
    {
        return array_values(array_filter(
            $directory->client->syncJobs(),
            static fn (array $row): bool => $directory->ownsAddress((string) ($row['user2'] ?? '')),
        ));
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
     * @return array<string, mixed>|null
     */
    private function autoreplyFilter(MailcowClient $client, string $username): ?array
    {
        foreach ($client->filters() as $filter) {
            if (strtolower((string) ($filter['username'] ?? '')) === $username
                && (string) ($filter['script_desc'] ?? '') === ComailAutoreply::DESCRIPTION) {
                return $filter;
            }
        }

        return null;
    }

    /**
     * @return array{enabled: bool, subject: string, body: string, starts_on: string|null, ends_on: string|null}
     */
    private function autoreplyState(MailcowClient $client, string $username): array
    {
        $filter = $this->autoreplyFilter($client, $username);
        if ($filter === null) {
            return ['enabled' => false, 'subject' => '', 'body' => '', 'starts_on' => null, 'ends_on' => null];
        }

        return ['enabled' => (int) ($filter['active'] ?? 0) === 1] + ComailAutoreply::read((string) ($filter['script_data'] ?? ''));
    }

    private function applyDnsQuietly(SiteComailDomain $row): void
    {
        try {
            $this->dns->applyIfManaged($row);
        } catch (Throwable $exception) {
            $row->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 500)])->save();
        }
    }

    /**
     * @return array{domain: string, is_primary: bool, status: string, mx_active: bool, dns_managed: bool, webmail_url: string|null}
     */
    private function domainRow(SiteComailDomain $row): array
    {
        return [
            'domain' => (string) $row->domain,
            'is_primary' => (bool) $row->is_primary,
            'status' => (string) $row->status,
            'mx_active' => $row->mxActive(),
            'dns_managed' => $this->dns->isManaged($row),
            'webmail_url' => $this->domainWebmailUrl($row),
        ];
    }

    /**
     * `https://mail.<domain>` once MX is on CoMail (mailcow serves that host with
     * its own certificate). Hidden only when the last DNS check explicitly saw
     * the mail.<domain> CNAME missing or pointing elsewhere (the link would break).
     */
    private function domainWebmailUrl(SiteComailDomain $row): ?string
    {
        if (! $row->mxActive()) {
            return null;
        }

        $records = is_array($row->dns_check['records'] ?? null) ? $row->dns_check['records'] : [];
        foreach ($records as $record) {
            if (is_array($record) && ($record['key'] ?? null) === 'cname_mail' && ($record['status'] ?? null) !== 'ok') {
                return null;
            }
        }

        return 'https://mail.'.$row->domain;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mailboxRow(array $row): array
    {
        $address = strtolower((string) ($row['username'] ?? ''));

        return [
            'address' => $address,
            'local_part' => (string) ($row['local_part'] ?? strstr($address, '@', true)),
            'domain' => (string) ($row['domain'] ?? ComailDirectory::domainOf($address)),
            'name' => (string) ($row['name'] ?? ''),
            'quota_mb' => ComailDirectory::bytesToMb($row['quota'] ?? 0),
            'used_bytes' => (int) ($row['quota_used'] ?? 0),
            'percent' => (int) ($row['percent_in_use'] ?? 0),
            'messages' => (int) ($row['messages'] ?? 0),
            'active' => (int) ($row['active_int'] ?? $row['active'] ?? 0) === 1,
            'created_at' => self::isoDate($row['created'] ?? null),
            'last_login_at' => self::isoDate($row['last_imap_login'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: int, address: string, goto: list<string>, active: bool, kind: string}
     */
    private function aliasRow(ComailDirectory $directory, array $row): array
    {
        $address = strtolower((string) ($row['address'] ?? ''));
        $goto = ComailDirectory::gotoList($row['goto'] ?? '');
        $kind = match (true) {
            str_starts_with($address, '@') => 'catchall',
            in_array($address, $goto, true), $directory->mailbox($address) !== null => 'forward',
            default => 'alias',
        };

        return [
            'id' => (int) ($row['id'] ?? 0),
            'address' => $address,
            'goto' => $goto,
            'active' => (int) ($row['active_int'] ?? $row['active'] ?? 1) === 1,
            'kind' => $kind,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function migrationRow(array $row): array
    {
        $success = $row['success'] ?? null;

        return [
            'id' => (int) ($row['id'] ?? 0),
            'mailbox' => strtolower((string) ($row['user2'] ?? '')),
            'host' => (string) ($row['host1'] ?? ''),
            'port' => (int) ($row['port1'] ?? 0),
            'user' => (string) ($row['user1'] ?? ''),
            'security' => (string) ($row['enc1'] ?? ''),
            'active' => (int) ($row['active_int'] ?? $row['active'] ?? 0) === 1,
            'running' => (int) ($row['is_running'] ?? 0) === 1,
            'last_run_at' => self::isoDate($row['last_run'] ?? null),
            'success' => $success === null || $success === '' ? null : (int) $success === 1,
            'exit_status' => isset($row['exit_status']) && $row['exit_status'] !== '' ? (string) $row['exit_status'] : null,
        ];
    }

    private static function isoDate(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }

        try {
            return is_numeric($value)
                ? Carbon::createFromTimestamp((int) $value)->toIso8601String()
                : Carbon::parse((string) $value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Success body. Objects are returned flat and also under `key`, so a client
     * may read either `json.address` or `json.mailbox.address`.
     *
     * @param  array<string, mixed>  $data
     */
    private function ok(array $data, int $status = 200, ?string $key = null): JsonResponse
    {
        $body = ['ok' => true] + $data;
        if ($key !== null) {
            $body[$key] = $data;
        }

        return response()->json($body, $status);
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
