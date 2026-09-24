<?php

namespace App\Services\Rollouts;

use App\Enums\Channel;
use App\Enums\DeployGate;
use App\Enums\DeploymentStatus;
use App\Enums\FleetRolloutStatus;
use App\Enums\SiteStatus;
use App\Jobs\AdvanceFleetRolloutJob;
use App\Models\Deployment;
use App\Models\FleetRollout;
use App\Models\Site;
use App\Models\User;
use App\Services\Coolify\EnvCatalog\DeamonRepo;
use App\Services\GitHub\CiBranchHeads;
use App\Services\Ops\PacedFanout;
use App\Services\Sites\CoolifyDeploySettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * CI-gated CMS rollout of one green commit on one channel branch.
 *
 *   canary  → deploy every `ci`-gated canary site, wait for each build to finish
 *             and the site to pass health; any failure or the timeout halts.
 *   fanout  → the remaining `ci`-gated sites through PacedFanout, a batch per tick.
 *   done    → counts in `summary`.
 *
 * Every tick first asks whether the commit is still the branch head: Coolify
 * builds HEAD, so once the branch has moved on a deploy would build a commit
 * whose CI has not passed. The rollout then stops as `superseded`.
 */
class FleetRolloutService
{
    private const WAIT = 'wait';

    private const PASSED = 'passed';

    private const STOPPED = 'stopped';

    public function __construct(
        private readonly CiBranchHeads $heads,
        private readonly CoolifyDeploySettings $settings,
        private readonly PacedFanout $fanout,
        private readonly CanaryHealthProbe $health,
    ) {}

    /**
     * A green `CI` run for the head of a channel branch. Idempotent per commit:
     * a re-run of the same workflow never starts a second rollout.
     */
    public function startForGreenCommit(Channel $channel, string $sha): ?FleetRollout
    {
        $lock = Cache::lock('fleet-rollout-start:'.$channel->value, 30);

        return $lock->block(10, function () use ($channel, $sha): ?FleetRollout {
            if (FleetRollout::query()->where('channel', $channel->value)->where('sha', $sha)->exists()) {
                return null;
            }

            foreach (FleetRollout::query()->open()->where('channel', $channel->value)->get() as $open) {
                $this->supersede($open, 'newer_green', $sha);
            }

            if (! $this->eligibleSites($channel)->exists()) {
                return null;
            }

            $rollout = FleetRollout::query()->create([
                'channel' => $channel,
                'sha' => $sha,
                'status' => FleetRolloutStatus::Canary,
                'started_at' => now(),
                'stage_started_at' => now(),
                'summary' => [],
            ]);

            $this->audit($rollout, 'fleet_rollout.started', [
                'channel' => $channel->value,
                'sha' => $sha,
            ]);

            AdvanceFleetRolloutJob::dispatch($rollout->id);

            return $rollout;
        });
    }

    /**
     * One tick. Returns the seconds until the next tick, or null when the rollout
     * no longer needs one (done, halted, superseded).
     */
    public function advance(FleetRollout $rollout): ?int
    {
        $rollout->refresh();
        if (! $rollout->isOpen()) {
            return null;
        }

        if (! $this->isCurrent($rollout)) {
            $this->supersede($rollout, 'branch_moved');

            return null;
        }

        if ($rollout->status === FleetRolloutStatus::Canary) {
            $state = $this->advanceCanary($rollout);
            if ($state === self::STOPPED) {
                return null;
            }
            if ($state === self::WAIT) {
                $rollout->touch();

                return $this->pollSeconds();
            }

            $rollout->status = FleetRolloutStatus::Fanout;
            $rollout->stage_started_at = now();
            $rollout->save();
        }

        $state = $this->advanceFanout($rollout);
        if ($state === self::WAIT) {
            $rollout->touch();

            return $this->pollSeconds();
        }

        return null;
    }

