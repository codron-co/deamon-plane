<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\CoolifyConnection;
use App\Models\CoolifyEnvironment;
use App\Models\CoolifyGitSource;
use App\Models\CoolifyProjectRecord;
use App\Models\CoolifyServer;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

class CoolifyInventoryController extends Controller
{
    public function showServer(CoolifyConnection $connection, CoolifyServer $server): View
    {
        $this->authorize('view', $connection);
        $this->assertChild($connection, $server->coolify_connection_id);

        return view('ops.coolify.inventory-show', [
            'connection' => $connection,
            'canWrite' => $this->canWrite($connection),
            'kind' => 'server',
            'title' => $server->label(),
            'kindLabel' => __('coolify.detail.server'),
            'statusActive' => $server->is_active,
            'isDefault' => $connection->default_server_uuid === $server->uuid,
            'facts' => [
                [
                    'label' => __('coolify.detail.uuid'),
                    'value' => $server->uuid,
                    'code' => true,
                ],
                [
                    'label' => __('coolify.allowlist.ip'),
                    'value' => $server->ip ?: __('ops.none'),
                    'code' => filled($server->ip),
                ],
                [
                    'label' => __('coolify.detail.connection'),
                    'value' => $connection->name,
                    'href' => route('ops.coolify.show', $connection),
                ],
            ],
            'sites' => $this->sitesFor($connection, 'coolify_server_uuid', $server->uuid),
            'environments' => collect(),
            'toggleRoute' => 'ops.coolify.servers.toggle',
            'toggleParam' => 'server',
            'record' => $server,
        ]);
    }

    public function showProject(CoolifyConnection $connection, CoolifyProjectRecord $project): View
    {
        $this->authorize('view', $connection);
        $this->assertChild($connection, $project->coolify_connection_id);
        $connection->loadMissing('environments');

        $environments = $connection->environments
            ->where('project_uuid', $project->uuid)
            ->sortBy('name')
            ->values();

        return view('ops.coolify.inventory-show', [
            'connection' => $connection,
            'canWrite' => $this->canWrite($connection),
            'kind' => 'project',
            'title' => $project->label(),
            'kindLabel' => __('coolify.detail.project'),
            'statusActive' => $project->is_active,
            'isDefault' => $connection->default_project_uuid === $project->uuid,
            'facts' => [
                [
                    'label' => __('coolify.detail.uuid'),
                    'value' => $project->uuid,
                    'code' => true,
                ],
                [
                    'label' => __('coolify.detail.environments_count'),
                    'value' => (string) $environments->count(),
                ],
                [
                    'label' => __('coolify.detail.connection'),
                    'value' => $connection->name,
                    'href' => route('ops.coolify.show', $connection),
                ],
            ],
            'sites' => $this->sitesFor($connection, 'coolify_project_uuid', $project->uuid),
            'environments' => $environments,
            'toggleRoute' => 'ops.coolify.projects.toggle',
            'toggleParam' => 'project',
            'record' => $project,
        ]);
    }

    public function showEnvironment(CoolifyConnection $connection, CoolifyEnvironment $environment): View
    {
        $this->authorize('view', $connection);
        $this->assertChild($connection, $environment->coolify_connection_id);
        $connection->loadMissing('projects');

        $project = $connection->projects->firstWhere('uuid', $environment->project_uuid);

        $projectFact = [
            'label' => __('coolify.detail.project'),
            'value' => $project?->label() ?: ($environment->project_uuid ?: __('ops.none')),
        ];
        if ($project) {
            $projectFact['href'] = route('ops.coolify.projects.show', [$connection, $project]);
        } elseif (filled($environment->project_uuid)) {
            $projectFact['code'] = true;
            $projectFact['value'] = $environment->project_uuid;
        }

        return view('ops.coolify.inventory-show', [
            'connection' => $connection,
            'canWrite' => $this->canWrite($connection),
            'kind' => 'environment',
            'title' => $environment->label(),
            'kindLabel' => __('coolify.detail.environment'),
            'statusActive' => $environment->is_active,
            'isDefault' => $connection->default_environment_uuid === $environment->uuid,
            'facts' => [
                [
                    'label' => __('coolify.detail.uuid'),
                    'value' => $environment->uuid,
                    'code' => true,
                ],
                $projectFact,
                [
                    'label' => __('coolify.detail.connection'),
                    'value' => $connection->name,
                    'href' => route('ops.coolify.show', $connection),
                ],
            ],
            'sites' => $this->sitesFor($connection, 'coolify_environment_uuid', $environment->uuid),
            'environments' => collect(),
            'toggleRoute' => 'ops.coolify.environments.toggle',
            'toggleParam' => 'environment',
            'record' => $environment,
        ]);
    }

    public function showGitSource(CoolifyConnection $connection, CoolifyGitSource $source): View
    {
        $this->authorize('view', $connection);
        $this->assertChild($connection, $source->coolify_connection_id);

        $kindValue = $source->kind?->value;
        $kindLabel = $kindValue
            ? __('coolify.kinds.'.$kindValue)
            : __('ops.none');

        return view('ops.coolify.inventory-show', [
            'connection' => $connection,
            'canWrite' => $this->canWrite($connection),
            'kind' => 'git',
            'title' => $source->label(),
            'kindLabel' => __('coolify.detail.git'),
            'statusActive' => $source->is_active,
            'isDefault' => $connection->default_git_source_uuid === $source->uuid,
            'facts' => [
                [
                    'label' => __('coolify.detail.uuid'),
                    'value' => $source->uuid,
                    'code' => true,
                ],
                [
                    'label' => __('coolify.allowlist.kind'),
                    'value' => $kindLabel,
                ],
                [
                    'label' => __('coolify.detail.connection'),
                    'value' => $connection->name,
                    'href' => route('ops.coolify.show', $connection),
                ],
            ],
            'sites' => $this->sitesFor(
                $connection,
                'coolify_git_source_uuid',
                $source->uuid,
                'coolify_git_source_kind',
                $source->kind?->value,
            ),
            'environments' => collect(),
            'toggleRoute' => 'ops.coolify.git-sources.toggle',
            'toggleParam' => 'source',
            'record' => $source,
        ]);
    }

    private function canWrite(CoolifyConnection $connection): bool
    {
        return request()->user()?->can('update', $connection) ?? false;
    }

    /**
     * @return Collection<int, Site>
     */
    private function sitesFor(
        CoolifyConnection $connection,
        string $column,
        string $uuid,
        ?string $kindColumn = null,
        ?string $kindValue = null,
    ): Collection {
        return Site::query()
            ->where($column, $uuid)
            ->where(function ($query) use ($connection): void {
                $query->where('coolify_connection_id', $connection->id);
                if ($connection->is_default) {
                    $query->orWhereNull('coolify_connection_id');
                }
            })
            ->when($kindColumn !== null && $kindValue !== null, function ($query) use ($kindColumn, $kindValue): void {
                $query->where(function ($inner) use ($kindColumn, $kindValue): void {
                    $inner->where($kindColumn, $kindValue)->orWhereNull($kindColumn);
                });
            })
            ->orderBy('name')
            ->get();
    }

    private function assertChild(CoolifyConnection $connection, ?int $childConnectionId): void
    {
        if ((int) $childConnectionId !== (int) $connection->id) {
            abort(404);
        }
    }
}
