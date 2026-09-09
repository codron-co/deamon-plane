<?php

use App\Http\Controllers\Ops\FleetController;
use App\Http\Controllers\Ops\SettingsController;
use App\Http\Controllers\Ops\ThemeController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/', [FleetController::class, 'index'])->name('ops.fleet');
    require __DIR__.'/ops/sites.php';
    Route::get('/themes', [ThemeController::class, 'index'])->name('ops.themes');
    Route::get('/settings', [SettingsController::class, 'index'])->name('ops.settings');
    Route::post('/settings', [SettingsController::class, 'update'])->name('ops.settings.update');
    Route::post('/settings/coolify/test', [SettingsController::class, 'testConnection'])->name('ops.settings.coolify.test');
});
