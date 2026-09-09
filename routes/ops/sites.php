<?php

use App\Http\Controllers\Ops\SiteController;
use App\Http\Controllers\Ops\SiteThemeController;
use Illuminate\Support\Facades\Route;

Route::get('/sites', [SiteController::class, 'index'])->name('ops.sites');
Route::get('/sites/create', [SiteController::class, 'create'])->name('ops.sites.create');
Route::post('/sites', [SiteController::class, 'store'])->name('ops.sites.store');
Route::get('/sites/{site}', [SiteController::class, 'edit'])->name('ops.sites.edit');
Route::put('/sites/{site}', [SiteController::class, 'update'])->name('ops.sites.update');
Route::post('/sites/{site}/provision', [SiteController::class, 'provision'])->name('ops.sites.provision');
Route::post('/sites/{site}/channel', [SiteController::class, 'switchChannel'])->name('ops.sites.channel');
Route::post('/sites/{site}/health', [SiteController::class, 'checkHealth'])->name('ops.sites.health');
Route::post('/sites/{site}/themes', [SiteThemeController::class, 'assign'])->name('ops.sites.themes.assign');
Route::post('/sites/{site}/themes/{installation}/update', [SiteThemeController::class, 'update'])->name('ops.sites.themes.update');
Route::post('/sites/{site}/themes/{installation}/sync', [SiteThemeController::class, 'sync'])->name('ops.sites.themes.sync');
Route::post('/sites/{site}/themes/{installation}/activate', [SiteThemeController::class, 'activate'])->name('ops.sites.themes.activate');
Route::post('/sites/{site}/themes/{installation}/auto-update', [SiteThemeController::class, 'autoUpdate'])->name('ops.sites.themes.auto-update');
Route::delete('/sites/{site}', [SiteController::class, 'destroy'])->name('ops.sites.destroy');
