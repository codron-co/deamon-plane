<?php

namespace App\Jobs;

use App\Enums\ThemeInstallationStatus;
use App\Models\SiteThemeInstallation;
use App\Models\User;
use App\Services\Themes\ThemeRolloutService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

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

    /**
     * Timeout or worker restart left the installation mid-way; mark it so the
     * operator sees it and it can be retried (audit P09).
     */
    public function failed(?Throwable $exception): void
    {
        $installation = SiteThemeInstallation::query()->find($this->installationId);
        if ($installation === null || ! in_array($installation->status, [ThemeInstallationStatus::Installing, ThemeInstallationStatus::Updating], true)) {
            return;
        }

        $installation->status = ThemeInstallationStatus::Error;
        $installation->last_error = __('sites.errors.job_stopped');
        $installation->save();

        $installation->site?->auditLogs()->create([
            'actor_user_id' => null,
            'action' => 'theme.job_stopped',
            'after' => ['installation_id' => $installation->id],
            'ip' => null,
        ]);
    }
}
