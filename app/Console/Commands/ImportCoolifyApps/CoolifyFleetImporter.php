<?php

namespace App\Console\Commands\ImportCoolifyApps;

use App\Enums\Channel;
use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Coolify\Dto\CoolifyApplication;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CoolifyFleetImporter
{
    public function __construct(
        private readonly CoolifyFleetClassifier $classifier,
    ) {}

    /**
     * @param  Collection<int, CoolifyApplication>  $apps
     * @return Collection<int, ImportPlanRow>
     */
    public function plan(Collection $apps): Collection
    {
        return $apps->map(fn (CoolifyApplication $app): ImportPlanRow => $this->planApp($app))->values();
    }

    /**
     * @param  Collection<int, ImportPlanRow>  $plan
     * @return array{created: int, updated: int, skipped: int}
     */
    public function apply(Collection $plan): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($plan as $row) {
            if (! $row->willWrite()) {
                $skipped++;

                continue;
            }

            DB::transaction(function () use ($row, &$created, &$updated): void {
                if ($row->action === ImportPlanRow::ACTION_CREATE) {
                    $this->createSite($row);
                    $created++;

                    return;
                }

                $this->updateSite($row);
                $updated++;
            });
        }

        Log::info('Coolify fleet import applied', [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ]);

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    public function planApp(CoolifyApplication $app): ImportPlanRow
    {
        $reasons = $this->classifier->skipReasons($app);
        $host = $this->classifier->primaryHost($app);
        $repoDisplay = $this->classifier->displayRepository($app->gitRepository);
        $branch = (string) ($app->gitBranch ?? '');
        $pack = (string) ($app->buildPack ?? '');
        $dockerfile = $this->classifier->isDockerfile($app);
        $needsReview = $this->classifier->needsReview($app->gitBranch);
        $flags = [];

        if ($dockerfile) {
            $flags[] = 'dockerfile';
        }

        if ($needsReview) {
            $flags[] = 'needs_review';
        }

        if ($reasons !== []) {
            return $this->skipRow(
                $app,
                $host,
                $repoDisplay,
                implode('; ', $reasons),
                $flags,
                $dockerfile,
                $needsReview,
            );
        }

        $existing = $this->findExisting($app->uuid, $host);

        if ($existing instanceof Site && $this->isConflict($existing, $app->uuid, $host)) {
            return $this->skipRow(
                $app,
                $host,
                $repoDisplay,
                'uuid/domain maps to different existing sites',
                $flags,
                $dockerfile,
                $needsReview,
            );
        }

        if ($existing === null && $this->trashedOccupies($app->uuid, $host)) {
            return $this->skipRow(
                $app,
                $host,
                $repoDisplay,
                'soft-deleted site occupies this uuid or domain',
                $flags,
                $dockerfile,
                $needsReview,
            );
        }

        if ($existing === null && $host === null) {
            return $this->skipRow(
                $app,
                null,
                $repoDisplay,
                'missing fqdn and app.domain',
                $flags,
                $dockerfile,
                $needsReview,
            );
        }

        $channel = $this->classifier->resolveChannel($app->gitBranch);
        $status = $this->classifier->resolveStatus($app->status);
        $note = $this->noteFor($dockerfile, $needsReview, $branch, $existing === null ? 'create' : 'update');

        return new ImportPlanRow(
            uuid: $app->uuid,
            name: $app->name !== '' ? $app->name : ($host ?? $app->uuid),
            repo: $repoDisplay,
            branch: $branch !== '' ? $branch : '(none)',
            pack: $pack !== '' ? $pack : '(none)',
            domain: $host ?? '',
            action: $existing === null ? ImportPlanRow::ACTION_CREATE : ImportPlanRow::ACTION_UPDATE,
            note: $note,
            flags: $flags,
            channel: $channel,
            status: $status,
            host: $host,
            slug: $existing?->slug ?? $this->classifier->slugFrom((string) $host, $app->name),
            serverUuid: $this->classifier->serverUuid($app),
            gitRepository: $app->gitRepository,
            existing: $existing,
            dockerfileWarning: $dockerfile,
            needsReview: $needsReview,
        );
    }

    private function createSite(ImportPlanRow $row): Site
    {
        $host = (string) $row->host;
        $slug = $this->uniqueSlug((string) $row->slug);

        $site = Site::query()->create([
            'slug' => $slug,
            'name' => $row->name,
            'primary_domain' => $host,
            'channel' => $row->channel ?? Channel::Main,
            'status' => $row->status ?? SiteStatus::Draft,
            'coolify_app_uuid' => $row->uuid,
            'coolify_server_uuid' => $row->serverUuid,
            'git_repository' => $row->gitRepository,
            'agent_base_url' => 'https://'.$host,
            'notes' => $this->mergeNotes(null, $row),
        ]);

        $this->syncPrimaryDomain($site, $host);

        $site->auditLogs()->create([
            'action' => 'site.imported',
            'after' => $this->auditSnapshot($site->fresh() ?? $site),
        ]);

        return $site;
    }

    private function updateSite(ImportPlanRow $row): Site
    {
        /** @var Site $site */
        $site = Site::query()->lockForUpdate()->findOrFail($row->existing?->id);

        $before = $this->auditSnapshot($site);
        $host = $row->host ?? $site->primary_domain;

        $site->name = $row->name;
        $site->coolify_app_uuid = $row->uuid;
        $site->git_repository = $row->gitRepository ?: $site->git_repository;

        if (filled($row->serverUuid)) {
            $site->coolify_server_uuid = $row->serverUuid;
        }

        if (filled($host)) {
            $site->primary_domain = $host;
        }

        if ($row->channel instanceof Channel && ! $row->needsReview) {
            $site->channel = $row->channel;
        }

        if (! in_array($site->status, [SiteStatus::Provisioning, SiteStatus::Deploying], true)
            && $row->status instanceof SiteStatus) {
            $site->status = $row->status;
        }

        if (blank($site->agent_base_url) && filled($host)) {
            $site->agent_base_url = preg_match('#^https?://#i', $host) === 1
                ? $host
                : 'https://'.$host;
        }

        $site->notes = $this->mergeNotes($site->notes, $row);
        $site->save();

        if (filled($host)) {
            $this->syncPrimaryDomain($site, $host);
        }

        $site->auditLogs()->create([
            'action' => 'site.import_updated',
            'before' => $before,
            'after' => $this->auditSnapshot($site->fresh() ?? $site),
        ]);

        return $site;
    }

    private function findExisting(string $uuid, ?string $host): ?Site
    {
        if ($uuid !== '') {
            $byUuid = Site::query()->where('coolify_app_uuid', $uuid)->first();
            if ($byUuid instanceof Site) {
                return $byUuid;
            }
        }

        if ($host === null || $host === '') {
            return null;
        }

        $byPrimary = Site::query()->where('primary_domain', $host)->first();
        if ($byPrimary instanceof Site) {
            return $byPrimary;
        }

        $domain = SiteDomain::query()->where('domain', $host)->first();

        return $domain?->site;
    }

    private function isConflict(Site $existing, string $uuid, ?string $host): bool
    {
        if (filled($existing->coolify_app_uuid) && $existing->coolify_app_uuid !== $uuid) {
            return true;
        }

        if ($host === null || $host === '') {
            return false;
        }

        $byDomain = Site::query()->where('primary_domain', $host)->first()
            ?? SiteDomain::query()->where('domain', $host)->first()?->site;

        if (! $byDomain instanceof Site || $byDomain->is($existing)) {
            return false;
        }

        return true;
    }

    private function trashedOccupies(string $uuid, ?string $host): bool
    {
        return Site::onlyTrashed()
            ->where(function ($query) use ($uuid, $host): void {
                if ($uuid !== '') {
                    $query->where('coolify_app_uuid', $uuid);
                }

                if ($host !== null && $host !== '') {
                    $query->orWhere('primary_domain', $host);
                }
            })
            ->exists();
    }

    /**
     * @param  list<string>  $flags
     */
    private function skipRow(
        CoolifyApplication $app,
        ?string $host,
        string $repoDisplay,
        string $note,
        array $flags,
        bool $dockerfile,
        bool $needsReview,
    ): ImportPlanRow {
        return new ImportPlanRow(
            uuid: $app->uuid !== '' ? $app->uuid : '(none)',
            name: $app->name,
            repo: $repoDisplay,
            branch: (string) ($app->gitBranch ?? ''),
            pack: (string) ($app->buildPack ?? ''),
            domain: $host ?? '',
            action: ImportPlanRow::ACTION_SKIP,
            note: $note,
            flags: $flags,
            host: $host,
            dockerfileWarning: $dockerfile,
            needsReview: $needsReview,
        );
    }

    private function uniqueSlug(string $base): string
    {
        $slug = $base !== '' ? $base : 'site';
        $candidate = $slug;
        $i = 2;

        while (Site::withTrashed()->where('slug', $candidate)->exists()) {
            $candidate = substr($slug, 0, 60).'-'.$i;
            $i++;
        }

        return $candidate;
    }

    private function syncPrimaryDomain(Site $site, string $domain): void
    {
        $primary = $site->primaryDomainRecord;

        if ($primary === null) {
            $site->domains()->create([
                'domain' => $domain,
                'is_primary' => true,
            ]);

            $site->unsetRelation('primaryDomainRecord');

            return;
        }

        if ($primary->domain !== $domain) {
            $primary->update(['domain' => $domain]);
        }
    }

    private function mergeNotes(?string $existing, ImportPlanRow $row): ?string
    {
        $lines = [];

        if ($row->dockerfileWarning) {
            $lines[] = '[import] dockerfile_build_pack: Coolify build_pack is dockerfile (compose preferred)';
        }

        if ($row->needsReview) {
            $lines[] = '[import] needs_review: git_branch '.$row->branch.' is not in main|beta|alpha; channel stored as main';
        }

        if ($lines === []) {
            return $existing !== null && trim($existing) !== '' ? $existing : null;
        }

        $current = (string) $existing;
        foreach ($lines as $line) {
            if (! str_contains($current, $line)) {
                $current = trim($current) === '' ? $line : trim($current)."\n".$line;
            }
        }

        return $current;
    }

    private function noteFor(bool $dockerfile, bool $needsReview, string $branch, string $action): string
    {
        $bits = [];

        if ($dockerfile) {
            $bits[] = 'dockerfile warning';
        }

        if ($needsReview) {
            $bits[] = 'needs_review ('.($branch !== '' ? $branch : 'empty').')';
        }

        return $bits === [] ? $action : implode('; ', $bits);
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(Site $site): array
    {
        return [
            'slug' => $site->slug,
            'name' => $site->name,
            'primary_domain' => $site->primary_domain,
            'channel' => $site->channel instanceof Channel ? $site->channel->value : $site->channel,
            'status' => $site->status instanceof SiteStatus ? $site->status->value : $site->status,
            'coolify_app_uuid' => $site->coolify_app_uuid,
            'coolify_server_uuid' => $site->coolify_server_uuid,
        ];
    }
}
