<?php

namespace App\Services\Security;

use App\Enums\SiteStatus;
use App\Models\FleetAllowlistEntry;
use App\Models\FleetIpBlock;
use App\Models\Site;
use App\Models\SiteSecurityCursor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Sleep;

/**
 * Pushes one fleet block / allowlist record to the sites, one site at a time:
 * the canary (`test`) first, and the rest only if the canary took it. A site that
 * already answered ok is not called again, so "push again" finishes the sites that
 * missed it (the CMS routes are idempotent on the record's uid anyway).
 *
 * The direction follows the record: a released block is an unblock, a removed
 * allowlist entry is a remove.
 */
class FleetSecurityApplier
{
    public const CANARY_SLUG = 'test';

    public function __construct(private readonly SiteSecurityClient $client) {}

    /**
     * @param  null|callable(string, int, int): void  $onProgress  (site name, done, total)
     * @return array{status: string, ok: int, failed: int, skipped: int, stopped_at_canary: bool, errors: list<string>}
     */
    public function apply(FleetIpBlock|FleetAllowlistEntry $record, ?callable $onProgress = null): array
    {
        $sites = $this->sites();
        $applied = is_array($record->applied) ? $record->applied : [];
        $total = max(1, count($sites));
        $done = 0;
        $result = ['status' => FleetIpBlock::STATUS_PENDING, 'ok' => 0, 'failed' => 0, 'skipped' => 0, 'stopped_at_canary' => false, 'errors' => []];
        $spacingMs = max(0, (int) config('ops.security.apply_spacing_ms', 250));

        foreach ($sites as $site) {
            $isCanary = $site->slug === self::CANARY_SLUG;
            $previous = $applied[$site->slug]['state'] ?? null;

            if ($previous === 'ok') {
                $result['ok']++;
            } elseif ($this->knownUnsupported($site)) {
                $applied[$site->slug] = $this->row('unsupported', 'Site has no security endpoint.');
                $result['skipped']++;
            } else {
                $call = $this->push($site, $record);
                if ($call->isOk()) {
                    $applied[$site->slug] = $this->row('ok');
                    $result['ok']++;
                } else {
                    $applied[$site->slug] = $this->row($call->isUnsupported() ? 'unsupported' : 'failed', $call->message);
                    $call->isUnsupported() ? $result['skipped']++ : $result['failed']++;
                    if (! $call->isUnsupported()) {
                        $result['errors'][] = $site->slug.': '.$call->message;
                    }
                }

                if ($spacingMs > 0) {
                    Sleep::for($spacingMs)->milliseconds();
                }
            }

            $done++;
            $onProgress !== null && $onProgress($site->name, $done, $total);
            $record->forceFill(['applied' => $applied])->save();

            // The canary decides: when it did not take the change, nobody else is touched.
            if ($isCanary) {
                if (($applied[$site->slug]['state'] ?? '') !== 'ok') {
                    $result['stopped_at_canary'] = true;
                    break;
                }
            }
        }

        $result['status'] = match (true) {
            $result['stopped_at_canary'] => FleetIpBlock::STATUS_FAILED,
            $result['failed'] === 0 => FleetIpBlock::STATUS_APPLIED,
            $result['ok'] > 0 => FleetIpBlock::STATUS_PARTIAL,
            default => FleetIpBlock::STATUS_FAILED,
        };

        $record->forceFill(['status' => $result['status'], 'applied' => $applied])->save();

        return $result;
    }

    /**
     * Sites that can take a change: active with an agent secret, the canary first.
     *
     * @return list<Site>
     */
    private function sites(): array
    {
        return Site::query()
            ->where('status', SiteStatus::Active->value)
            ->whereNotNull('agent_secret_encrypted')
            ->orderBy('name')
            ->get()
            ->sortBy(fn (Site $site): string => ($site->slug === self::CANARY_SLUG ? '0' : '1').$site->name)
            ->values()
            ->all();
    }

    private function knownUnsupported(Site $site): bool
    {
        $cursor = SiteSecurityCursor::query()->where('site_id', $site->id)->first();

        return $cursor !== null && $cursor->supported === false
            && $cursor->last_polled_at !== null && $cursor->last_polled_at->gt(now()->subDay());
    }

    private function push(Site $site, Model $record): SecurityCallResult
    {
        if ($record instanceof FleetIpBlock) {
            return $record->released_at !== null
                ? $this->client->unblock($site, ['uid' => $record->uid, 'ip' => $record->ip])
                : $this->client->block($site, [
                    'uid' => $record->uid,
                    'ip' => $record->ip,
                    'reason' => $record->reason,
                    'expires_at' => $record->expires_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                    'source' => 'plane',
                ]);
        }

        /** @var FleetAllowlistEntry $record */
        return $this->client->allowlist($site, [
            'uid' => $record->uid,
            'action' => $record->removed_at !== null ? 'remove' : 'add',
            'ip' => $record->ip,
            'label' => $record->label,
        ]);
    }

    /**
     * @return array{state: string, at: string, error?: string}
     */
    private function row(string $state, ?string $error = null): array
    {
        return array_filter(['state' => $state, 'at' => now()->toIso8601String(), 'error' => $error], fn ($v) => $v !== null);
    }
}
