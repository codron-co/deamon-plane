<?php

namespace App\Services\Ops;

use App\Models\Site;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyCredentials;
use App\Services\Coolify\CoolifyDeployBusyException;
use App\Services\Coolify\CoolifyDeployGate;
use App\Services\Coolify\CoolifyRateGuard;
use App\Support\RetryAfter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Runs one action per site, one site at a time, and treats a Coolify throttle as
 * "not yet" instead of "failed": the site goes to the back of the queue and the
 * sweep waits out the shared cooldown before continuing. A bulk op therefore
 * finishes every site unless it hits a real, non-transient error.
 *
 * Three ways a site can end up untouched, and the operator needs to tell them
 * apart: a real error (`failed`), the Coolify request limit (`skipped`), and a
 * build slot still held by somebody else (`waiting`). Only the first is a fault.
 */
class PacedFanout
{
    public function __construct(
        private readonly CoolifyRateGuard $guard,
        private readonly CoolifyDeployGate $gate,
    ) {}

    /**
     * A site deferred by a throttle and then completed counts as `ok`: the only
     * sites in `skipped` are the ones that ran out of attempts while throttled,
     * so `rate_limited` answers "did the throttle leave work undone?" and never
     * "did we see a 429 somewhere?". The same holds for `waiting` and the
     * per-server build gate.
     *
     * @param  iterable<int, Site>  $sites
     * @param  callable(Site): void  $action
     * @param  null|callable(Site, int, int): void  $onProgress  Receives (site, completed, total).
     * @return array{ok: int, failed: int, skipped: int, waiting: int, errors: list<string>, waiting_sites: list<string>, rate_limited: bool, deploy_busy: bool, throttled: bool, deferrals: int, deferred: int, sites: list<Site>}
     */
    public function run(iterable $sites, callable $action, ?callable $onProgress = null): array
    {
        // The sweep is itself the queue: it must not stand behind its own builds.
        return $this->gate->duringSweep(fn (): array => $this->sweep($sites, $action, $onProgress));
    }

    /**
     * @param  iterable<int, Site>  $sites
     * @param  callable(Site): void  $action
     * @param  null|callable(Site, int, int): void  $onProgress
     * @return array{ok: int, failed: int, skipped: int, waiting: int, errors: list<string>, waiting_sites: list<string>, rate_limited: bool, deploy_busy: bool, throttled: bool, deferrals: int, deferred: int, sites: list<Site>}
     */
    private function sweep(iterable $sites, callable $action, ?callable $onProgress): array
    {
        /** @var list<array{site: Site, attempt: int, deferred: bool}> $pending */
        $pending = [];
        foreach ($sites as $site) {
            if ($site instanceof Site) {
                $pending[] = ['site' => $site, 'attempt' => 0, 'deferred' => false];
            }
        }

        $total = max(1, count($pending));
        $maxAttempts = $this->maxSiteAttempts();

        $ok = 0;
        $failed = 0;
        $skipped = 0;
        $waiting = 0;
        $completed = 0;
        $deferrals = 0;
        $deferred = 0;
        $throttled = false;
        $errors = [];
        $waitingSites = [];
        $done = [];

        while ($pending !== []) {
            /** @var array{site: Site, attempt: int, deferred: bool} $entry */
            $entry = array_shift($pending);
            $site = $entry['site'];

            try {
                $action($site);
                $ok++;
                if ($entry['deferred']) {
                    $deferred++;
                }
            } catch (Throwable $exception) {
                $throttled = $throttled || $this->isRateLimited($exception);

                if ($this->isTransient($exception) && $maxAttempts > $entry['attempt'] + 1) {
                    $entry['attempt']++;
                    $entry['deferred'] = true;
                    $deferrals++;

                    Log::info('Bulk Coolify action deferred', [
                        'site_id' => $site->id,
                        'attempt' => $entry['attempt'],
                        'pending' => count($pending) + 1,
                    ]);

                    // Back of the line, so healthy sites keep progressing while
                    // this one waits for the throttle window to reopen.
                    $pending[] = $entry;
                    $this->waitOut($site, $entry['attempt']);

                    continue;
                }

                if ($this->isDeployBusy($exception)) {
                    // Never asked to deploy: the host slot was taken the whole
                    // time. That is a queue, not a fault, so it stays out of
                    // `errors` as well as out of `failed`.
                    $waiting++;
                    $waitingSites[] = $site->name;
                } elseif ($this->isRateLimited($exception)) {
                    $skipped++;
                    $errors[] = $site->name.': '.$exception->getMessage();
                } else {
                    $failed++;
                    $errors[] = $site->name.': '.$exception->getMessage();
                }
            }

            $completed++;
            $done[] = $site;

            if ($onProgress !== null) {
                $onProgress($site, $completed, $total);
            }
        }

        return [
            'ok' => $ok,
            'failed' => $failed,
            'skipped' => $skipped,
            'waiting' => $waiting,
            'errors' => $errors,
            'waiting_sites' => $waitingSites,
            'rate_limited' => $skipped > 0,
            'deploy_busy' => $waiting > 0,
            'throttled' => $throttled,
            'deferrals' => $deferrals,
            'deferred' => $deferred,
            'sites' => $done,
        ];
    }

    public function isTransient(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof CoolifyDeployBusyException && $current->isRetryable()) {
                return true;
            }

            if ($current instanceof CoolifyApiException) {
                return $current->isTransient();
            }

            if ($current instanceof ConnectionException) {
                return true;
            }
        }

        return in_array((int) $exception->getCode(), [0, 429, 500, 502, 503, 504], true)
            && (int) $exception->getCode() !== 0;
    }

    /**
     * The per-server build gate refused the site. Nothing was sent to Coolify,
     * so the site is exactly where it started — waiting, not broken.
     */
    public function isDeployBusy(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof CoolifyDeployBusyException) {
                return true;
            }
        }

        return false;
    }

    public function isRateLimited(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof CoolifyApiException && $current->isRateLimited()) {
                return true;
            }
        }

        return (int) $exception->getCode() === 429;
    }

    private function waitOut(Site $site, int $attempt): void
    {
        $cooldown = $this->guard->cooldownRemaining($this->hostFor($site));
        $backoff = RetryAfter::backoff(
            $attempt,
            max(1, (int) config('ops.coolify.retry.base_delay_ms', 500)),
            max(1, (int) config('ops.coolify.retry.max_delay_ms', 8000)),
        );

        $delay = max($cooldown, $backoff);
        if ($delay > 0) {
            Sleep::for((int) ceil($delay * 1000))->milliseconds();
        }
    }

    private function hostFor(Site $site): string
    {
        $site->loadMissing('coolifyConnection');
        $connection = $site->coolifyConnection;

        if ($connection !== null && $connection->hasToken()) {
            return $connection->credentials()->apiRoot();
        }

        return CoolifyCredentials::resolve()->apiRoot();
    }

    private function maxSiteAttempts(): int
    {
        return max(1, (int) config('ops.coolify.bulk.max_site_attempts', 6));
    }
}
