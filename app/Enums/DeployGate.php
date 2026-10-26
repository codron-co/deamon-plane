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

    /**
     * A site that runs built images never auto-deploys: its `coolify` gate is a
     * manual release (release.sh or a deploy from Plane).
     */
    public function labelFor(bool $runsBuiltImages): string
    {
        return $runsBuiltImages && $this === self::Coolify
            ? __('rollouts.gate.manual')
            : $this->label();
    }
}
