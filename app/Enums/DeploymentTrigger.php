<?php

namespace App\Enums;

enum DeploymentTrigger: string
{
    case Create = 'create';
    case ChannelSwitch = 'channel_switch';
    case Manual = 'manual';
    case ThemeRollout = 'theme_rollout';
    case CiRollout = 'ci_rollout';

    public function label(): string
    {
        return __('ops.deploy_trigger.'.$this->value);
    }

    /**
     * Deploys of a site that is already running: a failed or cancelled build
     * is recorded on the deployment row only and never flips the site to Error.
     */
    public function keepsSiteStatus(): bool
    {
        return in_array($this, self::keepingSiteStatus(), true);
    }

    /**
     * @return list<self>
     */
    public static function keepingSiteStatus(): array
    {
        return [self::Manual, self::ThemeRollout, self::CiRollout];
    }
}
