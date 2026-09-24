<?php

namespace App\Services\GitHub;

use App\Enums\Channel;
use App\Jobs\SyncCoolifyEnvCatalogJob;
use App\Models\Theme;
use App\Services\Coolify\EnvCatalog\DeamonRepo;
use App\Services\Themes\ThemeWebhookFanout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GitHubWebhookHandler
{
    public function __construct(
        private readonly GitHubThemeResolver $themes = new GitHubThemeResolver,
        private readonly ThemeWebhookFanout $fanout = new ThemeWebhookFanout,
        private readonly CiBranchHeads $heads = new CiBranchHeads,
        private readonly ?GitHubWorkflowRunHandler $workflowRuns = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: true, event: string, updated: bool, fanout: int, skipped: int, ci?: string}
     */
    public function handle(string $event, array $payload, ?string $deliveryId = null): array
    {
        if ($event === 'ping') {
            return $this->result('ping');
        }

        // GitHub "Redeliver" resends a delivery with its original id. Handling an
        // old push again would move every opted-in site back to that commit.
        if (! $this->firstDelivery($deliveryId)) {
            Log::info('github.webhook_duplicate_delivery', ['event' => $event]);

            return $this->result($event);
        }

        $repo = $this->repoFullName($payload);
        if ($repo === null) {
            return $this->result($event);
        }

        if ($event === 'workflow_run') {
            return ($this->workflowRuns ?? app(GitHubWorkflowRunHandler::class))->handle($repo, $payload);
        }

        // CMS repo push → remember the branch head (CI gate) and refresh that
        // branch's Coolify env catalog (.env.production.example).
        $catalogChannel = $this->envCatalogChannel($event, $repo, $payload);
        if ($catalogChannel !== null) {
            $sha = $this->commitSha($payload);
            if ($sha !== null) {
                $this->heads->recordPush($repo, $catalogChannel->value, $sha);
            }
            SyncCoolifyEnvCatalogJob::dispatch($catalogChannel->value);

            return $this->result($event, updated: true);
        }

        $theme = $this->themes->resolve($repo, $payload);
        if ($theme === null) {
            return $this->result($event);
        }

        $defaultRef = $this->themes->defaultRef($theme, $payload);

        if ($event === 'push') {
            return $this->themePush($theme, $defaultRef, $repo, $payload);
        }

        // A release only names a tag; its target is a branch name, not a commit,
        // so it updates the catalog label and leaves installations alone.
        $tag = $this->releaseTag($payload);
        if ($tag === null) {
            return $this->result($event);
        }

        $theme->latest_tag = $tag;
        $theme->last_synced_at = Carbon::now();
        $theme->save();

        return $this->result($event, updated: true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: true, event: string, updated: bool, fanout: int, skipped: int}
     */
    private function themePush(Theme $theme, string $defaultRef, string $repo, array $payload): array
    {
        // Only the catalog branch moves the catalog. A push to a feature
        // branch or a tag must never reach sites that follow the theme.
        if (! $this->isPushTo($payload, $defaultRef)) {
            return $this->result('push');
        }

        $sha = $this->commitSha($payload);
        if ($sha === null) {
            return $this->result('push');
        }

        $this->heads->recordPush($repo, $defaultRef, $sha);

        // CI-gated theme: the push is only a candidate. The green `CI` run for
        // this exact commit moves the catalog (GitHubWorkflowRunHandler).
        if ($theme->ci_gate || $sha === $theme->latest_sha) {
            return $this->result('push');
        }

        $theme->latest_sha = $sha;
        $theme->last_synced_at = Carbon::now();
        $theme->save();

        $counts = $this->fanout->fanOut($theme, $defaultRef);

        return $this->result('push', updated: true, fanout: $counts['fanout'], skipped: $counts['skipped']);
    }

    /**
     * @return array{ok: true, event: string, updated: bool, fanout: int, skipped: int}
     */
    private function result(string $event, bool $updated = false, int $fanout = 0, int $skipped = 0): array
    {
        return [
            'ok' => true,
            'event' => $event,
            'updated' => $updated,
            'fanout' => $fanout,
            'skipped' => $skipped,
        ];
    }

    /**
     * A `push` to an allowlisted branch of the CMS repo (config ops.deamon.repository).
     *
     * @param  array<string, mixed>  $payload
     */
    private function envCatalogChannel(string $event, string $repo, array $payload): ?Channel
    {
        if ($event !== 'push') {
            return null;
        }

        $cms = DeamonRepo::fullName();
        if ($cms === null || strcasecmp($cms, $repo) !== 0) {
            return null;
        }

        $ref = $payload['ref'] ?? null;
        if (! is_string($ref) || ! str_starts_with($ref, 'refs/heads/')) {
            return null;
        }

        $channel = Channel::tryFrom(substr($ref, 11));

        return $channel !== null && $channel->isAllowed() ? $channel : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function repoFullName(array $payload): ?string
    {
        $full = $payload['repository']['full_name'] ?? null;
        if (is_string($full) && $full !== '') {
            return $full;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function commitSha(array $payload): ?string
    {
        $sha = $payload['after'] ?? $payload['head_commit']['id'] ?? null;

        return is_string($sha) && $sha !== '' && $sha !== '0000000000000000000000000000000000000000'
            ? $sha
            : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function releaseTag(array $payload): ?string
    {
        $tag = $payload['release']['tag_name'] ?? null;

        return is_string($tag) && $tag !== '' ? $tag : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isPushTo(array $payload, string $branch): bool
    {
        $ref = $payload['ref'] ?? null;

        return is_string($ref) && $ref === 'refs/heads/'.$branch;
    }

    private function firstDelivery(?string $deliveryId): bool
    {
        $deliveryId = trim((string) $deliveryId);
        if ($deliveryId === '') {
            return true;
        }

        return Cache::add('github-webhook-delivery:'.sha1($deliveryId), true, now()->addDays(7));
    }
}
