<?php

use App\Http\Controllers\Internal\WatchListController;
use App\Http\Controllers\Mcp\McpController;
use App\Http\Middleware\AuthenticateMcpToken;
use App\Http\Middleware\ConvertOpsAjaxRedirect;
use App\Http\Middleware\RestrictOpsByIp;
use App\Http\Middleware\SetOpsLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::prefix('webhooks')
                ->name('webhooks.')
                ->group(base_path('routes/webhooks.php'));

            Route::prefix('internal/site/v1/mail')
                ->group(base_path('routes/internal-site.php'));

            Route::get('internal/ops/v1/watch-list', WatchListController::class)
                ->middleware('throttle:30,1')
                ->name('internal.watch-list');

            // MCP for AI agents: bearer token, no session/CSRF (docs/modules/mcp.md).
            Route::match(['GET', 'POST', 'DELETE'], 'mcp', McpController::class)
                ->middleware(['throttle:mcp', AuthenticateMcpToken::class])
                ->name('mcp');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '*'),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        $middleware->web(append: [
            RestrictOpsByIp::class,
            SetOpsLocale::class,
            ConvertOpsAjaxRedirect::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('ops.fleet'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
