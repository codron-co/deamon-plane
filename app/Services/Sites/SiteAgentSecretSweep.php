<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\User;
use App\Services\Ops\BulkResultSummary;
use App\Services\Ops\PacedFanout;
use Illuminate\Support\Collection;

/**
 * Generates and writes CONTROL_PLANE_AGENT_SECRET only for sites that have none.
 * A site that already has a secret is kept — bulk inject never rotates.
 */
class SiteAgentSecretSweep
{
    public function __construct(
        private readonly SiteAgentSecretInjector $injector,
        private readonly PacedFanout $fanout,
        private readonly SiteAppHealthFixer $appHealth,
    ) {}

    /**
     * @param  Collection<int, Site>  $sites
     * @param  null|callable(Site, int, int): void  $onProgress
     * @return array{ok: int, failed: int, skipped: int, waiting: int, kept: int, unready: int, errors: list<string>, waiting_sites: list<string>, rate_limited: bool, deploy_busy: bool}
     */
    public function run(Collection $sites, ?User $actor = null, ?string $ip = null, ?callable $onProgress = null): array
    {
        $kept = $sites->filter(static fn (Site $site): bool => $site->hasAgentSecret());
        $unready = $sites->filter(static fn (Site $site): bool => ! $site->hasAgentSecret() && blank($site->coolify_app_uuid));
        $todo = $sites
            ->filter(static fn (Site $site): bool => ! $site->hasAgentSecret() && filled($site->coolify_app_uuid))
            ->values();

        $fanout = $todo->isEmpty()
            ? ['ok' => 0, 'failed' => 0, 'skipped' => 0, 'waiting' => 0, 'errors' => [], 'waiting_sites' => [], 'rate_limited' => false, 'deploy_busy' => false]
            : $this->fanout->run(
                $todo,
                function (Site $site) use ($actor, $ip): void {
                    $this->injector->inject($site, $actor, $ip, rotate: false);
                },
                $onProgress,
            );

        $this->appHealth->forgetCategoryCounts();

        $fanout['kept'] = $kept->count();
        $fanout['unready'] = $unready->count();

        return $fanout;
    }

    /**
     * @param  array{ok?: int, failed?: int, skipped?: int, waiting?: int, kept?: int, unready?: int, errors?: list<string>}  $result
     */
    public function summarize(array $result): string
    {
        $text = BulkResultSummary::format(__('sites.agent.bulk'), $result);
        if ((int) ($result['unready'] ?? 0) > 0) {
            $text .= ' '.__('sites.agent.bulk_unready', ['count' => $result['unready']]);
        }
        if ((int) ($result['kept'] ?? 0) > 0) {
            $text .= ' '.__('sites.agent.bulk_kept', ['count' => $result['kept']]);
        }

        return $text;
    }
}
