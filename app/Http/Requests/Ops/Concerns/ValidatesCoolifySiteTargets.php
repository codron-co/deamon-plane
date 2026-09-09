<?php

namespace App\Http\Requests\Ops\Concerns;

use App\Enums\CoolifyGitSourceKind;
use App\Enums\OpsRole;
use App\Models\CoolifyConnection;
use App\Models\CoolifyEnvironment;
use App\Models\CoolifyGitSource;
use App\Models\CoolifyProjectRecord;
use App\Models\CoolifyServer;
use Illuminate\Validation\Validator;

trait ValidatesCoolifySiteTargets
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function coolifyTargetRules(): array
    {
        return [
            'coolify_connection_id' => ['nullable', 'integer', 'exists:coolify_connections,id'],
            'coolify_server_uuid' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'coolify_project_uuid' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'coolify_environment_uuid' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'coolify_git_source' => ['nullable', 'string', 'max:96'],
            'attach_app_uuid' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'placement' => ['nullable', 'string', 'in:provision,attach'],
            'advanced_server_uuid' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'advanced_project_uuid' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'advanced_environment_uuid' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'advanced_git_source' => ['nullable', 'string', 'max:96'],
        ];
    }

    protected function applyAdvancedOverrides(): void
    {
        if (! $this->user()?->hasRole(OpsRole::SuperAdmin->value)) {
            return;
        }

        foreach ([
            'coolify_server_uuid' => 'advanced_server_uuid',
            'coolify_project_uuid' => 'advanced_project_uuid',
            'coolify_environment_uuid' => 'advanced_environment_uuid',
            'coolify_git_source' => 'advanced_git_source',
        ] as $target => $advanced) {
            $value = trim((string) $this->input($advanced));
            if ($value !== '') {
                $this->merge([$target => $value]);
            }
        }
    }

    public function withCoolifyTargetValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $connectionId = $this->input('coolify_connection_id');
            if (! filled($connectionId)) {
                return;
            }

            $connection = CoolifyConnection::query()->find($connectionId);
            if (! $connection instanceof CoolifyConnection) {
                return;
            }

            $superAdmin = $this->user()?->hasRole(OpsRole::SuperAdmin->value) ?? false;
            $usedAdvanced = $superAdmin && collect([
                $this->input('advanced_server_uuid'),
                $this->input('advanced_project_uuid'),
                $this->input('advanced_environment_uuid'),
                $this->input('advanced_git_source'),
            ])->contains(fn (mixed $value): bool => filled($value));

            if ($usedAdvanced) {
                return;
            }

            $server = $this->input('coolify_server_uuid');
            if (filled($server) && ! $this->activeExists(CoolifyServer::class, $connection, (string) $server)) {
                $validator->errors()->add('coolify_server_uuid', 'Sunucu, bu bağlantının aktif listesinde olmalı. UUID’yi elle yazmayın.');
            }

            $project = $this->input('coolify_project_uuid');
            if (filled($project) && ! $this->activeExists(CoolifyProjectRecord::class, $connection, (string) $project)) {
                $validator->errors()->add('coolify_project_uuid', 'Proje, bu bağlantının aktif listesinde olmalı.');
            }

            $environment = $this->input('coolify_environment_uuid');
            if (filled($environment) && ! CoolifyEnvironment::query()
                ->where('coolify_connection_id', $connection->id)
                ->where('uuid', $environment)
                ->where('is_active', true)
                ->exists()) {
                $validator->errors()->add('coolify_environment_uuid', 'Ortam, bu bağlantının aktif listesinde olmalı.');
            }

            $git = $this->input('coolify_git_source');
            if (filled($git) && is_string($git) && str_contains($git, ':')) {
                [$kind, $uuid] = explode(':', $git, 2);
                $enum = CoolifyGitSourceKind::tryFrom($kind);
                if ($enum === null || ! CoolifyGitSource::query()
                    ->where('coolify_connection_id', $connection->id)
                    ->where('kind', $enum)
                    ->where('uuid', $uuid)
                    ->where('is_active', true)
                    ->exists()) {
                    $validator->errors()->add('coolify_git_source', 'Git kaynağı, bu bağlantının aktif listesinde olmalı.');
                }
            }
        });
    }

    /**
     * @param  class-string  $model
     */
    private function activeExists(string $model, CoolifyConnection $connection, string $uuid): bool
    {
        return $model::query()
            ->where('coolify_connection_id', $connection->id)
            ->where('uuid', $uuid)
            ->where('is_active', true)
            ->exists();
    }
}
