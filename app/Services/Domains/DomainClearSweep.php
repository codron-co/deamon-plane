<?php

namespace App\Services\Domains;

use App\Models\SiteDomain;
use Illuminate\Support\Collection;

/**
 * Removes leftover unbound hosts from the Plane registry. Never talks to
 * Coolify — these rows are not on the proxy. Primary, temporary, and
 * already-bound hosts are skipped, not errors.
 */
class DomainClearSweep
{
    /**
     * @param  Collection<int, SiteDomain>  $domains
     * @return array{ok: int, skipped: int}
     */
    public function run(Collection $domains): array
    {
        $ok = 0;
        $skipped = 0;

        foreach ($domains as $row) {
            if (! $row instanceof SiteDomain || ! $row->isLeftoverClearable()) {
                $skipped++;

                continue;
            }

            $row->delete();
            $ok++;
        }

        return [
            'ok' => $ok,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  array{ok?: int, skipped?: int}  $result
     */
    public function summarize(array $result): string
    {
        $ok = (int) ($result['ok'] ?? 0);
        $skipped = (int) ($result['skipped'] ?? 0);

        if ($skipped > 0) {
            return __('domains.flash.cleared_skipped', [
                'count' => $ok,
                'skipped' => $skipped,
            ]);
        }

        return __('domains.flash.cleared', ['count' => $ok]);
    }
}
