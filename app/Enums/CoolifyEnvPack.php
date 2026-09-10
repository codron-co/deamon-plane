<?php

namespace App\Enums;

enum CoolifyEnvPack: string
{
    case Dockerfile = 'dockerfile';
    case DockerCompose = 'dockercompose';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $pack) => $pack->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Dockerfile => __('settings.env.packs.dockerfile'),
            self::DockerCompose => __('settings.env.packs.dockercompose'),
        };
    }
}
