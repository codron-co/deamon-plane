<?php

use App\Http\Controllers\Ops\CloudflareSettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/cloudflare', [CloudflareSettingsController::class, 'show'])->name('ops.cloudflare.show');
Route::post('/cloudflare', [CloudflareSettingsController::class, 'update'])->name('ops.cloudflare.update');
Route::post('/cloudflare/test', [CloudflareSettingsController::class, 'test'])->name('ops.cloudflare.test');
