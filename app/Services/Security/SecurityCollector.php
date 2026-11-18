<?php

namespace App\Services\Security;

use App\Models\Site;
use App\Models\SiteSecurityCursor;
use App\Models\SiteSecurityEvent;
use Illuminate\Support\Facades\DB;

/**
 * Pulls one site's security summary and stores what is new. Sequential and
 * bounded: a few pages of 200 events per run, one site per call. A site that
 * errors is marked and left alone until the next run; it never throws, so one
 * broken CMS cannot stall the sweep.
 *
 * Events are deduplicated by (site, event_uid); the cursor only advances after a
 * page was stored, so a crash replays at most one page and replays are harmless.
 */
class SecurityCollector
{
    public function __construct(
        private readonly SiteSecurityClient $client,
        private readonly SecurityAlertNotifier $alerts,
    ) {}

    /**
     * @return array{state: string, stored: int}
     */
    public function collect(Site $site): array
    {
        $cursor = SiteSecurityCursor::query()->firstOrNew(['site_id' => $site->id]);
        $maxPages = max(1, (int) config('ops.security.max_pages_per_run', 3));
        $stored = 0;
        $fresh = [];
        $state = SecurityCallResult::OK;

        for ($page = 0; $page < $maxPages; $page++) {
            $result = $this->client->summary($site, $cursor->cursor);
            $cursor->last_polled_at = now();

            if (! $result->isOk()) {
                $state = $result->state;
                $this->recordFailure($cursor, $result);
                break;
            }

            $payload = $result->payload;
            $cursor->supported = true;
            $cursor->consecutive_failures = 0;
            $cursor->last_error = null;
            $cursor->last_success_at = now();
            $cursor->module_enabled = ($payload['module_enabled'] ?? true) !== false;
            $cursor->summary = SecurityEventSanitizer::summary($payload);

            $rows = $cursor->module_enabled ? $this->rows($payload) : [];
            $new = $this->store($site, $rows);
            $stored += count($new);
            array_push($fresh, ...$new);

            $next = $payload['cursor'] ?? null;
            if (is_string($next) && $next !== '' && strlen($next) <= 255) {
                $cursor->cursor = $next;
            }
            $cursor->save();

            if (($payload['has_more'] ?? false) !== true || $rows === []) {
                break;
            }
        }

        $cursor->save();

        if ($fresh !== []) {
            $this->alerts->handle($site, $fresh);
        }

        return ['state' => $state, 'stored' => $stored];
    }

    private function recordFailure(SiteSecurityCursor $cursor, SecurityCallResult $result): void
    {
        if ($result->isUnsupported()) {
            // Older CMS or module not installed: not a fault, asked again in a day.
            $cursor->supported = false;
            $cursor->consecutive_failures = 0;
            $cursor->last_error = null;

            return;
        }

        $cursor->consecutive_failures = min(1000, (int) $cursor->consecutive_failures + 1);
        $cursor->last_error = mb_substr($result->message, 0, 255);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function rows(array $payload): array
    {
        $rows = [];
        foreach (array_slice((array) ($payload['events'] ?? []), 0, 200) as $raw) {
            $event = is_array($raw) ? SecurityEventSanitizer::event($raw) : null;
            if ($event !== null) {
                $rows[] = $event;
            }
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>> the rows that were not stored yet
     */
    private function store(Site $site, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $existing = SiteSecurityEvent::query()
            ->where('site_id', $site->id)
            ->whereIn('event_uid', array_column($rows, 'event_uid'))
            ->pluck('count', 'event_uid')
            ->all();
        $known = array_fill_keys(array_keys($existing), true);

        // The same uid can come back with a bigger count (the site merged more hits into it):
        // update the row, never duplicate it, and never alert twice for it.
        foreach ($rows as $row) {
            if (isset($existing[$row['event_uid']]) && (int) $existing[$row['event_uid']] !== (int) $row['count']) {
                SiteSecurityEvent::query()
                    ->where('site_id', $site->id)
                    ->where('event_uid', $row['event_uid'])
                    ->update(['count' => $row['count']]);
            }
        }

        $new = [];
        foreach ($rows as $row) {
            if (! isset($known[$row['event_uid']])) {
                $known[$row['event_uid']] = true; // the same uid twice in one page
                $new[] = $row;
            }
        }

        if ($new === []) {
            return [];
        }

        $stamp = now()->format('Y-m-d H:i:s');
        $insert = array_map(fn (array $row): array => $row + ['site_id' => $site->id, 'created_at' => $stamp], $new);

        DB::transaction(function () use ($insert): void {
            foreach (array_chunk($insert, 100) as $chunk) {
                SiteSecurityEvent::query()->insertOrIgnore($chunk);
            }
        });

        return $new;
    }
}
