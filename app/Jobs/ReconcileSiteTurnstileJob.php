<?php

namespace App\Jobs;

use App\Enums\OpsLane;
use App\Enums\SiteStatus;
use App\Jobs\Concerns\OnOpsLane;
use App\Models\Site;
use App\Models\SiteTurnstile;
use App\Services\Turnstile\SiteTurnstileAgent;
use App\Services\Turnstile\SiteTurnstileSync;
use App\Services\Turnstile\TurnstileHostnames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Saatlik: alan adları değişen ya da widget'ı olmayan siteleri Cloudflare ile
 * eşitler, CMS'in kabul etmediği (kapalı, eski sürüm) ya da health'te farklı
 * anahtar bildiren siteleri yeniden iter.
 */
class ReconcileSiteTurnstileJob implements ShouldQueue
{
    use OnOpsLane;
    use Queueable;

    public int $timeout = 900;

    public function __construct()
    {
        $this->onLane(OpsLane::Long);
    }

    public function handle(SiteTurnstileSync $sync, SiteTurnstileAgent $agent, TurnstileHostnames $hostnames): void
    {
        SiteTurnstile::query()
            ->with('site')
            ->orderBy('id')
            ->chunk(50, function ($records) use ($sync, $agent, $hostnames): void {
                foreach ($records as $record) {
                    $site = $record->site;
                    if (! $site instanceof Site || $site->status !== SiteStatus::Active || ! $site->hasAgentSecret()) {
                        continue;
                    }

                    try {
                        $this->reconcile($site, $record, $sync, $agent, $hostnames);
                    } catch (Throwable) {
                        // One site's Cloudflare or agent error must not stop the pass.
                    }
                }
            });
    }

    private function reconcile(Site $site, SiteTurnstile $record, SiteTurnstileSync $sync, SiteTurnstileAgent $agent, TurnstileHostnames $hostnames): void
    {
        $account = $sync->accountFor($site);
        $drifted = $record->widgetIdMap() === []
            || ($account !== null && $hostnames->forSite($site, $account) !== $record->hostnameList());

        if ($drifted && $sync->syncWidgets($site)) {
            $record->refresh();
        }

        if ($site->last_health_at !== null && ! $site->supportsTurnstile()) {
            return;
        }

        $state = $record->pushState();
        if (in_array($state, ['pending', 'failed', 'never'], true) || $this->siteReportsOtherKeys($site, $record)) {
            $agent->push($site);
        }
    }

    /**
     * CMS health'te farklı site anahtarı bildiriyorsa (yedekten dönüş, elle silme) yeniden it.
     */
    private function siteReportsOtherKeys(Site $site, SiteTurnstile $record): bool
    {
        $reported = $site->reportedTurnstile();
        if ($reported === null || ! $record->enabled) {
            return false;
        }

        $expected = [];
        foreach ($record->widgets() as $mode => $widget) {
            $expected[$mode] = (string) $widget->sitekey;
        }
        ksort($expected);

        $actual = $reported['sitekeys'];
        ksort($actual);

        return $expected !== [] && $expected !== $actual;
    }
}
