<?php

namespace App\Enums;

enum SiteStatus: string
{
    case Draft = 'draft';
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Deploying = 'deploying';
    case Error = 'error';
    case Archived = 'archived';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status) => $status->value, self::cases());
    }

    /**
     * Plan §4.2 — draft → provisioning → active ⇄ deploying → active;
     * draft → active when attaching an existing Coolify app (no second create);
     * provisioning/deploying ↘ error; retry → deploying or provisioning; active → archived.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Provisioning, self::Active],
            self::Provisioning => [self::Active, self::Error],
            self::Active => [self::Deploying, self::Archived],
            self::Deploying => [self::Active, self::Error],
            self::Error => [self::Deploying, self::Provisioning],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
