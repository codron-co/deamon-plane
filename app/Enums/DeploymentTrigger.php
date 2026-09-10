<?php

namespace App\Enums;

enum DeploymentTrigger: string
{
    case Create = 'create';
    case ChannelSwitch = 'channel_switch';
    case Manual = 'manual';
    case ThemeRollout = 'theme_rollout';

    public function label(): string
    {
        return __('ops.deploy_trigger.'.$this->value);
    }
}
