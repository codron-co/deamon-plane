<?php

use App\Http\Controllers\Ops\SecurityController;
use App\Http\Controllers\Ops\SecurityResponseController;
use Illuminate\Support\Facades\Route;

// Güvenlik: fleet security overview, cross-site correlation, event stream, blocks.
Route::get('/security', [SecurityController::class, 'index'])->name('ops.security');
Route::get('/security/correlation', [SecurityController::class, 'correlation'])->name('ops.security.correlation');
Route::get('/security/events', [SecurityController::class, 'events'])->name('ops.security.events');
Route::get('/security/sites/{site}', [SecurityController::class, 'site'])->name('ops.security.site');
Route::get('/security/blocks', [SecurityController::class, 'blocks'])->name('ops.security.blocks');
Route::post('/security/blocks', [SecurityResponseController::class, 'block'])->name('ops.security.block');
Route::post('/security/blocks/release', [SecurityResponseController::class, 'release'])->name('ops.security.release');
Route::post('/security/allowlist', [SecurityResponseController::class, 'allow'])->name('ops.security.allow');
Route::post('/security/allowlist/remove', [SecurityResponseController::class, 'removeAllow'])->name('ops.security.allow.remove');
