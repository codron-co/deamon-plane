<?php

namespace App\Services\Comail;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Shared CoMail group (`sites.comail_share_group`): sites of one company that
 * see and manage each other's mailboxes (ComailDirectory with `shared`).
 * Domains, limits and billing stay with the site that owns each domain.
 * Every change is audited on each site it touches.
 */
class ComailShareGroup
{
    public function __construct(private readonly ComailSiteConfigurer $configurer) {}

    /**
     * Put the sites in one group: the group one of them already has, else a new
     * one. Sites already in two different groups are refused (clear one first).
     *
     * @param  list<Site>  $sites
     */
    public function join(array $sites, ?User $actor = null, string $source = 'console', ?string $ip = null): string
    {
        $ids = array_values(array_unique(array_map(static fn (Site $site): string => (string) $site->id, $sites)));
        if (count($ids) < 2) {
            throw ComailException::validation('Ortak posta grubu için en az iki farklı site gerekir.');
        }

        $groups = array_values(array_unique(array_filter(
            array_map(static fn (Site $site): ?string => $site->comail_share_group, $sites),
            static fn (?string $group): bool => $group !== null && $group !== '',
        )));
        if (count($groups) > 1) {
            throw ComailException::validation('Siteler farklı ortak posta gruplarında; önce birini gruptan çıkarın.');
        }

        $group = $groups[0] ?? strtolower((string) Str::ulid());
        foreach ($sites as $site) {
            $this->assign($site, $group, $actor, $source, $ip);
        }

        return $group;
    }

    /**
     * Take the site out of its group. A group left with one site shares
     * nothing, so that last site is cleared too.
     */
    public function leave(Site $site, ?User $actor = null, string $source = 'console', ?string $ip = null): void
    {
        $group = $site->comail_share_group;
        if ($group === null || $group === '') {
            return;
        }

        $this->assign($site, null, $actor, $source, $ip);

        $rest = self::members($group);
        if ($rest->count() === 1) {
            $this->assign($rest->first(), null, $actor, $source, $ip);
        }
    }

    /**
     * @return Collection<int, Site>
     */
    public static function members(string $group): Collection
    {
        return Site::query()->where('comail_share_group', $group)->orderBy('name')->get();
    }

    private function assign(Site $site, ?string $group, ?User $actor, string $source, ?string $ip): void
    {
        $before = $site->comail_share_group;
        if ($before === $group) {
            return;
        }

        $site->forceFill(['comail_share_group' => $group])->save();

        $site->auditLogs()->create([
            'actor_user_id' => $actor?->id,
            'action' => 'comail.share_group_changed',
            'after' => [
                'from' => $before,
                'to' => $group,
                'members' => $group === null ? [] : self::members($group)->pluck('slug')->all(),
                'source' => $source,
            ],
            'ip' => $ip,
        ]);

        // The CMS module switch depends on the reachable domains (ComailSiteConfigurer).
        if ($site->hasAgentSecret()) {
            $this->configurer->queue($site);
        }
    }
}
