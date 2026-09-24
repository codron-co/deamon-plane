<?php

namespace App\Services\GitHub;

use App\Enums\Channel;
use App\Enums\CiRunVerdict;
use App\Models\CiBranchHead;
use App\Models\Theme;
use App\Services\Coolify\EnvCatalog\DeamonRepo;
use App\Services\Rollouts\FleetRolloutService;
use App\Services\Themes\ThemeWebhookFanout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * GitHub `workflow_run` (completed) → the CI gate.
 *
 * Only the workflow named `ops.ci.workflow_name` that ran for a `push` counts.
 * A green run promotes its commit only while that commit is still the recorded
 * head of the branch (CiBranchHeads); anything else is recorded and audited and
 * nothing deploys.
 *
 *   CMS repo, channel branch → FleetRolloutService (sites on the `ci` gate)
 *   theme repo, default ref, `ci_gate` on → catalog sha + ThemeWebhookFanout
 */
class GitHubWorkflowRunHandler
{
    public function __construct(
        private readonly CiBranchHeads $heads,
        private readonly GitHubThemeResolver $themes,
        private readonly ThemeWebhookFanout $fanout,
        private readonly FleetRolloutService $rollouts,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: true, event: string, updated: bool, fanout: int, skipped: int, ci?: string}
     */
    public function handle(string $repo, array $payload): array
    {
        $run = $this->run($payload);
        if ($run === null) {
            return $this->result();
        }

        [$branch, $sha, $conclusion] = $run;

        $cms = DeamonRepo::fullName();
        if ($cms !== null && strcasecmp($cms, $repo) === 0) {
            return $this->cmsRun($repo, $branch, $sha, $conclusion);
        }

        $theme = $this->themes->resolve($repo, $payload);
        if ($theme === null || $branch !== $this->themes->defaultRef($theme, $payload)) {
            return $this->result();
        }

        return $this->themeRun($theme, $repo, $branch, $sha, $conclusion);
    }

    private function cmsRun(string $repo, string $branch, string $sha, string $conclusion): array
    {
        $channel = Channel::tryFrom($branch);
        if ($channel === null || ! $channel->isAllowed()) {
            return $this->result();
        }

        ['verdict' => $verdict, 'head' => $head] = $this->heads->recordRun($repo, $branch, $sha, $conclusion);

        if ($verdict === CiRunVerdict::Promote) {
            $rollout = $this->rollouts->startForGreenCommit($channel, $sha);

            return $this->result($rollout !== null, $verdict);
        }

        $this->auditHead($head, $verdict, $sha, $conclusion);

        return $this->result(false, $verdict);
    }

    private function themeRun(Theme $theme, string $repo, string $branch, string $sha, string $conclusion): array
    {
        ['verdict' => $verdict] = $this->heads->recordRun($repo, $branch, $sha, $conclusion);

        // Without the gate the push already moved the catalog; the run is only recorded.
        if (! $theme->ci_gate) {
            return $this->result(false, $verdict);
        }

        if ($verdict !== CiRunVerdict::Promote) {
            $theme->auditLogs()->create([
                'actor_user_id' => null,
                'action' => $verdict === CiRunVerdict::Red ? 'theme.ci_failed' : 'theme.ci_held',
                'before' => null,
                'after' => [
                    'branch' => $branch,
                    'sha' => $sha,
                    'conclusion' => $conclusion,
                    'verdict' => $verdict->value,
                ],
                'ip' => null,
            ]);

            return $this->result(false, $verdict);
        }

        if ($sha === $theme->latest_sha) {
            return $this->result(false, $verdict);
        }

        $before = $theme->latest_sha;
        $theme->latest_sha = $sha;
        $theme->last_synced_at = Carbon::now();
        $theme->save();

        $counts = $this->fanout->fanOut($theme, $branch);

        $theme->auditLogs()->create([
            'actor_user_id' => null,
            'action' => 'theme.ci_promoted',
            'before' => ['latest_sha' => $before],
            'after' => [
                'latest_sha' => $sha,
                'branch' => $branch,
                'fanout' => $counts['fanout'],
                'skipped' => $counts['skipped'],
            ],
            'ip' => null,
        ]);

        return $this->result(true, $verdict, $counts['fanout'], $counts['skipped']);
    }

    /**
     * A red, superseded or unprovable run of the CMS: nothing deploys; the Activity
     * page says which commit was held and why.
     */
    private function auditHead(CiBranchHead $head, CiRunVerdict $verdict, string $sha, string $conclusion): void
    {
        $action = match ($verdict) {
            CiRunVerdict::Red => 'ci.run_failed',
            CiRunVerdict::Superseded => 'ci.run_superseded',
            default => 'ci.run_head_unknown',
        };

        $head->auditLogs()->create([
            'actor_user_id' => null,
            'action' => $action,
            'before' => null,
            'after' => [
                'repo' => $head->repo_full_name,
                'branch' => $head->branch,
                'sha' => $sha,
                'conclusion' => $conclusion,
                'head_sha' => $head->head_sha,
            ],
            'ip' => null,
        ]);

        Log::info('github.ci_held', [
            'repo' => $head->repo_full_name,
            'branch' => $head->branch,
            'sha' => $sha,
            'verdict' => $verdict->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function run(array $payload): ?array
    {
        $run = $payload['workflow_run'] ?? null;
        if (! is_array($run) || ($payload['action'] ?? null) !== 'completed') {
            return null;
        }

        if (($run['name'] ?? null) !== (string) config('ops.ci.workflow_name', 'CI')) {
            return null;
        }

        // A pull_request / workflow_dispatch / schedule run does not vouch for a branch head.
        if (($run['event'] ?? null) !== 'push') {
            return null;
        }

        $branch = $run['head_branch'] ?? null;
        $sha = $run['head_sha'] ?? null;
        $conclusion = $run['conclusion'] ?? null;
        if (! is_string($branch) || $branch === '' || ! is_string($sha) || $sha === '' || ! is_string($conclusion) || $conclusion === '') {
            return null;
        }

        return [$branch, $sha, $conclusion];
    }

    /**
     * @return array{ok: true, event: string, updated: bool, fanout: int, skipped: int, ci?: string}
     */
    private function result(bool $updated = false, ?CiRunVerdict $verdict = null, int $fanout = 0, int $skipped = 0): array
    {
        $result = [
            'ok' => true,
            'event' => 'workflow_run',
            'updated' => $updated,
            'fanout' => $fanout,
            'skipped' => $skipped,
        ];

        if ($verdict !== null) {
            $result['ci'] = $verdict->value;
        }

        return $result;
    }
}
