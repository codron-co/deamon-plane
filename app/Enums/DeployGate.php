<?php

namespace App\Enums;

/**
 * Who starts a CMS deploy after a push to the site's channel branch.
 *
 * `coolify`: Coolify auto-deploys every push (the old behaviour, CI not consulted).
 * `ci`: Coolify auto-deploy is off; Plane deploys once GitHub's `CI` workflow is
 * green for the branch head (fleet rollout, canary first).
 */
enum DeployGate: string
{
    case Coolify = 'coolify';
    case Ci = 'ci';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $gate) => $gate->value, self::cases());
    }

    public function label(): string
    {
        return __('rollouts.gate.'.$this->value);
    }
}
