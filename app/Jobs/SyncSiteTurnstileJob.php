<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Turnstile\SiteTurnstileAgent;
use App\Services\Turnstile\SiteTurnstileSync;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Bir sitenin Turnstile widget atamasını alan adlarıyla eşitler ve CMS'e iter.
 * Provision sonrası CMS birkaç dakika açılmamış olabilir; yeniden dener, kalanı
 * saatlik ReconcileSiteTurnstileJob toplar.
 */
class SyncSiteTurnstileJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 120;

    public int $uniqueFor = 120;

    public function __construct(public readonly string $siteId) {}

    public function uniqueId(): string
    {
        return $this->siteId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 180, 600];
    }

    public function handle(SiteTurnstileSync $sync, SiteTurnstileAgent $agent): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null || ! $site->turnstile()->exists()) {
            return;
        }

        // Cloudflare hatası gönderimi durdurmaz: mod/form değişikliği eski widget'larla da gider.
        $sync->syncWidgets($site);

        if (! $site->hasAgentSecret()) {
            return;
        }

        if (! $agent->push($site, retryConnection: true)) {
            $error = (string) $site->turnstile()->value('push_error');

            // Aynı gövdeyi her seferinde reddedecek durumlar: yeniden denemek failed_jobs'u doldurur.
            if (str_starts_with($error, 'validation_failed') || in_array($error, ['cms_too_old', 'secret_echoed', 'no_base_url'], true)) {
                return;
            }

            throw new RuntimeException('Turnstile push failed for site '.$site->id.'.');
        }
    }
}
