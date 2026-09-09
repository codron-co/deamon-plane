<?php

namespace App\Jobs;

use App\Models\SiteThemeInstallation;
use App\Models\User;
use App\Services\Themes\ThemeRolloutService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ThemeInstallJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $installationId,
        public readonly bool $activate = false,
        public readonly bool $sync = false,
        public readonly ?int $actorUserId = null,
        public readonly ?string $ip = null,
    ) {}

    public function uniqueId(): string
    {
        return 'theme-install-'.$this->installationId;
    }

    public function handle(ThemeRolloutService $rollout): void
    {
        $installation = SiteThemeInstallation::query()->find($this->installationId);
        if ($installation === null) {
            return;
        }

        $actor = $this->actorUserId !== null
            ? User::query()->find($this->actorUserId)
            : null;

        $rollout->performInstall($installation, $this->activate, $this->sync, $actor, $this->ip);
    }
}
