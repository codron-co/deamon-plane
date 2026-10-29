<?php

namespace App\Services\Comail;

use App\Models\Site;
use App\Models\SiteComailDomain;
use Illuminate\Database\Eloquent\Collection;

/**
 * One site's view of the shared mailcow: its domains, mailboxes and aliases.
 * Every object a site may touch is checked against its CoMail domains, so a
 * site can never reach another site's mailbox, alias or sync job.
 *
 * With `$shared` (the CMS site API) the view also covers the active domains of
 * the other sites in the site's CoMail share group (`sites.comail_share_group`):
 * one company, several sites, every mailbox manageable from each of them.
 * Domain management, limits and billing stay with the site that owns the
 * domain: use forDomain() / own() for anything counted against a package.
 */
class ComailDirectory
{
    /** @var list<array<string, mixed>>|null */
    private ?array $mailboxes = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $aliases = null;

    /** @var array<string, string>|null domain => owning site id */
    private ?array $owners = null;

    /** @var array<string, self> owning site id => that site's own directory */
    private array $ownerDirectories = [];

    public function __construct(
        public readonly Site $site,
        public readonly MailcowClient $client,
        public readonly bool $shared = false,
    ) {}

    /**
     * Domains mailboxes may live on (provisioned on mailcow): the site's own,
     * then (shared view) those of the other sites in its share group.
     *
     * @return list<string>
     */
    public function domains(): array
    {
        return array_keys($this->owners());
    }

    /**
     * The site's own active CoMail domains, whatever the view.
     *
     * @return list<string>
     */
    public function ownDomains(): array
    {
        return array_keys(array_filter(
            $this->owners(),
            fn (string $siteId): bool => $siteId === (string) $this->site->id,
        ));
    }

    public function ownsDomain(string $domain): bool
    {
        return array_key_exists(strtolower(trim($domain)), $this->owners());
    }

    /**
     * A domain of another site in the share group (reachable, not managed here).
     */
    public function isSharedDomain(string $domain): bool
    {
        $owner = $this->owners()[strtolower(trim($domain))] ?? null;

        return $owner !== null && $owner !== (string) $this->site->id;
    }

    /**
     * Id of the site that owns this (reachable) domain, else null.
     */
    public function ownerSiteId(string $domain): ?string
    {
        return $this->owners()[strtolower(trim($domain))] ?? null;
    }

    /**
     * The owning site's own (unshared) directory for a reachable domain: the
     * one its package limits and usage are counted on.
     */
    public function forDomain(string $domain): self
    {
        $owner = $this->ownerSiteId($domain);
        if ($owner === null) {
            throw ComailException::domainNotOwned();
        }

        if ($owner === (string) $this->site->id) {
            return $this->own();
        }

        if (! isset($this->ownerDirectories[$owner])) {
            $site = Site::query()->find($owner);
            if (! $site instanceof Site) {
                throw ComailException::domainNotOwned();
            }
            $this->ownerDirectories[$owner] = new self($site, $this->client);
        }

        return $this->ownerDirectories[$owner];
    }

    /**
     * This site's own directory (its domains only): usage, overview, billing.
     */
    public function own(): self
    {
        if (! $this->shared) {
            return $this;
        }

        return $this->ownerDirectories[(string) $this->site->id] ??= new self($this->site, $this->client);
    }

    /**
     * Other (not deleted) sites in the site's share group, by name.
     *
     * @return Collection<int, Site>
     */
    public static function groupSiblings(Site $site): Collection
    {
        $group = $site->comail_share_group;
        if ($group === null || $group === '') {
            return new Collection;
        }

        return Site::query()
            ->where('comail_share_group', $group)
            ->whereKeyNot($site->getKey())
            ->orderBy('name')
            ->get();
    }

    /**
     * Owner of a domain that belongs to another site of the share group, else null.
     */
    public static function sharedOwnerId(Site $site, string $domain): ?string
    {
        $siblings = self::groupSiblings($site);
        if ($siblings->isEmpty() || $domain === '') {
            return null;
        }

        $owner = SiteComailDomain::query()
            ->whereIn('site_id', $siblings->modelKeys())
            ->where('domain', strtolower(trim($domain)))
            ->value('site_id');

        return $owner === null ? null : (string) $owner;
    }

