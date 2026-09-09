<?php

namespace App\Enums;

enum DeploymentTrigger: string
{
    case Create = 'create';
    case ChannelSwitch = 'channel_switch';
    case Manual = 'manual';
    case ThemeRollout = 'theme_rollout';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $trigger) => $trigger->value, self::cases());
    }
}
