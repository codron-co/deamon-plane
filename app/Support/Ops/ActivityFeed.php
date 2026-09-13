<?php

namespace App\Support\Ops;

use App\Enums\DeploymentStatus;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\OpsBackgroundJob;
use App\Models\Site;
use App\Support\SecretRedactor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reverse-chronological merge of ops jobs, deployments and audit rows.
 *
 * The widget at GET /jobs is personal (actor = me, last two hours). This feed
 * is a fleet log: every ops role that can see Sites can read every row. Payloads
 * are redacted before they leave the server.
 */
class ActivityFeed
{
    public const KINDS = ['job', 'deployment', 'audit'];

    public const OUTCOMES = ['ok', 'failed', 'cancelled', 'running', 'queued'];

    public const PER_PAGE = 25;

    public const EXPORT_MAX = 1000;

    /**
     * @return LengthAwarePaginator<int, ActivityRow>
     */
    public function paginate(ActivityFilters $filters): LengthAwarePaginator
    {
        $page = DB::query()
            ->fromSub($this->indexQuery($filters), 'activity')
            ->orderBy('occurred_at', $filters->sortDirection)
            ->orderBy('record_id', $filters->sortDirection)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        /** @var LengthAwarePaginator<int, ActivityRow> $page */
        $page->setCollection($this->hydrate($page->getCollection()));

        return $page;
    }

    public function unfilteredTotal(): int
    {
        return OpsBackgroundJob::query()->count()
            + Deployment::query()->count()
            + AuditLog::query()->count();
    }

    /**
     * Same UNION and filters as the page, hard-capped. Payloads stay out of
     * the CSV — hydrate already redacts title/detail.
     *
     * @return Collection<int, ActivityRow>
     */
    public function export(ActivityFilters $filters, ?int $limit = null): Collection
    {
        $max = max(1, (int) config('ops.activity.export_limit', self::EXPORT_MAX));
        $limit = $limit === null ? $max : max(1, min($limit, $max));

        $items = DB::query()
            ->fromSub($this->indexQuery($filters), 'activity')
            ->orderBy('occurred_at', $filters->sortDirection)
            ->orderBy('record_id', $filters->sortDirection)
            ->limit($limit)
            ->get();

        return $this->hydrate($items);
    }