    public function halt(FleetRollout $rollout, ?User $actor, ?string $ip): void
    {
        if (! $rollout->isOpen()) {
            throw new FleetRolloutException(__('rollouts.errors.not_open'));
        }

        $this->markHalted($rollout, 'manual', [], $actor, $ip);
    }

    /**
     * Continue a halted rollout with the fan-out stage (the canary result is the
     * operator's call now). Refused once the branch has moved on.
     */
    public function resume(FleetRollout $rollout, ?User $actor, ?string $ip): void
    {
        if ($rollout->status !== FleetRolloutStatus::Halted) {
            throw new FleetRolloutException(__('rollouts.errors.not_halted'));
        }

        if (! $this->isCurrent($rollout)) {
            throw new FleetRolloutException(__('rollouts.errors.not_current', ['sha' => $rollout->shortSha()]));
        }

        $summary = $this->summary($rollout);
        $summary['resumed'] = ['by' => $actor?->id, 'at' => now()->toIso8601String()];

        $rollout->forceFill([
            'status' => FleetRolloutStatus::Fanout,
            'stage_started_at' => now(),
            'finished_at' => null,
            'halted_reason' => null,
            'halted_by' => null,
            'summary' => $summary,
        ])->save();

        $this->audit($rollout, 'fleet_rollout.resumed', ['sha' => $rollout->sha], $actor, $ip);

        AdvanceFleetRolloutJob::dispatch($rollout->id);
    }

    /**
     * `ci`-gated, active, not pinned, with a Coolify app, on the channel.
     *
     * @return Builder<Site>
     */
    public function eligibleSites(Channel $channel): Builder
    {
        return Site::query()
            ->where('channel', $channel->value)
            ->where('deploy_gate', DeployGate::Ci->value)
            ->where('status', SiteStatus::Active->value)
            ->whereNotNull('coolify_app_uuid')
            ->where('coolify_app_uuid', '!=', '')
            ->where(fn (Builder $query) => $query->whereNull('coolify_pinned_sha')->orWhere('coolify_pinned_sha', ''));
    }

    public function qualifies(Site $site, FleetRollout $rollout): bool
    {
        return ! $site->trashed()
            && $site->channel === $rollout->channel
            && $site->usesCiGate()
            && $site->status === SiteStatus::Active
            && filled($site->coolify_app_uuid)
            && ! $site->hasPinnedCommit();
    }

    /**
     * The rollout's commit is still the last push to its branch.
     */
    public function isCurrent(FleetRollout $rollout): bool
    {
        $repo = DeamonRepo::fullName();

        return $repo !== null && $this->heads->isHead($repo, $rollout->channel->value, $rollout->sha);
    }

