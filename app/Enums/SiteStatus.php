<?php

namespace App\Enums;

enum SiteStatus: string
{
    case Draft = 'draft';
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Deploying = 'deploying';
    case Stopped = 'stopped';
    case Error = 'error';
    case Archived = 'archived';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status) => $status->value, self::cases());
    }

    public function label(): string
    {
        return __('ops.site_status.'.$this->value);
    }

    /**
     * Plan §4.2 — draft → provisioning → active ⇄ deploying → active;
     * draft → active when attaching an existing Coolify app (no second create);
     * active ⇄ stopped (Coolify start/stop); provisioning/deploying ↘ error;
     * retry → deploying or provisioning; error → active when latest Coolify deploy finished;
     * active/stopped → archived.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Provisioning, self::Active],
            self::Provisioning => [self::Active, self::Error],
            self::Active => [self::Deploying, self::Stopped, self::Archived],
            self::Deploying => [self::Active, self::Error],
            self::Stopped => [self::Active, self::Archived],
            self::Error => [self::Deploying, self::Provisioning, self::Active],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
