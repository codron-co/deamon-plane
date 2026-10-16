<?php

use App\Http\Controllers\Ops\InstagramSettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/instagram', [InstagramSettingsController::class, 'edit'])->name('ops.instagram.edit');
Route::put('/instagram', [InstagramSettingsController::class, 'update'])->name('ops.instagram.update');
Route::post('/instagram/push', [InstagramSettingsController::class, 'push'])->name('ops.instagram.push');
