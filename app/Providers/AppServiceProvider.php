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
use App\Services\Coolify\CoolifyDeployGate;
use App\Services\Coolify\CoolifyRateGuard;
use App\Support\OpsAppearance;
use App\Support\ProductionDebugGuard;
use Illuminate\Support\Facades\Gate;
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
    }

    public function boot(): void
    {
        Gate::define('ops.write', static fn (User $user): bool => $user->canWriteOps());
        Gate::define('ops.danger', static fn (User $user): bool => $user->canRunDangerousOps());
        Gate::policy(Site::class, SitePolicy::class);
        Gate::policy(Theme::class, ThemePolicy::class);
        Gate::policy(ThemeGitConnection::class, ThemeGitConnectionPolicy::class);
        Gate::policy(CoolifyConnection::class, CoolifyConnectionPolicy::class);

        ProductionDebugGuard::assert();

        View::composer(['layouts.ops', 'layouts.guest'], function ($view): void {
            $view->with('opsAppearance', OpsAppearance::fromRequest(request()));
        });
    }
}
