<?php

namespace App\Services\GitHub;

use App\Enums\CiRunVerdict;
use App\Models\CiBranchHead;

/**
 * Records the last pushed commit per repo branch and the CI verdict GitHub
 * reports for it. The "is this still the head?" answer comes from here, never
 * from the GitHub API: Plane already receives every push it cares about.
 */
class CiBranchHeads
{
    public const SUCCESS = 'success';

    public const SUPERSEDED = 'superseded';

    public function recordPush(string $repo, string $branch, string $sha): CiBranchHead
    {
        $head = $this->row($repo, $branch);
        $head->head_sha = $sha;
        $head->pushed_at = now();
        $head->save();

        return $head;
    }

    /**
     * @return array{verdict: CiRunVerdict, head: CiBranchHead}
     */
    public function recordRun(string $repo, string $branch, string $sha, string $conclusion): array
    {
        $head = $this->row($repo, $branch);

        if ($conclusion !== self::SUCCESS) {
            $verdict = CiRunVerdict::Red;
        } elseif ($head->head_sha === null || $head->head_sha === '') {
            $verdict = CiRunVerdict::HeadUnknown;
        } elseif (! hash_equals($head->head_sha, $sha)) {
            $verdict = CiRunVerdict::Superseded;
        } else {
            $verdict = CiRunVerdict::Promote;
        }

        // A late verdict for an older commit must not overwrite the head's own verdict.
        $headVerdictKnown = $head->head_sha !== null
            && $head->head_sha !== $sha
            && $head->ci_sha === $head->head_sha;

        if (! $headVerdictKnown) {
            $head->ci_status = $verdict === CiRunVerdict::Superseded ? self::SUPERSEDED : $conclusion;
            $head->ci_sha = $sha;
            $head->ci_at = now();
            $head->save();
        }

        return ['verdict' => $verdict, 'head' => $head];
    }

    /**
     * True while `sha` is the last commit pushed to `branch`.
     */
    public function isHead(string $repo, string $branch, string $sha): bool
    {
        $head = CiBranchHead::for($repo, $branch);

        return $head !== null
            && is_string($head->head_sha)
            && $head->head_sha !== ''
            && hash_equals($head->head_sha, $sha);
    }

    private function row(string $repo, string $branch): CiBranchHead
    {
        return CiBranchHead::query()->firstOrNew([
            'repo_full_name' => CiBranchHead::normalizeRepo($repo),
            'branch' => $branch,
        ]);
    }
}
