<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Mcp\McpException;
use App\Mcp\McpPresenter;
use App\Models\Deployment;
use App\Models\OpsBackgroundJob;
use App\Models\Site;

class ListJobsTool extends ReadTool
{
    public function name(): string
    {
        return 'list_jobs';
    }

    public function description(): string
    {
        return 'Background jobs (Coolify sync, catalog sync, bulk deploys) and active deployments. Pass job_id to follow one job a write tool returned until status is completed or failed.';
    }

    public function inputSchema(): array
    {
        return self::schema([
            'job_id' => ['type' => 'string', 'description' => 'One job (the `job.id` a write tool returned).'],
            'active_only' => ['type' => 'boolean', 'default' => false],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
        ]);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $jobId = trim((string) ($arguments['job_id'] ?? ''));
        if ($jobId !== '') {
            $job = OpsBackgroundJob::query()->find($jobId);
            if (! $job instanceof OpsBackgroundJob) {
                throw new McpException("Job `{$jobId}` not found.", 'not_found');
            }

            return ['job' => McpPresenter::job($job)];
        }

        $jobs = OpsBackgroundJob::query()
            ->when($arguments['active_only'] ?? false, fn ($query) => $query->whereIn('status', ['queued', 'running']))
            ->latest()
            ->limit(self::limit($arguments, 20))
            ->get();

        $deployments = Deployment::query()
            ->whereIn('status', ['waiting', 'queued', 'in_progress'])
            ->orderBy('id')
            ->get();
        $slugs = Site::withTrashed()->whereIn('id', $deployments->pluck('site_id')->unique())->pluck('slug', 'id');

        return [
            'jobs' => $jobs->map(fn (OpsBackgroundJob $job): array => McpPresenter::job($job))->values()->all(),
            'active_deployments' => $deployments->map(fn (Deployment $deployment): array => array_merge(
                ['site' => $slugs[$deployment->site_id] ?? null],
                McpPresenter::deployment($deployment),
            ))->values()->all(),
        ];
    }
}
