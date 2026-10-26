<?php

namespace App\Services\Comail;

use App\Models\Site;
use App\Models\SiteComailDomain;

/**
 * One site's view of the shared mailcow: its domains, mailboxes and aliases.
 * Every object a site may touch is checked against its own CoMail domains, so
 * a site can never reach another site's mailbox, alias or sync job.
 */
class ComailDirectory
{
    /** @var list<array<string, mixed>>|null */
    private ?array $mailboxes = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $aliases = null;

    public function __construct(
        public readonly Site $site,
        public readonly MailcowClient $client,
    ) {}

    /**
     * Domains mailboxes may live on (provisioned on mailcow).
     *
     * @return list<string>
     */
    public function domains(): array
    {
        return $this->site->comailDomains()
            ->where('status', SiteComailDomain::STATUS_ACTIVE)
            ->pluck('domain')
            ->map(fn (mixed $domain): string => strtolower((string) $domain))
            ->values()
            ->all();
    }

    public function ownsDomain(string $domain): bool
    {
        return in_array(strtolower(trim($domain)), $this->domains(), true);
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
}
