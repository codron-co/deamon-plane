<?php

namespace App\Enums;

/**
 * What a `waiting` deployment row replays once the per-host build cap has room.
 * Every side effect of the action (pin / unpin, auto-deploy toggle, branch
 * PATCH, env sync, preflight) happens at start time, never at request time, so
 * a cancelled waiting row leaves the Coolify app exactly as it was.
 */
enum WaitingDeployAction: string
{
    /** Tekrar deploy. Payload `force` (default true; the domain-bind redeploy uses false). */
    case Redeploy = 'redeploy';

    /** HEAD'e güncelle: unpin, keep auto-deploy as it is. */
    case UpdateHead = 'update_head';

    /** HEAD'de deploy: unpin and turn auto-deploy on (off for a CI-gated site). */
    case FollowHead = 'follow_head';

    /** Commite geç. Payload `ref`. */
    case Pin = 'pin';

    /** Channel switch: the site stays `deploying` with `desired_channel` while waiting. */
    case ChannelSwitch = 'channel_switch';

    /** First provision build: the site stays `provisioning` while waiting. */
    case Provision = 'provision';

    public function trigger(): DeploymentTrigger
    {
        return match ($this) {
            self::ChannelSwitch => DeploymentTrigger::ChannelSwitch,
            self::Provision => DeploymentTrigger::Create,
            default => DeploymentTrigger::Manual,
        };
    }

    /**
     * Actions that only rebuild the app on its current settings or move it to a
     * target; a site-lifecycle action (channel switch, provision) owns the site
     * status and fails through its own service.
     */
    public function isAppAction(): bool
    {
        return in_array($this, [self::Redeploy, self::UpdateHead, self::FollowHead, self::Pin], true);
    }

    public function label(): string
    {
        return __('site_ops.queue.actions.'.$this->value);
    }
}