    private function advanceCanary(FleetRollout $rollout): string
    {
        $stage = $rollout->stage('canary');

        if (! array_key_exists('site_ids', $stage)) {
            $ids = $this->eligibleSites($rollout->channel)
                ->where('deploy_canary', true)
                ->orderBy('name')
                ->pluck('id')
                ->map(fn (mixed $id): string => (string) $id)
                ->all();

            $stage = [
                'site_ids' => $ids,
                'pending' => $ids,
                'deployments' => [],
                'healthy' => [],
                'health_failures' => [],
                'failed' => [],
            ];
            $this->saveStage($rollout, 'canary', $stage);
            $rollout->stage_started_at = now();
            $rollout->save();

            if ($ids === []) {
                return self::PASSED;
            }
        }

        if ($this->canaryTimedOut($rollout)) {
            $waiting = array_values(array_diff($stage['site_ids'] ?? [], $stage['healthy'] ?? []));
            $this->markHalted($rollout, 'timeout', $waiting);

            return self::STOPPED;
        }

        $pending = $this->stringList($stage['pending'] ?? []);
        if ($pending !== []) {
            $batch = $this->deployBatch($rollout, $pending);
            if ($batch['aborted']) {
                $this->supersede($rollout, 'branch_moved');

                return self::STOPPED;
            }
            if ($batch['stopped']) {
                $rollout->refresh();
            }

            foreach ($batch['deployed'] as $siteId => $deploymentId) {
                $stage['deployments'][$siteId] = $deploymentId;
            }
            $stage['site_ids'] = array_values(array_diff($stage['site_ids'] ?? [], $batch['skipped']));
            $stage['pending'] = array_values(array_diff(
                $pending,
                array_keys($batch['deployed']),
                array_keys($batch['failed']),
                $batch['skipped'],
            ));
            $this->saveStage($rollout, 'canary', $stage);

            if ($batch['stopped']) {
                return self::STOPPED;
            }

            if ($batch['failed'] !== []) {
                $this->markHalted($rollout, 'canary_deploy_failed', array_keys($batch['failed']), errors: $batch['failed']);

                return self::STOPPED;
            }
        }

        $failures = [];
        $healthy = $this->stringList($stage['healthy'] ?? []);
        $healthFailures = is_array($stage['health_failures'] ?? null) ? $stage['health_failures'] : [];
        $attempts = max(1, (int) config('ops.ci.canary_health_attempts', 3));
        $waiting = false;

        foreach ((array) ($stage['deployments'] ?? []) as $siteId => $deploymentId) {
            $siteId = (string) $siteId;
            if (in_array($siteId, $healthy, true)) {
                continue;
            }

            $deployment = Deployment::query()->find($deploymentId);
            $status = $deployment?->status;

            if ($status === DeploymentStatus::Failed || $status === DeploymentStatus::Cancelled || $deployment === null) {
                $failures[$siteId] = $deployment?->error_message ?: 'deploy '.($status->value ?? 'missing');

                continue;
            }

            if ($status !== DeploymentStatus::Finished) {
                $waiting = true;

                continue;
            }

            $site = Site::query()->find($siteId);
            if ($site !== null && $this->health->passes($site)) {
                $healthy[] = $siteId;

                continue;
            }

            $healthFailures[$siteId] = (int) ($healthFailures[$siteId] ?? 0) + 1;
            if ($healthFailures[$siteId] >= $attempts) {
                $failures[$siteId] = 'health';
            } else {
                $waiting = true;
            }
        }

        $stage['healthy'] = array_values(array_unique($healthy));
        $stage['health_failures'] = $healthFailures;
        $stage['failed'] = array_keys($failures);
        $this->saveStage($rollout, 'canary', $stage);

        if ($failures !== []) {
            $this->markHalted($rollout, 'canary_failed', array_keys($failures), errors: $failures);

            return self::STOPPED;
        }

        if ($waiting || ($stage['pending'] ?? []) !== []) {
            return self::WAIT;
        }

        return self::PASSED;
    }

