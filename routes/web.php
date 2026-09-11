<?php

use App\Http\Controllers\Ops\AccountController;
use App\Http\Controllers\Ops\FleetController;
use App\Http\Controllers\Ops\GithubSettingsController;
use App\Http\Controllers\Ops\OpsJobController;
use App\Http\Controllers\Ops\PreferencesController;
use App\Http\Controllers\Ops\SettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/', [FleetController::class, 'index'])->name('ops.fleet');
    Route::get('/jobs', [OpsJobController::class, 'index'])->name('ops.jobs');
    Route::get('/jobs/{job}', [OpsJobController::class, 'show'])->name('ops.jobs.show');
    Route::delete('/jobs/{job}', [OpsJobController::class, 'destroy'])->name('ops.jobs.destroy');
    Route::post('/jobs/deployments/{deployment}/cancel', [OpsJobController::class, 'cancelDeployment'])
        ->name('ops.jobs.deployments.cancel');
    Route::post('/jobs/deployments/{deployment}/force-start', [OpsJobController::class, 'forceStartDeployment'])
        ->name('ops.jobs.deployments.force-start');
    Route::post('/jobs/coolify-deployments/{uuid}/cancel', [OpsJobController::class, 'cancelCoolifyDeployment'])
        ->where('uuid', '[A-Za-z0-9_-]+')
        ->name('ops.jobs.coolify.cancel');
    Route::post('/jobs/coolify-deployments/{uuid}/force-start', [OpsJobController::class, 'forceStartCoolifyDeployment'])
        ->where('uuid', '[A-Za-z0-9_-]+')
        ->name('ops.jobs.coolify.force-start');
    require __DIR__.'/ops/sites.php';
    require __DIR__.'/ops/domains.php';
    require __DIR__.'/ops/coolify.php';
    require __DIR__.'/ops/cloudflare.php';
    require __DIR__.'/ops/mail-servers.php';
    require __DIR__.'/ops/themes.php';

    Route::get('/account', [AccountController::class, 'show'])->name('ops.account.show');
    Route::put('/account', [AccountController::class, 'update'])->name('ops.account.update');
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->name('ops.account.password');
    Route::post('/account/avatar', [AccountController::class, 'updateAvatar'])->name('ops.account.avatar');
    Route::delete('/account/avatar', [AccountController::class, 'destroyAvatar'])->name('ops.account.avatar.destroy');
    Route::get('/account/preferences', [PreferencesController::class, 'show'])->name('ops.account.preferences');
    Route::put('/account/preferences', [PreferencesController::class, 'update'])->name('ops.account.preferences.update');
    Route::post('/account/locale', [PreferencesController::class, 'updateLocale'])->name('ops.account.locale');
    Route::get('/account/appearance', fn () => redirect()->route('ops.account.preferences'));
    Route::post('/account/appearance', [PreferencesController::class, 'updateAppearance'])->name('ops.account.appearance');

    Route::get('/settings', [SettingsController::class, 'index'])->name('ops.settings');
    Route::post('/settings', [SettingsController::class, 'update'])->name('ops.settings.update');
    Route::post('/settings/env-defaults', [SettingsController::class, 'updateEnvDefaults'])->name('ops.settings.env.update');
    Route::post('/settings/coolify/test', [SettingsController::class, 'testConnection'])->name('ops.settings.coolify.test');
    Route::post('/settings/github', [GithubSettingsController::class, 'update'])->name('ops.settings.github.update');
    Route::post('/settings/github/test', [GithubSettingsController::class, 'testConnection'])->name('ops.settings.github.test');
});
