<?php

use App\Http\Controllers\Ops\SiteController;
use Illuminate\Support\Facades\Route;

Route::get('/sites', [SiteController::class, 'index'])->name('ops.sites');
Route::get('/sites/create', [SiteController::class, 'create'])->name('ops.sites.create');
Route::post('/sites', [SiteController::class, 'store'])->name('ops.sites.store');
Route::get('/sites/{site}', [SiteController::class, 'edit'])->name('ops.sites.edit');
Route::put('/sites/{site}', [SiteController::class, 'update'])->name('ops.sites.update');
Route::delete('/sites/{site}', [SiteController::class, 'destroy'])->name('ops.sites.destroy');