    private function advanceFanout(FleetRollout $rollout): string
    {
        $stage = $rollout->stage('fanout');

        if (! array_key_exists('site_ids', $stage)) {
            $canaryIds = $this->stringList($rollout->stage('canary')['site_ids'] ?? []);
            $ids = $this->eligibleSites($rollout->channel)
                ->when($canaryIds !== [], fn (Builder $query) => $query->whereNotIn('id', $canaryIds))
                ->orderBy('name')
                ->pluck('id')
                ->map(fn (mixed $id): string => (string) $id)
                ->all();

            $stage = [
                'site_ids' => $ids,
                'pending' => $ids,
                'deployed' => [],
                'failed' => [],
                'skipped' => [],
            ];
            $this->saveStage($rollout, 'fanout', $stage);
        }

        $pending = $this->stringList($stage['pending'] ?? []);
        if ($pending !== []) {
            $size = max(1, (int) config('ops.ci.fanout_batch', 20));
            $batch = $this->deployBatch($rollout, array_slice($pending, 0, $size));
            if ($batch['aborted']) {
                $this->supersede($rollout, 'branch_moved');

                return self::STOPPED;
            }
            if ($batch['stopped']) {
                $rollout->refresh();
            }

            $failed = is_array($stage['failed'] ?? null) ? $stage['failed'] : [];
            foreach ($batch['deployed'] as $siteId => $deploymentId) {
                $deployment = Deployment::query()->find($deploymentId);
                if ($deployment?->status === DeploymentStatus::Failed) {
                    $failed[$siteId] = $deployment->error_message ?: 'deploy failed';

                    continue;
                }
                $stage['deployed'][] = $siteId;
            }
            foreach ($batch['failed'] as $siteId => $message) {
                $failed[$siteId] = $message;
            }
            $stage['failed'] = $failed;
            $stage['skipped'] = array_values(array_unique(array_merge($stage['skipped'] ?? [], $batch['skipped'])));
            $stage['pending'] = array_values(array_diff(
                $pending,
                array_keys($batch['deployed']),
                array_keys($batch['failed']),
                $batch['skipped'],
            ));
            $this->saveStage($rollout, 'fanout', $stage);

            if ($batch['stopped']) {
                return self::STOPPED;
            }
        }

        if (($stage['pending'] ?? []) !== []) {
            return self::WAIT;
        }

        $rollout->forceFill([
            'status' => FleetRolloutStatus::Done,
            'finished_at' => now(),
        ])->save();

        $this->audit($rollout, 'fleet_rollout.done', [
            'canary' => $rollout->stageCounts('canary'),
            'fanout' => $rollout->stageCounts('fanout'),
        ]);

        return self::STOPPED;
    }

    /**
     * Deploys the given sites (in order) through PacedFanout. A site that no longer
     * qualifies is `skipped`; a host that is still building someone else's deploy
     * leaves the site pending for the next tick; a branch that moved aborts.
     *
     * @param  list<string>  $siteIds
     * @return array{deployed: array<string, int>, failed: array<string, string>, skipped: list<string>, aborted: bool, stopped: bool}
     */
    private function deployBatch(FleetRollout $rollout, array $siteIds): array
    {
        $deployed = [];
        $failed = [];
        $skipped = [];
        $aborted = false;
        $stopped = false;

        $sites = Site::query()->whereIn('id', $siteIds)->get()->keyBy(fn (Site $site): string => (string) $site->id);
        $targets = [];
        foreach ($siteIds as $id) {
            $site = $sites->get($id);
            if ($site === null || ! $this->qualifies($site, $rollout)) {
                $skipped[] = $id;

                continue;
            }
            $targets[] = $site;
        }

        $this->fanout->run($targets, function (Site $site) use ($rollout, &$deployed, &$failed, &$skipped, &$aborted, &$stopped): void {
            if ($aborted || $stopped) {
                return;
            }

            // An operator halt (or a newer rollout) that landed mid-sweep wins.
            $status = FleetRollout::query()->toBase()->where('id', $rollout->id)->value('status');
            if ($status !== $rollout->status->value) {
                $stopped = true;

                return;
            }

            // Re-checked before every deploy call: a push that lands mid-sweep
            // moves HEAD, and the next build would be an untested commit.
            if (! $this->isCurrent($rollout)) {
                $aborted = true;

                return;
            }

            $fresh = $site->fresh();
            if ($fresh === null || ! $this->qualifies($fresh, $rollout)) {
                $skipped[] = (string) $site->id;

                return;
            }

            try {
                $deployment = $this->settings->deployForRollout($fresh, $rollout);
                $deployed[(string) $site->id] = $deployment->id;
            } catch (Throwable $exception) {
                if ($this->fanout->isDeployBusy($exception)) {
                    return; // host busy: stays pending for the next tick
                }
                if ($this->fanout->isTransient($exception)) {
                    throw $exception; // PacedFanout waits out the Coolify throttle
                }
                $failed[(string) $site->id] = $exception->getMessage();
            }
        });

        return [
            'deployed' => $deployed,
            'failed' => $failed,
            'skipped' => array_values(array_unique($skipped)),
            'aborted' => $aborted,
            'stopped' => $stopped,
        ];
    }