    /**
     * @return array<string, string> domain => owning site id, own domains first
     */
    private function owners(): array
    {
        if ($this->owners !== null) {
            return $this->owners;
        }

        $owners = [];
        foreach ($this->site->comailDomains()->where('status', SiteComailDomain::STATUS_ACTIVE)->pluck('domain') as $domain) {
            $owners[strtolower((string) $domain)] = (string) $this->site->id;
        }

        if ($this->shared) {
            $siblings = self::groupSiblings($this->site);
            if ($siblings->isNotEmpty()) {
                $rows = SiteComailDomain::query()
                    ->whereIn('site_id', $siblings->modelKeys())
                    ->where('status', SiteComailDomain::STATUS_ACTIVE)
                    ->orderBy('domain')
                    ->get(['domain', 'site_id']);
                foreach ($rows as $row) {
                    $owners[strtolower((string) $row->domain)] ??= (string) $row->site_id;
                }
            }
        }

        return $this->owners = $owners;
    }

    public static function domainOf(string $address): string
    {
        $at = strrpos($address, '@');

        return $at === false ? '' : strtolower(substr($address, $at + 1));
    }

    public function ownsAddress(string $address): bool
    {
        $domain = self::domainOf($address);

        return $domain !== '' && $this->ownsDomain($domain);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function mailboxes(): array
    {
        if ($this->mailboxes !== null) {
            return $this->mailboxes;
        }

        $domains = $this->domains();
        $rows = [];
        foreach ($domains as $domain) {
            foreach ($this->client->mailboxes($domain) as $row) {
                $username = strtolower((string) ($row['username'] ?? ''));
                // mailcow answers per domain; keep only rows that really are on it.
                if ($username !== '' && in_array(self::domainOf($username), $domains, true)) {
                    $rows[] = $row;
                }
            }
        }

        return $this->mailboxes = $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function mailbox(string $address): ?array
    {
        $address = strtolower(trim($address));
        if (! $this->ownsAddress($address)) {
            return null;
        }

        foreach ($this->mailboxes() as $row) {
            if (strtolower((string) ($row['username'] ?? '')) === $address) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The site's aliases, forwarders and catch-alls. A mailbox's own internal
     * alias row (address = goto) is not one of them.
     *
     * @return list<array<string, mixed>>
     */
    public function aliases(): array
    {
        if ($this->aliases !== null) {
            return $this->aliases;
        }

        $domains = $this->domains();
        $rows = [];
        foreach ($this->client->aliases() as $row) {
            $address = strtolower(trim((string) ($row['address'] ?? '')));
            $domain = strtolower((string) ($row['domain'] ?? self::domainOf($address)));
            if ($address === '' || ! in_array($domain, $domains, true) || self::domainOf($address) !== $domain) {
                continue;
            }

            if (self::gotoList($row['goto'] ?? '') === [$address]) {
                continue;
            }

            $rows[] = $row;
        }

        return $this->aliases = $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function alias(int $id): ?array
    {
        foreach ($this->aliases() as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                return $row;
            }
        }

        return null;
    }

    public function forget(): void
    {
        $this->mailboxes = null;
        $this->aliases = null;
        foreach ($this->ownerDirectories as $directory) {
            $directory->forget();
        }
    }

    /**
     * @return list<string>
     */
    public static function gotoList(mixed $goto): array
    {
        if (is_array($goto)) {
            $goto = implode(',', array_map(static fn (mixed $value): string => (string) $value, $goto));
        }

        $out = [];
        foreach (explode(',', (string) $goto) as $target) {
            $target = strtolower(trim($target));
            if ($target !== '' && ! in_array($target, $out, true)) {
                $out[] = $target;
            }
        }

        return $out;
    }

    public static function bytesToMb(mixed $bytes): int
    {
        return (int) round(((int) $bytes) / 1048576);
    }

    /**
     * mailcow's `force_pw_update`: the webmail makes the user pick a new
     * password on the next login (IMAP/SMTP apps keep working).
     *
     * @param  array<string, mixed>  $row
     */
    public static function mustChangePassword(array $row): bool
    {
        $attributes = is_array($row['attributes'] ?? null) ? $row['attributes'] : [];

        return (string) ($attributes['force_pw_update'] ?? '0') === '1';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function isActive(array $row): bool
    {
        return (int) ($row['active_int'] ?? $row['active'] ?? 0) === 1;
    }
}
