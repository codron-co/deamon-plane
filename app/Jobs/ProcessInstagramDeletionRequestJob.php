<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\InstagramAccount;
use App\Models\InstagramDeletionRequest;
use App\Services\Instagram\InstagramAgentClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Tells every site holding the Instagram user's data to disconnect and purge it. Sites that
 * cannot be reached (or run a CMS without the disconnect endpoint) leave the request `manual`
 * for ops, who has 7 days under the public data deletion page.
 */
class ProcessInstagramDeletionRequestJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly string $requestId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(InstagramAgentClient $agent): void
    {
        $request = InstagramDeletionRequest::query()->find($this->requestId);
        if ($request === null || $request->status === InstagramDeletionRequest::STATUS_COMPLETED) {
            return;
        }

        $accounts = InstagramAccount::query()
            ->with('site')
            ->where('ig_user_id', $request->ig_user_id)
            ->active()
            ->get();

        if ($accounts->isEmpty()) {
            $this->finish($request, InstagramDeletionRequest::STATUS_MANUAL, [], 'no_connected_site');

            return;
        }

        $failed = [];
        $retryable = false;
        foreach ($accounts as $account) {
            $site = $account->site;
            $result = $site !== null ? $agent->disconnect($site) : ['ok' => false, 'error' => 'site_missing'];

            if ($result['ok']) {
                $account->forceFill(['disconnected_at' => now()])->save();

                continue;
            }

            $error = $result['error'] ?? 'failed';
            $failed[] = ($site->slug ?? $account->site_id).': '.$error;
            // Only a down or erroring site is worth another try; an old CMS or a missing site is not.
            $retryable = $retryable || $error === 'site_unreachable' || str_starts_with($error, 'http_5');
        }

        $siteIds = $accounts->pluck('site_id')->values()->all();

        if ($failed === []) {
            $this->finish($request, InstagramDeletionRequest::STATUS_COMPLETED, $siteIds, null);

            return;
        }

        if ($retryable && $this->attempts() < $this->tries) {
            $this->release($this->backoff()[$this->attempts() - 1] ?? 300);

            return;
        }

        $this->finish($request, InstagramDeletionRequest::STATUS_MANUAL, $siteIds, implode('; ', $failed));
    }

    /**
     * @param  list<string>  $siteIds
     */
    private function finish(InstagramDeletionRequest $request, string $status, array $siteIds, ?string $note): void
    {
        $request->forceFill([
            'status' => $status,
            'site_ids' => $siteIds,
            'note' => $note !== null ? mb_substr($note, 0, 255) : null,
            'completed_at' => $status === InstagramDeletionRequest::STATUS_COMPLETED ? now() : null,
        ])->save();

        AuditLog::query()->create([
            'actor_user_id' => null,
            'action' => 'instagram.'.$request->type.'_'.$status,
            'subject_type' => InstagramDeletionRequest::class,
            'subject_id' => $request->id,
            'before' => null,
            'after' => ['site_ids' => $siteIds, 'note' => $note],
            'ip' => null,
        ]);

        if ($status === InstagramDeletionRequest::STATUS_MANUAL) {
            Log::warning('Instagram data deletion needs manual handling', [
                'request_id' => $request->id,
                'type' => $request->type,
                'note' => $note,
            ]);
        }
    }
}
