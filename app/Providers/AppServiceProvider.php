<?php

namespace App\Providers;

use App\Models\CoolifyConnection;
use App\Models\Site;
use App\Models\Theme;
use App\Models\User;
use App\Policies\CoolifyConnectionPolicy;
use App\Policies\SitePolicy;
use App\Policies\ThemePolicy;
use App\Support\OpsAppearance;
use App\Support\ProductionDebugGuard;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::define('ops.write', static fn (User $user): bool => $user->canWriteOps());
        Gate::policy(Site::class, SitePolicy::class);
        Gate::policy(Theme::class, ThemePolicy::class);
        Gate::policy(CoolifyConnection::class, CoolifyConnectionPolicy::class);

        ProductionDebugGuard::assert();

        View::composer(['layouts.ops', 'layouts.guest'], function ($view): void {
            $view->with('opsAppearance', OpsAppearance::fromRequest(request()));
        });
    }
}
