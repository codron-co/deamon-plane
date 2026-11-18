<?php

namespace App\Services\Security;

use App\Models\AuditLog;
use App\Models\FleetAllowlistEntry;
use App\Models\FleetIpBlock;
use App\Models\OpsBackgroundJob;
use App\Models\User;
use App\Services\Ops\OpsJobManager;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Fleet response: block or release an IP on every site, manage the fleet
 * allowlist. This is the one path the panel and the MCP tools share. Each call
 * writes the intent (idempotent per address), an audit row, and queues one
 * background job that pushes it site by site (canary first, FleetSecurityApplier).
 */
class FleetSecurityResponse
{
    public const JOB_TYPE = 'security.fleet_apply';

    public function __construct(private readonly OpsJobManager $jobs) {}

    /**
     * @return array{block: FleetIpBlock, job: OpsBackgroundJob|null, reused: bool}
     */
    public function block(string $ip, ?string $reason, ?CarbonInterface $expiresAt, User $actor, ?string $actorIp = null): array
    {
        $ip = IpAddressRange::normalizeForBlock($ip);

        foreach (FleetAllowlistEntry::query()->active()->get() as $entry) {
            if (IpAddressRange::overlaps($entry->ip, $ip)) {
                throw new InvalidArgumentException("{$ip} overlaps the fleet allowlist entry {$entry->ip}; remove that entry first.");
            }
        }

        $existing = FleetIpBlock::query()->active()->where('ip', $ip)->first();
        if ($existing !== null) {
            // Same address twice: a push again for the sites that missed it, no second record.
            return ['block' => $existing, 'job' => $this->queue($existing, $actor, $actorIp), 'reused' => true];
        }

        $block = FleetIpBlock::query()->create([
            'uid' => (string) Str::uuid(),
            'ip' => $ip,
            'reason' => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : null,
            'expires_at' => $expiresAt,
            'created_by' => $actor->id,
            'status' => FleetIpBlock::STATUS_PENDING,
            'applied' => [],
        ]);

        $this->audit($actor, 'security.fleet_block', $block, ['ip' => $ip, 'reason' => $block->reason, 'expires_at' => $expiresAt?->toIso8601String()], $actorIp);

        return ['block' => $block, 'job' => $this->queue($block, $actor, $actorIp), 'reused' => false];
    }

    /**
     * @return array{block: FleetIpBlock|null, job: OpsBackgroundJob|null}
     */
    public function unblock(string $ip, User $actor, ?string $actorIp = null): array
    {
        $ip = IpAddressRange::normalize($ip);
        $block = FleetIpBlock::query()->whereNull('released_at')->where('ip', $ip)->latest('id')->first();
        if ($block === null) {
            return ['block' => null, 'job' => null];
        }

        // Released blocks are pushed as unblock to every site, including ones that never took the block.
        $block->forceFill([
            'released_at' => now(),
            'released_by' => $actor->id,
            'status' => FleetIpBlock::STATUS_PENDING,
            'applied' => [],
        ])->save();

        $this->audit($actor, 'security.fleet_unblock', $block, ['ip' => $ip], $actorIp);

        return ['block' => $block, 'job' => $this->queue($block, $actor, $actorIp)];
    }

    /**
     * @return array{entry: FleetAllowlistEntry, job: OpsBackgroundJob|null, reused: bool}
     */
    public function allow(string $ip, ?string $label, User $actor, ?string $actorIp = null): array
    {
        $ip = IpAddressRange::normalize($ip);

        $existing = FleetAllowlistEntry::query()->active()->where('ip', $ip)->first();
        if ($existing !== null) {
            return ['entry' => $existing, 'job' => $this->queue($existing, $actor, $actorIp), 'reused' => true];
        }

        $entry = FleetAllowlistEntry::query()->create([
            'uid' => (string) Str::uuid(),
            'ip' => $ip,
            'label' => $label !== null && trim($label) !== '' ? mb_substr(trim($label), 0, 120) : null,
            'source' => 'manual',
            'created_by' => $actor->id,
            'status' => FleetIpBlock::STATUS_PENDING,
            'applied' => [],
        ]);

        $this->audit($actor, 'security.fleet_allowlist_add', $entry, ['ip' => $ip, 'label' => $entry->label], $actorIp);

        return ['entry' => $entry, 'job' => $this->queue($entry, $actor, $actorIp), 'reused' => false];
    }

    /**
     * @return array{entry: FleetAllowlistEntry|null, job: OpsBackgroundJob|null}
     */
    public function removeAllowed(string $ip, User $actor, ?string $actorIp = null): array
    {
        $ip = IpAddressRange::normalize($ip);
        $entry = FleetAllowlistEntry::query()->active()->where('ip', $ip)->first();
        if ($entry === null) {
            return ['entry' => null, 'job' => null];
        }

        $entry->forceFill(['removed_at' => now(), 'status' => FleetIpBlock::STATUS_PENDING, 'applied' => []])->save();
        $this->audit($actor, 'security.fleet_allowlist_remove', $entry, ['ip' => $ip], $actorIp);

        return ['entry' => $entry, 'job' => $this->queue($entry, $actor, $actorIp)];
    }

    private function queue(FleetIpBlock|FleetAllowlistEntry $record, User $actor, ?string $actorIp): OpsBackgroundJob
    {
        $kind = $record instanceof FleetIpBlock ? 'block' : 'allowlist';

        return $this->jobs->queue(
            self::JOB_TYPE,
            __('security.job_title.'.$kind, ['ip' => $record->ip]),
            $actor,
            ['record' => $kind, 'record_id' => $record->id, 'subject' => $record->ip, 'ip' => $actorIp],
        );
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(User $actor, string $action, Model $subject, array $after, ?string $actorIp): void
    {
        AuditLog::query()->create([
            'actor_user_id' => $actor->id,
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => (string) $subject->getKey(),
            'after' => $after,
            'ip' => $actorIp,
        ]);
    }
}
