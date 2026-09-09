<?php

use App\Http\Controllers\Ops\ThemeController;
use Illuminate\Support\Facades\Route;

Route::get('/themes', [ThemeController::class, 'index'])->name('ops.themes');
Route::post('/themes/sync', [ThemeController::class, 'sync'])->name('ops.themes.sync');
Route::get('/themes/{theme}', [ThemeController::class, 'show'])->name('ops.themes.show');
Route::put('/themes/{theme}', [ThemeController::class, 'update'])->name('ops.themes.update');
Route::post('/themes/{theme}/access', [ThemeController::class, 'grantAccess'])->name('ops.themes.access.store');
Route::delete('/themes/{theme}/access/{site}', [ThemeController::class, 'revokeAccess'])->name('ops.themes.access.destroy');
