<?php

namespace App\Services\Coolify;

use App\Enums\CoolifyGitSourceKind;
use App\Models\CoolifyConnection;
use App\Models\CoolifyEnvironment;
use App\Models\CoolifyGitSource;
use App\Models\CoolifyProjectRecord;
use App\Models\CoolifyServer;
use App\Services\Coolify\Dto\CoolifyGitSource as CoolifyGitSourceDto;
use App\Services\Coolify\Dto\CoolifyProject;
use App\Services\Coolify\Dto\CoolifyProjectEnvironment;
use App\Services\Coolify\Dto\CoolifyServer as CoolifyServerDto;
use Illuminate\Support\Facades\DB;

class CoolifyInventorySync
{
    /**
     * @return array{servers: int, projects: int, environments: int, git_sources: int, github_apps_available: bool}
     */
    public function sync(CoolifyConnection $connection): array
    {
        $coolify = CoolifyApplicationService::forConnection($connection);

        $servers = $coolify->listServers();
        $projects = $coolify->listProjects();
        $githubApps = $coolify->listGithubApps();
        $privateKeys = $coolify->listPrivateKeys();

        $githubAvailable = $githubApps !== null;
        $gitSources = collect($githubApps ?? [])->concat($privateKeys);

        $environmentCount = 0;

        DB::transaction(function () use ($connection, $servers, $projects, $gitSources, $githubAvailable, $coolify, &$environmentCount): void {
            $seenServers = [];
            foreach ($servers as $server) {
                if (! $server instanceof CoolifyServerDto || $server->uuid === '') {
                    continue;
                }

                $seenServers[] = $server->uuid;
                CoolifyServer::query()->updateOrCreate(
                    [
                        'coolify_connection_id' => $connection->id,
                        'uuid' => $server->uuid,
                    ],
                    ['name' => $server->name !== '' ? $server->name : $server->uuid],
                );
            }

            $seenProjects = [];
            $seenEnvironments = [];

            foreach ($projects as $project) {
                if (! $project instanceof CoolifyProject || $project->uuid === '') {
                    continue;
                }

                $seenProjects[] = $project->uuid;
                CoolifyProjectRecord::query()->updateOrCreate(
                    [
                        'coolify_connection_id' => $connection->id,
                        'uuid' => $project->uuid,
                    ],
                    ['name' => $project->name !== '' ? $project->name : $project->uuid],
                );

                foreach ($coolify->listEnvironments($project->uuid) as $environment) {
                    if (! $environment instanceof CoolifyProjectEnvironment || $environment->uuid === '') {
                        continue;
                    }

                    $seenEnvironments[] = $project->uuid."\0".$environment->uuid;
                    CoolifyEnvironment::query()->updateOrCreate(
                        [
                            'coolify_connection_id' => $connection->id,
                            'project_uuid' => $project->uuid,
                            'uuid' => $environment->uuid,
                        ],
                        ['name' => $environment->name !== '' ? $environment->name : $environment->uuid],
                    );
                    $environmentCount++;
                }
            }

            $seenGit = [];
            foreach ($gitSources as $source) {
                if (! $source instanceof CoolifyGitSourceDto || $source->uuid === '') {
                    continue;
                }

                $seenGit[] = $source->kind->value."\0".$source->uuid;
                CoolifyGitSource::query()->updateOrCreate(
                    [
                        'coolify_connection_id' => $connection->id,
                        'kind' => $source->kind,
                        'uuid' => $source->uuid,
                    ],
                    ['name' => $source->name !== '' ? $source->name : $source->uuid],
                );
            }

            CoolifyServer::query()
                ->where('coolify_connection_id', $connection->id)
                ->whereNotIn('uuid', $seenServers === [] ? [''] : $seenServers)
                ->delete();

            CoolifyProjectRecord::query()
                ->where('coolify_connection_id', $connection->id)
                ->whereNotIn('uuid', $seenProjects === [] ? [''] : $seenProjects)
                ->delete();

            $staleEnvironments = CoolifyEnvironment::query()
                ->where('coolify_connection_id', $connection->id)
                ->get()
                ->filter(static fn (CoolifyEnvironment $row): bool => ! in_array($row->project_uuid."\0".$row->uuid, $seenEnvironments, true));
            $staleEnvironments->each->delete();

            $staleGit = CoolifyGitSource::query()
                ->where('coolify_connection_id', $connection->id)
                ->get()
                ->filter(static function (CoolifyGitSource $row) use ($seenGit): bool {
                    $kind = $row->kind instanceof CoolifyGitSourceKind ? $row->kind->value : (string) $row->kind;

                    return ! in_array($kind."\0".$row->uuid, $seenGit, true);
                });
            $staleGit->each->delete();

            $connection->forceFill([
                'github_apps_list_available' => $githubAvailable,
                'last_synced_at' => now(),
            ])->save();
        });

        return [
            'servers' => $servers->count(),
            'projects' => $projects->count(),
            'environments' => $environmentCount,
            'git_sources' => $gitSources->count(),
            'github_apps_available' => $githubAvailable,
        ];
    }
}
