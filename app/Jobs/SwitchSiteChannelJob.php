<?php

namespace App\Jobs;

use App\Enums\OpsLane;
use App\Enums\SiteStatus;
use App\Jobs\Concerns\OnOpsLane;
use App\Models\Site;
use App\Services\Sites\ChannelSwitcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SwitchSiteChannelJob implements ShouldBeUnique, ShouldQueue
{
    use OnOpsLane;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $siteId,
        public readonly ?int $actorUserId = null,
        public readonly ?string $ip = null,
    ) {
        $this->onLane(OpsLane::Critical);
    }

    public function uniqueId(): string
    {
        return $this->siteId;
    }

    public function handle(ChannelSwitcher $switcher): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null || $site->status !== SiteStatus::Deploying) {
            return;
        }

        // Only a deployment row this attempt created may be marked failed; an
        // older (possibly successful) row of the site must stay as it was.
        $lastRowBefore = (int) $site->deployments()->max('id');

        try {
            $switcher->switchOnCoolify($site, $this->actorUserId, $this->ip);
        } catch (Throwable $exception) {
            $fresh = $site->fresh() ?? $site;
            $switcher->markFailed(
                $fresh,
                $switcher->safeFailureMessage($fresh, $exception),
                $fresh->deployments()->where('id', '>', $lastRowBefore)->latest('id')->first(),
                $this->actorUserId,
                $this->ip,
            );
        }
    }

    /**
     * Timeout or worker restart: the catch above never ran. Leave the site in
     * error with a reason instead of stuck mid-way (the watchdog covers rows).
     */
    public function failed(?Throwable $exception): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null || $site->status !== SiteStatus::Deploying) {
            return;
        }

        app(ChannelSwitcher::class)->markFailed(
            $site,
            __('sites.errors.job_stopped'),
            null,
            $this->actorUserId,
            $this->ip,
        );
    }
}
