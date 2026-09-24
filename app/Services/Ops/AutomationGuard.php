<?php

namespace App\Services\Ops;

use App\Models\AutomationSetting;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * One gate for every action Plane takes without a human (audit P13).
 *
 * A rule runs only when its env flag (config/ops.php) and its runtime switch
 * (Settings -> Automation, Super Admin) are both on, its per-site and fleet
 * budgets have room, and it is not paused. When the same signal shows up on
 * several sites in a short window it is a fleet incident, not a site fault:
 * the rule pauses for the whole fleet instead of "fixing" every site at once.
 */
final class AutomationGuard
{
    public const DEPLOY_AUTO_FIX = 'deploy_auto_fix';

    public const CORE_THEME_RESTART = 'core_theme_restart';

    public const DOMAIN_AUTO_REBIND = 'domain_auto_rebind';

    /** Distinct sites showing the same signal within the window = fleet incident. */
    public const INCIDENT_SITES = 3;

    public const INCIDENT_WINDOW_MINUTES = 15;

    public const INCIDENT_PAUSE_MINUTES = 60;

    /**
     * @var array<string, array{floor: string, per_site_per_day: int, fleet_per_hour: int}>
     */
    public const RULES = [
        self::DEPLOY_AUTO_FIX => ['floor' => 'ops.diagnosis.auto_fix', 'per_site_per_day' => 3, 'fleet_per_hour' => 20],
        self::CORE_THEME_RESTART => ['floor' => 'ops.agent.core_theme_auto_restart', 'per_site_per_day' => 4, 'fleet_per_hour' => 20],
        self::DOMAIN_AUTO_REBIND => ['floor' => 'ops.coolify.auto_rebind_domains', 'per_site_per_day' => 6, 'fleet_per_hour' => 60],
    ];

    /**
     * The env flag is the floor: when it is off the runtime switch cannot turn the rule on.
     */
    public function envAllows(string $rule): bool
    {
        return (bool) config(self::RULES[$rule]['floor'], true);
    }

    public function switchedOn(string $rule): bool
    {
        $setting = AutomationSetting::query()->where('rule', $rule)->first();

        return $setting === null || $setting->enabled;
    }

    public function enabled(string $rule): bool
    {
        return $this->envAllows($rule) && $this->switchedOn($rule);
    }

    public function pausedUntil(string $rule): ?int
    {
        $until = Cache::get($this->pauseKey($rule));

        return is_int($until) && $until > now()->getTimestamp() ? $until : null;
    }

    /**
     * Null when the action may run now (and its budget is spent), otherwise the
     * reason it may not: disabled, fleet_paused, fleet_incident, site_budget, fleet_budget.
     */
    public function deny(string $rule, ?Site $site = null, ?string $signal = null): ?string
    {
        if (! $this->enabled($rule)) {
            return 'disabled';
        }

        if ($this->pausedUntil($rule) !== null) {
            return 'fleet_paused';
        }

        if ($site !== null && $signal !== null && $this->isFleetIncident($rule, $site, $signal)) {
            return 'fleet_incident';
        }

        $limits = self::RULES[$rule];
        $siteKey = $site !== null ? 'automation:'.$rule.':site:'.$site->getKey() : null;
        $fleetKey = 'automation:'.$rule.':fleet';

        if ($siteKey !== null && RateLimiter::tooManyAttempts($siteKey, $limits['per_site_per_day'])) {
            return 'site_budget';
        }

        if (RateLimiter::tooManyAttempts($fleetKey, $limits['fleet_per_hour'])) {
            return 'fleet_budget';
        }

        if ($siteKey !== null) {
            RateLimiter::hit($siteKey, 86400);
        }
        RateLimiter::hit($fleetKey, 3600);

        return null;
    }

    private function isFleetIncident(string $rule, Site $site, string $signal): bool
    {
        $key = 'automation:'.$rule.':signal:'.sha1($signal);
        $window = now()->subMinutes(self::INCIDENT_WINDOW_MINUTES)->getTimestamp();

        /** @var array<string, int> $seen site id => last seen */
        $seen = array_filter(
            (array) Cache::get($key, []),
            static fn (mixed $at): bool => is_int($at) && $at >= $window,
        );
        $seen[(string) $site->getKey()] = now()->getTimestamp();
        Cache::put($key, $seen, now()->addMinutes(self::INCIDENT_WINDOW_MINUTES));

        if (count($seen) < self::INCIDENT_SITES) {
            return false;
        }

        Cache::put(
            $this->pauseKey($rule),
            now()->addMinutes(self::INCIDENT_PAUSE_MINUTES)->getTimestamp(),
            now()->addMinutes(self::INCIDENT_PAUSE_MINUTES),
        );

        Log::warning('automation.fleet_incident', [
            'rule' => $rule,
            'signal' => $signal,
            'sites' => count($seen),
            'paused_minutes' => self::INCIDENT_PAUSE_MINUTES,
        ]);

        return true;
    }

    private function pauseKey(string $rule): string
    {
        return 'automation:'.$rule.':paused_until';
    }
}
