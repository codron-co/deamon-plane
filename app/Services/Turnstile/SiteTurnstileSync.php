<?php

namespace App\Services\Turnstile;

use App\Jobs\SyncSiteTurnstileJob;
use App\Models\CloudflareSetting;
use App\Models\Site;
use App\Models\SiteTurnstile;
use App\Services\Cloudflare\CloudflareAccounts;
use App\Services\Cloudflare\CloudflareApiException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sitenin Turnstile widget atamasını alan adlarıyla eşitler (Cloudflare) ve
 * sonucu CMS'e iter. Widget hesabı sitenin Cloudflare hesabıdır, yoksa
 * varsayılan hesap (SiteLanding ile aynı kural).
 */
final class SiteTurnstileSync
{
    public function __construct(
        private readonly TurnstileHostnames $hostnames,
        private readonly TurnstileWidgetPool $pool,
    ) {}

    /**
     * Yeni site için kayıt açar (varsayılan: açık, gerekirse görünür, bütün formlar).
     */
    public function enroll(Site $site, ?int $actorId = null): SiteTurnstile
    {
        $record = $site->turnstile()->first();
        if ($record instanceof SiteTurnstile) {
            return $record;
        }

        return $site->turnstile()->create([
            'enabled' => true,
            'mode' => SiteTurnstile::MODE_INTERACTION_ONLY,
            'forms' => SiteTurnstile::FORMS,
            'changed_at' => now(),
            'updated_by_user_id' => $actorId,
        ]);
    }

    /**
     * Alan adı değişince ya da provision sonrası: kayıtlı siteyi kuyrukta eşitler.
     */
    public function queue(Site $site, int $delaySeconds = 30): void
    {
        if (! $site->turnstile()->exists()) {
            return;
        }

        SyncSiteTurnstileJob::dispatch((string) $site->id)->delay(now()->addSeconds($delaySeconds));
    }

    /**
     * Widget'ları ayarlar; değişiklik varsa `changed_at` ilerler. Hata kodu
     * `sync_error`'a yazılır (gizli bilgi yok). Başarıda true.
     */
    public function syncWidgets(Site $site): bool
    {
        $record = $site->turnstile()->first();
        if (! $record instanceof SiteTurnstile) {
            return false;
        }

        $account = $this->accountFor($site);
        if (! $account instanceof CloudflareSetting) {
            $this->recordSync($record, 'no_cloudflare_account');

            return false;
        }

        $hosts = $this->hostnames->forSite($site, $account);

        try {
            $widgetIds = $this->pool->assign($record, $account, $hosts);
        } catch (TurnstilePoolException $exception) {
            $this->recordSync($record, $exception->getMessage());

            return false;
        } catch (CloudflareApiException $exception) {
            $this->recordSync($record, $exception->isForbidden() ? 'cloudflare_forbidden' : 'cloudflare: '.$exception->getMessage());

            return false;
        } catch (Throwable $exception) {
            Log::warning('Turnstile widget sync failed', ['site_id' => $site->id, 'error' => $exception::class]);
            $this->recordSync($record, 'sync_failed');

            return false;
        }

        $changed = $record->widgetIdMap() !== $widgetIds
            || $record->hostnameList() !== $hosts
            || (int) $record->cloudflare_setting_id !== (int) $account->id;

        $record->forceFill([
            'cloudflare_setting_id' => $account->id,
            'hostnames' => $hosts,
            'widget_ids' => $widgetIds,
            'synced_at' => now(),
            'sync_error' => null,
        ]);
        if ($changed) {
            $record->changed_at = now();
        }
        $record->save();

        return true;
    }

    /**
     * Widget'ları bırakır (site silinmeden önce ya da koruma tamamen kaldırılırken).
     */
    public function releaseWidgets(SiteTurnstile $record): void
    {
        try {
            $this->pool->release($record);
        } catch (Throwable $exception) {
            Log::warning('Turnstile widget release failed', ['site_id' => $record->site_id, 'error' => $exception::class]);
        }
    }

    public function accountFor(Site $site): ?CloudflareSetting
    {
        $account = $site->cloudflareAccount;
        if ($account instanceof CloudflareSetting && $account->is_enabled && $account->hasCredentials()) {
            return $account;
        }

        $default = CloudflareAccounts::default();

        return $default instanceof CloudflareSetting && $default->hasCredentials() ? $default : null;
    }

    private function recordSync(SiteTurnstile $record, string $reason): void
    {
        SiteTurnstile::query()->whereKey($record->getKey())->toBase()->update([
            'sync_error' => Str::limit($reason, 250, '…'),
        ]);

        Log::warning('Turnstile widget sync failed', ['site_id' => $record->site_id, 'reason' => $reason]);
    }
}
