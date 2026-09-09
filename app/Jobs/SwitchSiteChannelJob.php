<?php

namespace App\Jobs;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\Sites\ChannelSwitcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SwitchSiteChannelJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $siteId,
        public readonly ?int $actorUserId = null,
        public readonly ?string $ip = null,
    ) {}

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

        try {
            $switcher->switchOnCoolify($site, $this->actorUserId, $this->ip);
        } catch (Throwable $exception) {
            $fresh = $site->fresh() ?? $site;
            $switcher->markFailed(
                $fresh,
                $switcher->safeFailureMessage($fresh, $exception),
                $fresh->deployments()->latest('id')->first(),
                $this->actorUserId,
                $this->ip,
            );
        }
    }
}
