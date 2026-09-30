<?php

namespace App\Enums;

/**
 * What the CI gate does, fleet-wide (Settings → CI gate). Only CI-gated targets
 * are affected: sites on `deploy_gate = ci` and themes with `ci_gate` on.
 *
 * `enforce`: deploy only after GitHub's `CI` is green for the branch head (default).
 * `bypass`: CI is off; a push rolls out straight away (same canary + fan-out).
 * `pause`: CI is off; nothing updates on its own, manual deploys still work.
 */
enum CiGateMode: string
{
    case Enforce = 'enforce';
    case Bypass = 'bypass';
    case Pause = 'pause';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $mode) => $mode->value, self::cases());
    }

    public function label(): string
    {
        return __('settings.ci_gate.modes.'.$this->value.'.name');
    }
}