    public static function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match('/secret|password|token|api_key|app_key/i', $key)) {
                    $out[$key] = '[redacted]';

                    continue;
                }
                $out[$key] = self::redact($item);
            }

            return $out;
        }

        if (is_string($value)) {
            return SecretRedactor::redactSensitive($value);
        }

        return $value;
    }

    public static function redactText(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        return SecretRedactor::redactSensitive($text);
    }

    private function indexQuery(ActivityFilters $filters): Builder
    {
        $parts = [];
        if ($filters->kind === '' || $filters->kind === 'job') {
            $parts[] = $this->jobIndex($filters);
        }
        if ($filters->kind === '' || $filters->kind === 'deployment') {
            $parts[] = $this->deploymentIndex($filters);
        }
        if ($filters->kind === '' || $filters->kind === 'audit') {
            $parts[] = $this->auditIndex($filters);
        }

        $first = array_shift($parts);
        foreach ($parts as $part) {
            $first->unionAll($part);
        }

        return $first;
    }

    private function jobIndex(ActivityFilters $filters): Builder
    {
        $query = DB::table('ops_background_jobs')
            ->selectRaw('CAST(id AS CHAR) as record_id')
            ->selectRaw("'job' as kind")
            ->selectRaw('updated_at as occurred_at')
            ->selectRaw('actor_user_id as actor_id')
            ->selectRaw('CAST(NULL AS CHAR) as site_id')
            ->selectRaw($this->jobOutcomeSql().' as outcome')
            ->selectRaw('title as summary');

        if ($filters->search !== '') {
            $term = addcslashes($filters->search, '%_\\');
            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('title', 'like', "%{$term}%")
                    ->orWhere('type', 'like', "%{$term}%")
                    ->orWhere('message', 'like', "%{$term}%");
            });
        }

        if ($filters->outcome !== '') {
            $query->whereRaw($this->jobOutcomeSql().' = ?', [$filters->outcome]);
        }

        if ($filters->actorId !== '') {
            $query->where('actor_user_id', (int) $filters->actorId);
        }

        if ($filters->siteId !== '') {
            $query->where('payload', 'like', '%'.addcslashes($filters->siteId, '%_\\').'%');
        }

        return $query;
    }

    private function deploymentIndex(ActivityFilters $filters): Builder
    {
        $query = DB::table('deployments')
            ->leftJoin('sites', 'sites.id', '=', 'deployments.site_id')
            ->selectRaw(
                "CAST(deployments.id AS CHAR) as record_id, 'deployment' as kind, COALESCE(deployments.finished_at, deployments.started_at, deployments.created_at) as occurred_at, deployments.requested_by as actor_id, CAST(deployments.site_id AS CHAR) as site_id, ".$this->deploymentOutcomeSql().' as outcome, sites.name as summary'
            );

        if ($filters->search !== '') {
            $term = addcslashes($filters->search, '%_\\');
            $search = $filters->search;
            /*
             * Same identity the Sites list and palette already search: name / slug /
             * primary, plus alias / www / preview hosts on site_domains and an exact
             * Coolify app uuid paste. A join on site_domains would duplicate deploys.
             */
            $query->where(function (Builder $builder) use ($term, $search): void {
                $builder->where('sites.name', 'like', "%{$term}%")
                    ->orWhere('sites.slug', 'like', "%{$term}%")
                    ->orWhere('sites.primary_domain', 'like', "%{$term}%")
                    ->orWhere('sites.coolify_app_uuid', $search)
                    ->orWhere('deployments.error_message', 'like', "%{$term}%")
                    ->orWhereExists(function (Builder $domains) use ($term): void {
                        $domains->selectRaw('1')
                            ->from('site_domains')
                            ->whereColumn('site_domains.site_id', 'sites.id')
                            ->where('site_domains.domain', 'like', "%{$term}%");
                    });
            });
        }

        if ($filters->outcome !== '') {
            $query->whereRaw($this->deploymentOutcomeSql().' = ?', [$filters->outcome]);
        }

        if ($filters->actorId !== '') {
            $query->where('deployments.requested_by', (int) $filters->actorId);
        }

        if ($filters->siteId !== '') {
            $query->where('deployments.site_id', $filters->siteId);
        }

        return $query;
    }

    private function auditIndex(ActivityFilters $filters): Builder
    {
        $query = AuditLog::query()
            ->selectRaw(
                "CAST(id AS CHAR) as record_id, 'audit' as kind, created_at as occurred_at, actor_user_id as actor_id, CASE WHEN subject_type = '".addslashes(Site::class)."' THEN subject_id ELSE CAST(NULL AS CHAR) END as site_id, CASE WHEN action LIKE '%failed%' THEN 'failed' ELSE 'ok' END as outcome, action as summary"
            )
            ->toBase();

        if ($filters->search !== '') {
            $term = addcslashes($filters->search, '%_\\');
            $query->where('action', 'like', "%{$term}%");
        }

        if ($filters->outcome !== '') {
            $query->whereRaw("CASE WHEN action LIKE '%failed%' THEN 'failed' ELSE 'ok' END = ?", [$filters->outcome]);
        }

        if ($filters->actorId !== '') {
            $query->where('actor_user_id', (int) $filters->actorId);
        }

        if ($filters->siteId !== '') {
            $query->where('subject_type', Site::class)->where('subject_id', $filters->siteId);
        }

        return $query;
    }

    private function jobOutcomeSql(): string
    {
        return "CASE status WHEN 'completed' THEN 'ok' WHEN 'failed' THEN 'failed' WHEN 'cancelled' THEN 'cancelled' WHEN 'running' THEN 'running' ELSE 'queued' END";
    }

    private function deploymentOutcomeSql(): string
    {
        return "CASE deployments.status WHEN '".DeploymentStatus::Finished->value."' THEN 'ok' WHEN '".DeploymentStatus::Failed->value."' THEN 'failed' WHEN '".DeploymentStatus::Cancelled->value."' THEN 'cancelled' WHEN '".DeploymentStatus::InProgress->value."' THEN 'running' ELSE 'queued' END";
    }

    /**
     * @param  Collection<int, object>  $items
     * @return Collection<int, ActivityRow>
     */
    private function hydrate(Collection $items): Collection
    {
        $jobIds = $items->where('kind', 'job')->pluck('record_id')->all();
        $deploymentIds = $items->where('kind', 'deployment')->pluck('record_id')->all();
        $auditIds = $items->where('kind', 'audit')->pluck('record_id')->all();

        $jobs = $jobIds === []
            ? collect()
            : OpsBackgroundJob::query()->whereIn('id', $jobIds)->with('actor')->get()->keyBy('id');
        $deployments = $deploymentIds === []
            ? collect()
            : Deployment::query()->whereIn('id', $deploymentIds)->with(['site', 'requestedBy'])->get()->keyBy('id');
        $audits = $auditIds === []
            ? collect()
            : AuditLog::query()->whereIn('id', $auditIds)->with('actor')->get()->keyBy('id');

        $siteIds = $items->pluck('site_id')->filter()->unique()->values();
        foreach ($jobs as $job) {
            foreach ((array) ($job->payload['site_ids'] ?? []) as $payloadSiteId) {
                if (is_string($payloadSiteId) && $payloadSiteId !== '') {
                    $siteIds->push($payloadSiteId);
                }
            }
        }
        $sites = $siteIds->isEmpty()
            ? collect()
            : Site::query()->whereIn('id', $siteIds->unique()->all())->get()->keyBy('id');

        return $items->map(function (object $item) use ($jobs, $deployments, $audits, $sites): ?ActivityRow {
            return match ((string) $item->kind) {
                'job' => $this->rowFromJob($item, $jobs->get((string) $item->record_id), $sites),
                'deployment' => $this->rowFromDeployment($item, $deployments->get((int) $item->record_id), $sites),
                'audit' => $this->rowFromAudit($item, $audits->get((int) $item->record_id), $sites),
                default => null,
            };
        })->filter()->values();
    }

    /**
     * @param  Collection<string, Site>  $sites
     */
    private function rowFromJob(object $item, ?OpsBackgroundJob $job, Collection $sites): ActivityRow
    {
        $payloadSiteId = is_array($job?->payload) ? (string) (($job->payload['site_ids'][0] ?? '')) : '';
        $site = $payloadSiteId !== '' ? $sites->get($payloadSiteId) : null;
        $kind = $job?->kindLabel() ?? (string) __('ops.activity.kinds.job');
        $subject = $job?->subjectLabel() ?? (string) ($item->summary ?? '');
        $title = $subject !== '' ? $kind.' · '.$subject : $kind;
        $detail = self::redactText($job?->message);

        return new ActivityRow(
            'job',
            (string) $item->record_id,
            $this->occurredAt($item),
            $job?->actor,
            $site,
            $title,
            $detail,
            (string) $item->outcome,
            route('ops.activity.jobs.show', $item->record_id),
        );
    }

    /**
     * @param  Collection<string, Site>  $sites
     */
    private function rowFromDeployment(object $item, ?Deployment $deployment, Collection $sites): ActivityRow
    {
        $site = $deployment?->site ?? $sites->get((string) $item->site_id);
        $name = $site?->name ?? (string) ($item->summary ?? '');
        $kind = (string) __('ops.jobs.deployment');
        $title = $name !== '' ? $kind.' · '.$name : $kind;
        $detail = self::redactText($deployment?->error_message);
        $url = $site instanceof Site && $deployment instanceof Deployment
            ? route('ops.sites.deployments.show', [$site, $deployment])
            : '';

        return new ActivityRow(
            'deployment',
            (string) $item->record_id,
            $this->occurredAt($item),
            $deployment?->requestedBy,
            $site,
            $title,
            $detail,
            (string) $item->outcome,
            $url,
        );
    }

    /**
     * @param  Collection<string, Site>  $sites
     */
    private function rowFromAudit(object $item, ?AuditLog $audit, Collection $sites): ActivityRow
    {
        $site = $sites->get((string) $item->site_id);
        $label = $audit?->actionLabel() ?? (string) ($item->summary ?? '');
        $name = $site?->name ?? '';
        $title = $name !== '' ? $label.' · '.$name : $label;

        return new ActivityRow(
            'audit',
            (string) $item->record_id,
            $this->occurredAt($item),
            $audit?->actor,
            $site,
            $title,
            '',
            (string) $item->outcome,
            route('ops.activity.audits.show', $item->record_id),
        );
    }

    private function occurredAt(object $item): Carbon
    {
        $raw = $item->occurred_at ?? now();

        return $raw instanceof Carbon
            ? $raw
            : Carbon::parse((string) $raw);
    }
}
