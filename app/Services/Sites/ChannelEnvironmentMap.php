<?php

namespace App\Services\Sites;

use App\Enums\Channel;
use App\Models\CoolifyEnvironment;
use App\Models\Site;

class ChannelEnvironmentMap
{
    public static function appEnv(Channel $channel): string
    {
        return match ($channel) {
            Channel::Main => 'production',
            Channel::Beta => 'staging',
            Channel::Alpha => 'local',
        };
    }

    /**
     * Coolify environment names to try, preferred first.
     *
     * @return list<string>
     */
    public static function environmentNames(Channel $channel): array
    {
        return match ($channel) {
            Channel::Main => ['main', 'production', 'prod'],
            Channel::Beta => ['beta', 'staging', 'stage'],
            Channel::Alpha => ['alpha'],
        };
    }

    public static function environmentUuid(Site $site, Channel $channel): ?string
    {
        $site->loadMissing('coolifyConnection');
        $connection = $site->coolifyConnection;
        if ($connection === null) {
            return null;
        }

        $project = trim((string) ($site->coolify_project_uuid ?: $connection->default_project_uuid));
        if ($project === '') {
            return null;
        }

        $envs = CoolifyEnvironment::query()
            ->where('coolify_connection_id', $connection->id)
            ->where('project_uuid', $project)
            ->where('is_active', true)
            ->get();

        foreach (self::environmentNames($channel) as $name) {
            $match = $envs->first(
                static fn (CoolifyEnvironment $environment): bool => strtolower(trim((string) $environment->name)) === $name,
            );
            if ($match instanceof CoolifyEnvironment && filled($match->uuid)) {
                return (string) $match->uuid;
            }
        }

        return null;
    }
}