    private function canaryTimedOut(FleetRollout $rollout): bool
    {
        $started = $rollout->stage_started_at ?? $rollout->started_at;
        $minutes = max(1, (int) config('ops.ci.canary_timeout_minutes', 30));

        return $started !== null && $started->lt(now()->subMinutes($minutes));
    }

    /**
     * @param  list<string>  $siteIds
     * @param  array<string, string>  $errors
     */
    private function markHalted(
        FleetRollout $rollout,
        string $code,
        array $siteIds,
        ?User $actor = null,
        ?string $ip = null,
        array $errors = [],
    ): void {
        $names = Site::query()->whereIn('id', $siteIds)->orderBy('name')->pluck('name')->all();
        $summary = $this->summary($rollout);
        $summary['halt'] = [
            'code' => $code,
            'site_ids' => $siteIds,
            'sites' => $names,
            'at' => now()->toIso8601String(),
        ];
        foreach ($errors as $siteId => $message) {
            $summary['errors'][] = $this->siteName($siteId).': '.$message;
        }

        $reason = __('rollouts.halt_reason.'.$code, ['sites' => implode(', ', $names) ?: '—']);

        $rollout->forceFill([
            'status' => FleetRolloutStatus::Halted,
            'finished_at' => now(),
            'halted_reason' => (string) $reason,
            'halted_by' => $actor?->id,
            'summary' => $summary,
        ])->save();

        $this->audit($rollout, $actor === null ? 'fleet_rollout.canary_failed' : 'fleet_rollout.halted', [
            'reason' => $code,
            'sha' => $rollout->sha,
            'sites' => $names,
        ], $actor, $ip);

        if ($actor === null) {
            foreach (Site::query()->whereIn('id', $siteIds)->get() as $site) {
                $site->auditLogs()->create([
                    'actor_user_id' => null,
                    'action' => 'site.ci_rollout_canary_failed',
                    'before' => null,
                    'after' => [
                        'rollout_id' => $rollout->id,
                        'sha' => $rollout->sha,
                        'reason' => $code,
                        'error' => $errors[(string) $site->id] ?? null,
                    ],
                    'ip' => null,
                ]);
            }
        }
    }

    private function supersede(FleetRollout $rollout, string $code, ?string $bySha = null): void
    {
        $summary = $this->summary($rollout);
        $summary['superseded'] = ['code' => $code, 'by_sha' => $bySha, 'at' => now()->toIso8601String()];

        $rollout->forceFill([
            'status' => FleetRolloutStatus::Superseded,
            'finished_at' => now(),
            'summary' => $summary,
        ])->save();

        $this->audit($rollout, 'fleet_rollout.superseded', [
            'reason' => $code,
            'sha' => $rollout->sha,
            'by_sha' => $bySha,
        ]);
    }

    /**
     * @param  array<string, mixed>  $stage
     */
    private function saveStage(FleetRollout $rollout, string $name, array $stage): void
    {
        $summary = $this->summary($rollout);
        $summary[$name] = $stage;
        $rollout->summary = $summary;
        $rollout->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(FleetRollout $rollout): array
    {
        return is_array($rollout->summary) ? $rollout->summary : [];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $item): string => (string) $item, $value));
    }

    private function siteName(string $siteId): string
    {
        return (string) (Site::query()->whereKey($siteId)->value('name') ?? $siteId);
    }

    private function pollSeconds(): int
    {
        return max(5, (int) config('ops.ci.poll_seconds', 30));
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(FleetRollout $rollout, string $action, array $after, ?User $actor = null, ?string $ip = null): void
    {
        $rollout->auditLogs()->create([
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'before' => null,
            'after' => $after,
            'ip' => $ip,
        ]);
    }
}
