<?php

use App\Http\Controllers\Ops\FleetController;
use App\Http\Controllers\Ops\GithubSettingsController;
use App\Http\Controllers\Ops\SettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/', [FleetController::class, 'index'])->name('ops.fleet');
    require __DIR__.'/ops/sites.php';
    require __DIR__.'/ops/themes.php';
    Route::get('/settings', [SettingsController::class, 'index'])->name('ops.settings');
    Route::post('/settings', [SettingsController::class, 'update'])->name('ops.settings.update');
    Route::post('/settings/coolify/test', [SettingsController::class, 'testConnection'])->name('ops.settings.coolify.test');
    Route::post('/settings/github', [GithubSettingsController::class, 'update'])->name('ops.settings.github.update');
    Route::post('/settings/github/test', [GithubSettingsController::class, 'testConnection'])->name('ops.settings.github.test');
});
