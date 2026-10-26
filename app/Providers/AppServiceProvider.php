<?php

namespace App\Providers;

use App\Models\CoolifyConnection;
use App\Models\Site;
use App\Models\Theme;
use App\Models\ThemeGitConnection;
use App\Models\User;
use App\Policies\CoolifyConnectionPolicy;
use App\Policies\SitePolicy;
use App\Policies\ThemeGitConnectionPolicy;
use App\Policies\ThemePolicy;
use App\Services\Comail\DnsResolver;
use App\Services\Comail\NativeDnsResolver;
use App\Services\Coolify\CoolifyDeployGate;
use App\Services\Coolify\CoolifyRateGuard;
use App\Support\OpsAppearance;
use App\Support\ProductionDebugGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One guard per process so a bulk sweep shares the Coolify cooldown.
        $this->app->singleton(CoolifyRateGuard::class);
        // Same reason: the gate has to recognise the sweep that opened it, and
        // the deploy paths resolve it from the container one site at a time.
        $this->app->singleton(CoolifyDeployGate::class);
        // CoMail DNS check; tests bind a fake resolver.
        $this->app->bind(DnsResolver::class, NativeDnsResolver::class);
    }

    public function boot(): void
    {
        Gate::define('ops.write', static fn (User $user): bool => $user->canWriteOps());
        Gate::define('ops.danger', static fn (User $user): bool => $user->canRunDangerousOps());
        Gate::policy(Site::class, SitePolicy::class);
        Gate::policy(Theme::class, ThemePolicy::class);
        Gate::policy(ThemeGitConnection::class, ThemeGitConnectionPolicy::class);
        Gate::policy(CoolifyConnection::class, CoolifyConnectionPolicy::class);

        // Keyed by the bearer token (hashed), so one runaway agent cannot starve another.
        RateLimiter::for('mcp', static fn (Request $request): Limit => Limit::perMinute((int) config('ops.mcp.rate_limit_per_minute', 240))
            ->by('mcp:'.hash('sha256', (string) ($request->bearerToken() ?? $request->ip()))));

        // CoMail site API: 60/min per site. Every CMS calls from the same Coolify host IP,
        // so an IP key would let one busy admin panel starve the rest.
        RateLimiter::for('site-comail', static fn (Request $request): Limit => Limit::perMinute(60)
            ->by('site-comail:'.substr((string) $request->header('X-Deamon-Site', $request->ip()), 0, 64)));

        // CoMail webmail SSO redeem (called by the webmail server).
        RateLimiter::for('comail-sso', static fn (Request $request): Limit => Limit::perMinute(30)
            ->by('comail-sso:'.$request->ip()));

        // Ops "Webmail'e gir": 10/min per signed-in user.
        RateLimiter::for('comail-webmail-ops', static fn (Request $request): Limit => Limit::perMinute(10)
            ->by('comail-webmail-ops:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        ProductionDebugGuard::assert();

        View::composer(['layouts.ops', 'layouts.guest'], function ($view): void {
            $view->with('opsAppearance', OpsAppearance::fromRequest(request()));
        });
    }
}
