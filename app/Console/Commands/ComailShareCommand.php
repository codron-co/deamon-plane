<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SiteComailDomain;
use App\Services\Comail\ComailException;
use App\Services\Comail\ComailShareGroup;
use Illuminate\Console\Command;

/**
 * Shared CoMail group: sites of one company that see and manage each other's
 * mailboxes (docs/modules/comail.md, "Ortak posta grubu").
 *
 *   ops:comail-share makermach makermak   put the sites in one group
 *   ops:comail-share --clear makermak     take a site out (a group of one is cleared)
 *   ops:comail-share                      list the groups
 */
class ComailShareCommand extends Command
{
    protected $signature = 'ops:comail-share
        {sites?* : two or more sites (slug, id or primary domain); with --clear the sites to take out}
        {--clear : take the given sites out of their group}';

    protected $description = 'Set, clear or list shared CoMail groups (sites that manage each other\'s mailboxes)';

    public function handle(ComailShareGroup $groups): int
    {
        $needles = array_values(array_filter(array_map('strval', (array) $this->argument('sites')), static fn (string $value): bool => trim($value) !== ''));
        if ($needles === []) {
            if ($this->option('clear')) {
                $this->error('Which site? ops:comail-share --clear <site>');

                return self::INVALID;
            }

            return $this->list();
        }

        $sites = [];
        foreach ($needles as $needle) {
            $site = $this->resolveSite($needle);
            if ($site === null) {
                $this->error('Site not found: '.$needle);

                return self::FAILURE;
            }
            $sites[] = $site;
        }

        try {
            if ($this->option('clear')) {
                foreach ($sites as $site) {
                    $group = $site->comail_share_group;
                    $groups->leave($site);
                    $this->line($group === null ? "{$site->slug}: not in a group" : "{$site->slug}: left group {$group}");
                }

                return self::SUCCESS;
            }

            $group = $groups->join($sites);
        } catch (ComailException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line('GROUP='.$group);
        $this->printMembers($group);

        return self::SUCCESS;
    }

    private function list(): int
    {
        $names = Site::query()->whereNotNull('comail_share_group')->distinct()->orderBy('comail_share_group')->pluck('comail_share_group');
        if ($names->isEmpty()) {
            $this->line('No shared CoMail groups.');

            return self::SUCCESS;
        }

        foreach ($names as $group) {
            $this->line('GROUP='.$group);
            $this->printMembers((string) $group);
        }

        return self::SUCCESS;
    }

    private function printMembers(string $group): void
    {
        foreach (ComailShareGroup::members($group) as $member) {
            $domains = $member->comailDomains()->where('status', SiteComailDomain::STATUS_ACTIVE)->pluck('domain')->implode(', ');
            $this->line("  {$member->slug}: ".($domains === '' ? '(no active CoMail domain)' : $domains));
        }
    }

    private function resolveSite(string $needle): ?Site
    {
        $needle = trim($needle);

        return Site::query()
            ->where('slug', $needle)
            ->orWhere('id', $needle)
            ->orWhere('primary_domain', strtolower($needle))
            ->first();
    }
}
