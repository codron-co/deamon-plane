<?php

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Services\Sites\Diagnosis\DeploymentDiagnoser;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dispatched by the Deployment model the moment a row turns Failed. Classifies the
 * failure, pulls the container log from Coolify when needed and applies the one safe
 * automatic fix. Idempotent: a row that already carries a diagnosis is left alone.
 */
class DiagnoseDeploymentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public int $uniqueFor = 120;

    public function __construct(
        public readonly int $deploymentId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->deploymentId;
    }

    public function handle(DeploymentDiagnoser $diagnoser): void
    {
        if (! config('ops.diagnosis.enabled', true)) {
            return;
        }

        $deployment = Deployment::query()->with('site')->find($this->deploymentId);
        if ($deployment === null || $deployment->status !== DeploymentStatus::Failed) {
            return;
        }

        if ($deployment->diagnosis !== null) {
            return;
        }

        $diagnoser->diagnose($deployment);
    }
}
