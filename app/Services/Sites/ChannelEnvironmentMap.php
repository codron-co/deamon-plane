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
     * Coolify project environment name — 1:1 with the git channel.
     */
    public static function coolifyName(Channel $channel): string
    {
        return $channel->value;
    }

    /**
     * Map leftover Coolify names onto the 1:1 channel name.
     */
    public static function canonicalizeEnvironmentName(?string $name): string
    {
        $normalized = strtolower(trim((string) $name));

        return match ($normalized) {
            '', 'production', 'prod' => Channel::Main->value,
            default => trim((string) $name),
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
            Channel::Main => [self::coolifyName(Channel::Main), 'production', 'prod'],
            Channel::Beta => [self::coolifyName(Channel::Beta), 'staging', 'stage'],
            Channel::Alpha => [self::coolifyName(Channel::Alpha)],
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
