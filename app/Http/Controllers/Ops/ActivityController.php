<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MailServer;
use App\Models\OpsBackgroundJob;
use App\Models\Site;
use App\Models\Theme;
use App\Models\User;
use App\Support\Lists\ListFragment;
use App\Support\Ops\ActivityFeed;
use App\Support\Ops\ActivityFilters;
use App\Support\Ops\ActivityRow;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Fleet activity: jobs, deployments and audit rows in one list.
 *
 * Unlike GET /jobs (JSON, actor = me, last two hours), this page is a history
 * every ops role can read. Another operator's jobs are visible here. Payloads
 * stay redacted.
 */
class ActivityController extends Controller
{
    public function index(Request $request, ActivityFeed $feed): Response
    {
        $this->authorize('viewAny', Site::class);

        $filters = ActivityFilters::from($request);
        $entries = $feed->paginate($filters);
        $actors = User::query()->orderBy('name')->get(['id', 'name']);
        $sites = Site::query()->orderBy('name')->get(['id', 'name']);

        return ListFragment::respond($request, 'ops.activity.index', 'ops.activity._region', [
            'entries' => $entries,
            'filters' => $filters,
            'search' => $filters->search,
            'kind' => $filters->kind,
            'outcome' => $filters->outcome,
            'actorId' => $filters->actorId,
            'siteId' => $filters->siteId,
            'sortDirection' => $filters->sortDirection,
            'filtersActive' => $filters->active(),
            'activeFilters' => $this->chipDisplay($filters, $actors, $sites),
            'totalActivity' => $entries->total() > 0 ? $entries->total() : $feed->unfilteredTotal(),
            'actors' => $actors,
            'sites' => $sites,
            'kinds' => ActivityFeed::KINDS,
            'outcomes' => ActivityFeed::OUTCOMES,
        ]);
    }

    public function export(Request $request, ActivityFeed $feed): Response
    {
        $this->authorize('viewAny', Site::class);

        $filters = ActivityFilters::from($request);
        $csv = $this->csv($feed->export($filters));
        $name = 'activity-'.now()->timezone((string) config('app.timezone'))->format('Y-m-d').'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function showJob(OpsBackgroundJob $job): View
    {
        $this->authorize('viewAny', Site::class);

        $job->load('actor');

        $result = ActivityFeed::redact($job->result);
        $payload = ActivityFeed::redact($job->payload);

        return view('ops.activity.job', [
            'job' => $job,
            'message' => ActivityFeed::redactText($job->message),
            'resultJson' => $this->pretty($result),
            'payloadJson' => $this->pretty($payload),
        ]);
    }

    public function showAudit(AuditLog $audit): View
    {
        $this->authorize('viewAny', Site::class);

        $audit->load(['actor', 'subject']);

        return view('ops.activity.audit', [
            'audit' => $audit,
            'beforeJson' => $this->pretty(ActivityFeed::redact($audit->before)),
            'afterJson' => $this->pretty(ActivityFeed::redact($audit->after)),
            'subjectUrl' => $this->subjectUrl($audit),
            'subjectLabel' => $this->subjectLabel($audit),
        ]);
    }

    /**
     * Resolve actor/site chip values from the lists already loaded for the
     * toolbar, so a ULID becomes a name without a second query per chip.
     *
     * @param  Collection<int, User>  $actors
     * @param  Collection<int, Site>  $sites
     * @return list<array{key: string, label: string, value: string, url: string}>
     */
    private function chipDisplay(ActivityFilters $filters, Collection $actors, Collection $sites): array
    {
        $chips = $filters->chips;
        foreach ($chips as $i => $chip) {
            if ($chip['key'] === 'actor' && $chip['value'] !== '') {
                $name = $actors->firstWhere('id', (int) $chip['value'])?->name;
                if (is_string($name) && $name !== '') {
                    $chips[$i]['value'] = $name;
                }
            }
            if ($chip['key'] === 'site' && $chip['value'] !== '') {
                $name = $sites->firstWhere('id', $chip['value'])?->name;
                if (is_string($name) && $name !== '') {
                    $chips[$i]['value'] = $name;
                }
            }
        }

        return $chips;
    }

    private function subjectUrl(AuditLog $audit): ?string
    {
        return match ($audit->subject_type) {
            Site::class => route('ops.sites.show', $audit->subject_id),
            Theme::class => route('ops.themes.show', $audit->subject_id),
            MailServer::class => route('ops.mail-servers.show', $audit->subject_id),
            default => null,
        };
    }

    private function subjectLabel(AuditLog $audit): string
    {
        $subject = $audit->subject;
        if ($subject instanceof Site || $subject instanceof Theme || $subject instanceof MailServer) {
            return (string) $subject->name;
        }

        return $audit->subject_type.' #'.$audit->subject_id;
    }

    /**
     * @param  Collection<int, ActivityRow>  $rows
     */
    private function csv(Collection $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, [
            __('ops.activity.columns.when'),
            __('ops.activity.columns.kind'),
            __('ops.activity.columns.outcome'),
            __('ops.activity.columns.actor'),
            __('ops.activity.columns.site'),
            __('ops.activity.columns.what'),
            __('ops.activity.export.detail'),
        ]);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row->occurredAt->timezone((string) config('app.timezone'))->toIso8601String(),
                $row->kindLabel(),
                $row->outcomeLabel(),
                $row->actorName(),
                (string) ($row->site?->name ?? ''),
                $row->title,
                $row->detail,
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }

    private function pretty(mixed $value): ?string
    {
        if ($value === null || $value === [] || $value === '') {
            return null;
        }

        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : null;
    }
}
